<?php
declare(strict_types=1);

final class VoteService
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function listOpenEvents(bool $includePaid = false): array
    {
        $votingModes = $includePaid
            ? "'free_multiple', 'paid_multiple', 'restricted_single'"
            : "'free_multiple', 'restricted_single'";
        $statement = $this->database->query(
            "SELECT id, name, voting_mode, result_visibility, max_votes_per_voter,
                    price_per_vote, currency
             FROM events
             WHERE event_status = 'published'
               AND voting_mode IN ({$votingModes})
               AND starts_at <= UTC_TIMESTAMP()
               AND ends_at > UTC_TIMESTAMP()
               AND EXISTS (
                 SELECT 1
                 FROM categories AS category
                 JOIN contestant_categories AS assignment
                   ON assignment.event_id = category.event_id
                  AND assignment.category_id = category.id
                 JOIN contestants AS contestant
                   ON contestant.id = assignment.contestant_id
                  AND contestant.event_id = assignment.event_id
                 WHERE category.event_id = events.id
                   AND category.category_status = 'active'
                   AND contestant.contestant_status = 'active'
               )
             ORDER BY starts_at, name"
        );

        return $statement->fetchAll();
    }

    public function hasOpenEvents(bool $includePaid = false): bool
    {
        $votingModes = $includePaid
            ? "'free_multiple', 'paid_multiple', 'restricted_single'"
            : "'free_multiple', 'restricted_single'";
        $statement = $this->database->query(
            "SELECT EXISTS (
               SELECT 1
               FROM events
               WHERE event_status = 'published'
                 AND voting_mode IN ({$votingModes})
                 AND starts_at <= UTC_TIMESTAMP()
                 AND ends_at > UTC_TIMESTAMP()
             )"
        );

        return (bool) $statement->fetchColumn();
    }

    public function listBallotOptions(bool $includePaid = false): array
    {
        $votingModes = $includePaid
            ? "'free_multiple', 'paid_multiple', 'restricted_single'"
            : "'free_multiple', 'restricted_single'";
        $statement = $this->database->query(
            "SELECT event.id AS event_id, event.name AS event_name, event.voting_mode,
                    event.max_votes_per_voter, category.id AS category_id,
                    category.name AS category_name, contestant.id AS contestant_id,
                    contestant.display_name
             FROM events AS event
             JOIN categories AS category
               ON category.event_id = event.id AND category.category_status = 'active'
             JOIN contestant_categories AS assignment
               ON assignment.event_id = event.id AND assignment.category_id = category.id
             JOIN contestants AS contestant
               ON contestant.id = assignment.contestant_id
              AND contestant.event_id = assignment.event_id
              AND contestant.contestant_status = 'active'
             WHERE event.event_status = 'published'
               AND event.voting_mode IN ({$votingModes})
               AND event.starts_at <= UTC_TIMESTAMP()
               AND event.ends_at > UTC_TIMESTAMP()
             ORDER BY event.starts_at, event.name, category.name, contestant.display_name"
        );

        return $statement->fetchAll();
    }

    public function cast(
        int $eventId,
        int $categoryId,
        int $contestantId,
        int $userId,
        int $quantity,
        string $idempotencyKey
    ): array {
        if ($eventId < 1 || $categoryId < 1 || $contestantId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Choose a valid event, category and contestant.');
        }
        if ($quantity < 1 || $quantity > 10000) {
            throw new InvalidArgumentException('Choose a vote quantity between 1 and 10,000.');
        }
        if (!preg_match('/^[a-f0-9]{32,100}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('The vote request could not be verified. Reload the page and try again.');
        }

        try {
            $this->database->beginTransaction();

            $eventQuery = $this->database->prepare(
                'SELECT id, event_status, voting_mode, max_votes_per_voter,
                        starts_at <= UTC_TIMESTAMP() AS has_started,
                        ends_at > UTC_TIMESTAMP() AS has_not_ended
                 FROM events
                 WHERE id = :event_id
                 FOR UPDATE'
            );
            $eventQuery->execute(['event_id' => $eventId]);
            $event = $eventQuery->fetch();
            if (!is_array($event)) {
                throw new DomainException('The event is not available for voting.');
            }
            if ($event['event_status'] !== 'published'
                || (int) $event['has_started'] !== 1
                || (int) $event['has_not_ended'] !== 1) {
                throw new DomainException('Voting is not open for this event.');
            }
            if ($event['voting_mode'] === 'paid_multiple') {
                throw new DomainException('Paid voting is unavailable until secure payment verification is enabled.');
            }
            if (!in_array($event['voting_mode'], ['free_multiple', 'restricted_single'], true)) {
                throw new DomainException('This event does not accept online votes.');
            }
            if ($event['voting_mode'] === 'restricted_single' && $quantity !== 1) {
                throw new InvalidArgumentException('Restricted elections allow one vote per category.');
            }

            $userQuery = $this->database->prepare(
                "SELECT account_status FROM users WHERE id = :user_id FOR UPDATE"
            );
            $userQuery->execute(['user_id' => $userId]);
            if ($userQuery->fetchColumn() !== 'active') {
                throw new DomainException('An active voter account is required to vote.');
            }
            $roleQuery = $this->database->prepare(
                "SELECT 1
                 FROM user_roles AS user_role
                 JOIN roles AS role ON role.id = user_role.role_id
                 WHERE user_role.user_id = :user_id AND role.name = 'voter'
                 LIMIT 1"
            );
            $roleQuery->execute(['user_id' => $userId]);
            if ($roleQuery->fetchColumn() === false) {
                throw new DomainException('A voter account is required to vote.');
            }

            $existingVote = $this->database->prepare(
                'SELECT id, event_id, category_id, contestant_id, voter_user_id, vote_quantity
                 FROM votes WHERE idempotency_key = :idempotency_key LIMIT 1'
            );
            $existingVote->execute(['idempotency_key' => $idempotencyKey]);
            $existing = $existingVote->fetch();
            if (is_array($existing)) {
                if ((int) $existing['event_id'] !== $eventId
                    || (int) $existing['category_id'] !== $categoryId
                    || (int) $existing['contestant_id'] !== $contestantId
                    || (int) $existing['voter_user_id'] !== $userId
                    || (int) $existing['vote_quantity'] !== $quantity) {
                    throw new DomainException('This vote request was already used for a different selection.');
                }

                $this->database->commit();
                return ['id' => (int) $existing['id'], 'already_recorded' => true];
            }

            $targetQuery = $this->database->prepare(
                "SELECT 1
                 FROM categories AS category
                 JOIN contestant_categories AS assignment
                   ON assignment.category_id = category.id AND assignment.event_id = category.event_id
                 JOIN contestants AS contestant
                   ON contestant.id = assignment.contestant_id
                  AND contestant.event_id = assignment.event_id
                 WHERE category.id = :category_id
                   AND category.event_id = :event_id
                   AND category.category_status = 'active'
                   AND contestant.id = :contestant_id
                   AND contestant.contestant_status = 'active'
                 LIMIT 1"
            );
            $targetQuery->execute([
                'category_id' => $categoryId,
                'event_id' => $eventId,
                'contestant_id' => $contestantId,
            ]);
            if ($targetQuery->fetchColumn() === false) {
                throw new DomainException('That contestant is not available in the selected event category.');
            }

            $eligibility = $this->database->prepare(
                "INSERT INTO voter_eligibility (
                    event_id, user_id, eligibility_status, verified_at
                 ) VALUES (:event_id, :user_id, 'eligible', UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    eligibility_status = IF(eligibility_status = 'revoked', 'revoked', 'eligible'),
                    verified_by = IF(eligibility_status = 'revoked', verified_by, NULL),
                    verified_at = IF(eligibility_status = 'revoked', verified_at, UTC_TIMESTAMP())"
            );
            $eligibility->execute(['event_id' => $eventId, 'user_id' => $userId]);

            $eligibilityStatus = $this->database->prepare(
                'SELECT eligibility_status
                 FROM voter_eligibility
                 WHERE event_id = :event_id AND user_id = :user_id
                 FOR UPDATE'
            );
            $eligibilityStatus->execute(['event_id' => $eventId, 'user_id' => $userId]);
            if ($eligibilityStatus->fetchColumn() !== 'eligible') {
                throw new DomainException('Your eligibility to vote in this event has been revoked.');
            }

            $ballotId = null;
            if ($event['voting_mode'] === 'restricted_single') {
                $ballot = $this->database->prepare(
                    "INSERT INTO election_ballots (event_id, category_id, voter_user_id, ballot_status)
                     VALUES (:event_id, :category_id, :user_id, 'cast')"
                );
                $ballot->execute([
                    'event_id' => $eventId,
                    'category_id' => $categoryId,
                    'user_id' => $userId,
                ]);
                $ballotId = (int) $this->database->lastInsertId();
            } elseif ($event['max_votes_per_voter'] !== null) {
                $countQuery = $this->database->prepare(
                    "SELECT COALESCE(SUM(vote_quantity), 0)
                     FROM votes
                     WHERE event_id = :event_id
                       AND voter_user_id = :user_id
                       AND vote_status = 'credited'"
                );
                $countQuery->execute(['event_id' => $eventId, 'user_id' => $userId]);
                $votesAlreadyCast = (int) $countQuery->fetchColumn();
                if ($votesAlreadyCast + $quantity > (int) $event['max_votes_per_voter']) {
                    throw new DomainException('This vote would exceed the event limit for your account.');
                }
            }

            $insertVote = $this->database->prepare(
                "INSERT INTO votes (
                    event_id, category_id, contestant_id, voter_user_id,
                    ballot_id, idempotency_key, vote_quantity, vote_status
                 ) VALUES (
                    :event_id, :category_id, :contestant_id, :user_id,
                    :ballot_id, :idempotency_key, :vote_quantity, 'credited'
                 )"
            );
            $insertVote->execute([
                'event_id' => $eventId,
                'category_id' => $categoryId,
                'contestant_id' => $contestantId,
                'user_id' => $userId,
                'ballot_id' => $ballotId,
                'idempotency_key' => $idempotencyKey,
                'vote_quantity' => $quantity,
            ]);
            $voteId = (int) $this->database->lastInsertId();
            $this->database->commit();

            return ['id' => $voteId, 'already_recorded' => false];
        } catch (PDOException $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                && isset($event)
                && $event['voting_mode'] === 'restricted_single') {
                throw new DomainException('You have already voted in this category.', 0, $exception);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    public function listPublicResults(): array
    {
        $statement = $this->database->query(
            "SELECT id, name, event_status, ends_at, result_visibility, cover_image_key
             FROM events
             WHERE event_status IN ('published', 'closed', 'finalized')
               AND (
                 result_visibility = 'live'
                 OR (result_visibility = 'after_close'
                     AND (event_status IN ('closed', 'finalized') OR ends_at <= UTC_TIMESTAMP()))
               )
             ORDER BY ends_at DESC, name"
        );

        return $statement->fetchAll();
    }

    public function publicResults(int $eventId): array
    {
        if ($eventId < 1) {
            throw new InvalidArgumentException('Choose an event to view its results.');
        }

        $eventQuery = $this->database->prepare(
            "SELECT id, name, event_status, ends_at, result_visibility, cover_image_key
             FROM events
             WHERE id = :event_id
               AND event_status IN ('published', 'closed', 'finalized')
               AND (
                 result_visibility = 'live'
                 OR (result_visibility = 'after_close'
                     AND (event_status IN ('closed', 'finalized') OR ends_at <= UTC_TIMESTAMP()))
               )
             LIMIT 1"
        );
        $eventQuery->execute(['event_id' => $eventId]);
        $event = $eventQuery->fetch();
        if (!is_array($event)) {
            throw new DomainException('Results for that event are not publicly available.');
        }

        $resultsQuery = $this->database->prepare(
            "SELECT category.id AS category_id, category.name AS category_name,
                    category.image_key AS category_image_key,
                    contestant.id AS contestant_id, contestant.display_name,
                    contestant.profile_image_key,
                    COALESCE(SUM(vote.vote_quantity), 0) AS vote_count
             FROM categories AS category
             JOIN contestant_categories AS assignment
               ON assignment.category_id = category.id AND assignment.event_id = category.event_id
             JOIN contestants AS contestant
               ON contestant.id = assignment.contestant_id
              AND contestant.event_id = assignment.event_id
             LEFT JOIN votes AS vote
               ON vote.event_id = assignment.event_id
              AND vote.category_id = assignment.category_id
              AND vote.contestant_id = assignment.contestant_id
              AND vote.vote_status = 'credited'
             WHERE category.event_id = :event_id
               AND category.category_status = 'active'
               AND contestant.contestant_status = 'active'
             GROUP BY category.id, category.name, category.image_key,
                      contestant.id, contestant.display_name, contestant.profile_image_key
             ORDER BY category.name, vote_count DESC, contestant.display_name"
        );
        $resultsQuery->execute(['event_id' => $eventId]);

        return ['event' => $event, 'results' => $resultsQuery->fetchAll()];
    }
}
