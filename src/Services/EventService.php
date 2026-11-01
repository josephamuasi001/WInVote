<?php
declare(strict_types=1);

final class EventService
{
    public function __construct(
        private readonly PDO $database,
        private readonly AccessControl $access
    ) {
    }

    public function listForOrganizer(int $organizerId): array
    {
        $statement = $this->database->prepare(
            "SELECT event.id, event.name, event.slug, event.event_type, event.event_status,
                    event.voting_mode, event.starts_at, event.ends_at, event.cover_image_key,
                    event.currency, event.price_per_vote, event.created_at,
                    (SELECT COUNT(*) FROM categories AS category
                     WHERE category.event_id = event.id
                       AND category.category_status = 'active') AS category_count,
                    (SELECT COUNT(DISTINCT contestant.id)
                     FROM contestants AS contestant
                     JOIN contestant_categories AS assignment
                       ON assignment.contestant_id = contestant.id
                      AND assignment.event_id = contestant.event_id
                     JOIN categories AS category
                       ON category.id = assignment.category_id
                      AND category.event_id = assignment.event_id
                     WHERE contestant.event_id = event.id
                       AND contestant.contestant_status = 'active'
                       AND category.category_status = 'active') AS nominee_count
             FROM events AS event
             WHERE event.organizer_id = :organizer_id
             ORDER BY event.created_at DESC, event.id DESC"
        );
        $statement->execute(['organizer_id' => $organizerId]);

        return $statement->fetchAll();
    }

    public function listPublicEvents(): array
    {
        $statement = $this->database->query(
            "SELECT event.id, event.name, event.event_type, event.description,
                    event.event_status, event.voting_mode, event.result_visibility,
                    event.starts_at, event.ends_at, event.cover_image_key,
                    event.currency, event.price_per_vote,
                    organizer.organization_name,
                    CASE
                      WHEN event.event_status IN ('closed', 'finalized')
                        OR event.ends_at <= UTC_TIMESTAMP() THEN 'Completed'
                      WHEN event.starts_at > UTC_TIMESTAMP() THEN 'Upcoming'
                      ELSE 'Live'
                    END AS public_status,
                    (SELECT COUNT(DISTINCT contestant.id)
                     FROM contestants AS contestant
                     JOIN contestant_categories AS assignment
                       ON assignment.contestant_id = contestant.id
                      AND assignment.event_id = contestant.event_id
                     JOIN categories AS category
                       ON category.id = assignment.category_id
                      AND category.event_id = assignment.event_id
                     WHERE contestant.event_id = event.id
                       AND contestant.contestant_status = 'active'
                       AND category.category_status = 'active') AS nominee_count,
                    CASE
                      WHEN event.result_visibility = 'live'
                        OR (event.result_visibility = 'after_close'
                            AND (event.event_status IN ('closed', 'finalized')
                                 OR event.ends_at <= UTC_TIMESTAMP()))
                        THEN (SELECT COALESCE(SUM(vote.vote_quantity), 0)
                              FROM votes AS vote
                              WHERE vote.event_id = event.id AND vote.vote_status = 'credited')
                      ELSE NULL
                    END AS public_vote_count,
                    CASE
                      WHEN event.result_visibility = 'live'
                        OR (event.result_visibility = 'after_close'
                            AND (event.event_status IN ('closed', 'finalized')
                                 OR event.ends_at <= UTC_TIMESTAMP()))
                        THEN 1 ELSE 0
                    END AS has_public_results
             FROM events AS event
             JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
             WHERE event.event_status IN ('published', 'closed', 'finalized')
               AND organizer.approval_status = 'approved'
             ORDER BY
               CASE WHEN event.starts_at <= UTC_TIMESTAMP()
                          AND event.ends_at > UTC_TIMESTAMP()
                          AND event.event_status = 'published' THEN 0
                    WHEN event.starts_at > UTC_TIMESTAMP()
                          AND event.event_status = 'published' THEN 1
                    ELSE 2 END,
               event.starts_at ASC, event.name"
        );

        return $statement->fetchAll();
    }

    public function publicEventDetails(int $eventId): ?array
    {
        if ($eventId < 1) {
            return null;
        }

        $statement = $this->database->prepare(
            "SELECT event.id, event.name, event.event_type, event.description,
                    event.event_status, event.voting_mode, event.result_visibility,
                    event.starts_at, event.ends_at, event.nomination_opens_at,
                    event.nomination_closes_at, event.cover_image_key,
                    event.currency, event.price_per_vote,
                    organizer.organization_name,
                    CASE
                      WHEN event.event_status IN ('closed', 'finalized')
                        OR event.ends_at <= UTC_TIMESTAMP() THEN 'Completed'
                      WHEN event.starts_at > UTC_TIMESTAMP() THEN 'Upcoming'
                      ELSE 'Live'
                    END AS public_status,
                    CASE
                      WHEN event.result_visibility = 'live'
                        OR (event.result_visibility = 'after_close'
                            AND (event.event_status IN ('closed', 'finalized')
                                 OR event.ends_at <= UTC_TIMESTAMP()))
                        THEN 1 ELSE 0
                    END AS has_public_results
             FROM events AS event
             JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
             WHERE event.id = :event_id
               AND event.event_status IN ('published', 'closed', 'finalized')
               AND organizer.approval_status = 'approved'
             LIMIT 1"
        );
        $statement->execute(['event_id' => $eventId]);
        $event = $statement->fetch();
        if (!is_array($event)) {
            return null;
        }

        $categoriesQuery = $this->database->prepare(
            "SELECT category.id AS category_id, category.name AS category_name,
                    category.description AS category_description,
                    category.image_key AS category_image_key,
                    contestant.id AS contestant_id, contestant.display_name,
                    contestant.biography, contestant.profile_image_key,
                    (SELECT COALESCE(SUM(vote.vote_quantity), 0)
                     FROM votes AS vote
                     WHERE vote.event_id = event.id
                       AND vote.contestant_id = contestant.id
                       AND vote.vote_status = 'credited') AS vote_count
             FROM events AS event
             JOIN categories AS category
               ON category.event_id = event.id AND category.category_status = 'active'
             LEFT JOIN contestant_categories AS assignment
               ON assignment.event_id = event.id AND assignment.category_id = category.id
             LEFT JOIN contestants AS contestant
               ON contestant.id = assignment.contestant_id
              AND contestant.event_id = event.id
              AND contestant.contestant_status = 'active'
             WHERE event.id = :event_id
             ORDER BY category.name, contestant.display_name"
        );
        $categoriesQuery->execute(['event_id' => $eventId]);
        $categories = [];
        foreach ($categoriesQuery->fetchAll() as $row) {
            $categoryId = (int) $row['category_id'];
            if (!isset($categories[$categoryId])) {
                $categories[$categoryId] = [
                    'id' => $categoryId,
                    'name' => $row['category_name'],
                    'description' => $row['category_description'],
                    'image_key' => $row['category_image_key'],
                    'contestants' => [],
                ];
            }
            if ($row['contestant_id'] !== null) {
                $categories[$categoryId]['contestants'][] = [
                    'id' => (int) $row['contestant_id'],
                    'name' => $row['display_name'],
                    'biography' => $row['biography'],
                    'image_key' => $row['profile_image_key'],
                    'vote_count' => (int) $row['vote_count'],
                ];
            }
        }

        return [
            'event' => $event,
            'categories' => array_values($categories),
        ];
    }

    public function publicContestantDetails(int $contestantId): ?array
    {
        if ($contestantId < 1) {
            return null;
        }

        $statement = $this->database->prepare(
            "SELECT contestant.id, contestant.display_name, contestant.biography,
                    contestant.profile_image_key, event.id AS event_id, event.name AS event_name,
                    event.event_status, event.voting_mode, event.result_visibility,
                    event.starts_at, event.ends_at, event.currency,
                    CASE
                      WHEN event.result_visibility = 'live'
                        OR (event.result_visibility = 'after_close'
                            AND (event.event_status IN ('closed', 'finalized')
                                 OR event.ends_at <= UTC_TIMESTAMP()))
                        THEN (SELECT COALESCE(SUM(vote.vote_quantity), 0)
                              FROM votes AS vote
                              WHERE vote.event_id = event.id
                                AND vote.contestant_id = contestant.id
                                AND vote.vote_status = 'credited')
                      ELSE NULL
                    END AS public_vote_count,
                    CASE
                      WHEN event.event_status IN ('closed', 'finalized')
                        OR event.ends_at <= UTC_TIMESTAMP() THEN 'Completed'
                      WHEN event.starts_at > UTC_TIMESTAMP() THEN 'Upcoming'
                      ELSE 'Live'
                    END AS public_status,
                    CASE
                      WHEN event.result_visibility = 'live'
                        OR (event.result_visibility = 'after_close'
                            AND (event.event_status IN ('closed', 'finalized')
                                 OR event.ends_at <= UTC_TIMESTAMP()))
                        THEN 1 ELSE 0
                    END AS has_public_results,
                    category.id AS category_id, category.name AS category_name,
                    organizer.organization_name
             FROM contestants AS contestant
             JOIN events AS event ON event.id = contestant.event_id
             JOIN organizer_profiles AS organizer ON organizer.id = event.organizer_id
             JOIN contestant_categories AS assignment
               ON assignment.contestant_id = contestant.id
              AND assignment.event_id = contestant.event_id
             JOIN categories AS category
               ON category.id = assignment.category_id
              AND category.event_id = assignment.event_id
             WHERE contestant.id = :contestant_id
               AND contestant.contestant_status = 'active'
               AND category.category_status = 'active'
               AND event.event_status IN ('published', 'closed', 'finalized')
               AND organizer.approval_status = 'approved'
             ORDER BY category.name
             LIMIT 1"
        );
        $statement->execute(['contestant_id' => $contestantId]);
        $contestant = $statement->fetch();

        return is_array($contestant) ? $contestant : null;
    }

    public function findForManagement(int $eventId, int $userId, array $roles): array
    {
        if (!$this->access->canManageEvent($userId, $roles, $eventId)) {
            http_response_code(404);
            throw new RuntimeException('The event was not found or you do not have access to it.');
        }

        $statement = $this->database->prepare(
            'SELECT id, organizer_id, name, slug, event_type, description, event_status,
                    voting_mode, result_visibility, starts_at, ends_at,
                    nomination_opens_at, nomination_closes_at, cover_image_key,
                    max_votes_per_voter, price_per_vote, currency
             FROM events
             WHERE id = :event_id
             LIMIT 1'
        );
        $statement->execute(['event_id' => $eventId]);
        $event = $statement->fetch();
        if (!is_array($event)) {
            http_response_code(404);
            throw new RuntimeException('The event could not be found.');
        }

        return $event;
    }

    public function create(int $userId, int $organizerId, array $input): int
    {
        $values = $this->validateEventInput($input);
        $slug = $this->uniqueSlug($values['name']);
        $statement = $this->database->prepare(
            "INSERT INTO events (
                organizer_id, created_by, name, slug, event_type, description,
                event_status, voting_mode, result_visibility, starts_at, ends_at,
                max_votes_per_voter, price_per_vote, currency,
                nomination_opens_at, nomination_closes_at, cover_image_key
             ) VALUES (
                :organizer_id, :created_by, :name, :slug, :event_type, :description,
                'draft', :voting_mode, :result_visibility, :starts_at, :ends_at,
                :max_votes_per_voter, :price_per_vote, :currency,
                :nomination_opens_at, :nomination_closes_at, :cover_image_key
             )"
        );
        $statement->execute([
            'organizer_id' => $organizerId,
            'created_by' => $userId,
            'name' => $values['name'],
            'slug' => $slug,
            'event_type' => $values['event_type'],
            'description' => $values['description'],
            'voting_mode' => $values['voting_mode'],
            'result_visibility' => $values['result_visibility'],
            'starts_at' => $values['starts_at'],
            'ends_at' => $values['ends_at'],
            'max_votes_per_voter' => $values['max_votes_per_voter'],
            'price_per_vote' => $values['price_per_vote'],
            'currency' => $values['currency'],
            'nomination_opens_at' => $values['nomination_opens_at'],
            'nomination_closes_at' => $values['nomination_closes_at'],
            'cover_image_key' => $this->validateImageKey($input['cover_image_key'] ?? null),
        ]);

        return (int) $this->database->lastInsertId();
    }

    public function update(int $eventId, int $userId, array $roles, array $input): void
    {
        $event = $this->findForManagement($eventId, $userId, $roles);
        $editableStatus = in_array(
            $event['event_status'],
            ['draft', 'rejected', 'pending_review'],
            true
        );
        $publishedBeforeVoting = in_array($event['event_status'], ['published', 'paused'], true)
            && new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC'))
                > new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$editableStatus && !$publishedBeforeVoting) {
            throw new DomainException('Event details can only be edited before voting starts.');
        }

        $values = $this->validateEventInput($input);
        $statement = $this->database->prepare(
            'UPDATE events
             SET name = :name, event_type = :event_type, description = :description,
                 voting_mode = :voting_mode, result_visibility = :result_visibility,
                 starts_at = :starts_at, ends_at = :ends_at,
                 max_votes_per_voter = :max_votes_per_voter,
                 price_per_vote = :price_per_vote, currency = :currency,
                 nomination_opens_at = :nomination_opens_at,
                 nomination_closes_at = :nomination_closes_at,
                 cover_image_key = COALESCE(:cover_image_key, cover_image_key),
                 approved_by = CASE WHEN event_status IN (\'published\', \'paused\') THEN NULL ELSE approved_by END,
                 approved_at = CASE WHEN event_status IN (\'published\', \'paused\') THEN NULL ELSE approved_at END,
                 event_status = CASE WHEN event_status IN (\'published\', \'paused\') THEN \'pending_review\' ELSE event_status END
             WHERE id = :event_id'
        );
        $statement->execute([
            'name' => $values['name'],
            'event_type' => $values['event_type'],
            'description' => $values['description'],
            'voting_mode' => $values['voting_mode'],
            'result_visibility' => $values['result_visibility'],
            'starts_at' => $values['starts_at'],
            'ends_at' => $values['ends_at'],
            'max_votes_per_voter' => $values['max_votes_per_voter'],
            'price_per_vote' => $values['price_per_vote'],
            'currency' => $values['currency'],
            'nomination_opens_at' => $values['nomination_opens_at'],
            'nomination_closes_at' => $values['nomination_closes_at'],
            'cover_image_key' => $this->validateImageKey($input['cover_image_key'] ?? null),
            'event_id' => $eventId,
        ]);
    }

    public function transitionStatus(int $eventId, int $userId, array $roles, string $nextStatus): void
    {
        $event = $this->findForManagement($eventId, $userId, $roles);
        $allowedTransitions = [
            'draft' => ['pending_review', 'closed'],
            'rejected' => ['pending_review', 'closed'],
            'pending_review' => ['closed'],
            'published' => ['paused', 'closed'],
            'paused' => ['closed'],
        ];
        $currentStatus = $event['event_status'];

        if (!in_array($nextStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw new DomainException('That event status change is not allowed.');
        }

        $statement = $this->database->prepare(
            'UPDATE events SET event_status = :event_status WHERE id = :event_id'
        );
        $statement->execute([
            'event_status' => $nextStatus,
            'event_id' => $eventId,
        ]);
    }

    private function validateEventInput(array $input): array
    {
        $fields = [];
        foreach ([
            'name', 'event_type', 'description', 'voting_mode', 'result_visibility',
            'currency', 'price_per_vote', 'max_votes_per_voter', 'starts_at', 'ends_at',
            'nomination_opens_at', 'nomination_closes_at',
        ] as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Event fields must contain text values.');
            }

            $fields[$field] = $value;
        }

        $name = trim($fields['name']);
        $eventType = trim($fields['event_type']);
        $description = trim($fields['description']);
        $votingMode = $fields['voting_mode'];
        $visibility = $fields['result_visibility'];
        $currency = strtoupper(trim($fields['currency'] === '' ? 'GHS' : $fields['currency']));
        $price = trim($fields['price_per_vote'] === '' ? '0' : $fields['price_per_vote']);
        $maxVotesValue = trim($fields['max_votes_per_voter']);

        if ($name === '' || mb_strlen($name, 'UTF-8') > 180) {
            throw new InvalidArgumentException('Event name is required and must be at most 180 characters.');
        }
        if ($eventType === '' || mb_strlen($eventType, 'UTF-8') > 80) {
            throw new InvalidArgumentException('Choose an event type using no more than 80 characters.');
        }
        if ($description === '' || mb_strlen($description, 'UTF-8') > 20000) {
            throw new InvalidArgumentException('Event description is required and must be at most 20,000 characters.');
        }
        if (!in_array($votingMode, ['free_multiple', 'paid_multiple', 'restricted_single'], true)) {
            throw new InvalidArgumentException('Choose a supported voting mode.');
        }
        if (!in_array($visibility, ['hidden', 'live', 'after_close', 'organizer_only'], true)) {
            throw new InvalidArgumentException('Choose a supported results visibility setting.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Enter a three-letter currency code.');
        }
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $price)) {
            throw new InvalidArgumentException('Enter a valid non-negative vote price with up to two decimal places.');
        }
        if ($votingMode === 'paid_multiple' && preg_match('/^0+(?:\.0{1,2})?$/', $price)) {
            throw new InvalidArgumentException('Paid voting requires a vote price greater than zero.');
        }
        if ($votingMode !== 'paid_multiple' && !preg_match('/^0+(?:\.0{1,2})?$/', $price)) {
            throw new InvalidArgumentException('Only paid voting events can have a non-zero vote price.');
        }

        $maxVotes = null;
        if ($maxVotesValue !== '') {
            if (!ctype_digit($maxVotesValue) || (int) $maxVotesValue < 1 || (int) $maxVotesValue > 10000) {
                throw new InvalidArgumentException('The vote limit must be a whole number between 1 and 10,000.');
            }
            $maxVotes = (int) $maxVotesValue;
        }
        if ($votingMode === 'restricted_single') {
            if ($maxVotesValue !== '' && $maxVotes !== 1) {
                throw new InvalidArgumentException('Restricted elections allow exactly one vote per voter.');
            }
            $maxVotes = 1;
        }

        $startsAt = $this->parseUtcDate($fields['starts_at'], 'Voting start');
        $endsAt = $this->parseUtcDate($fields['ends_at'], 'Voting end');
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('Voting must end after it starts.');
        }
        $nominationOpensAt = null;
        $nominationClosesAt = null;
        if ($fields['nomination_opens_at'] !== '' || $fields['nomination_closes_at'] !== '') {
            if ($fields['nomination_opens_at'] === '' || $fields['nomination_closes_at'] === '') {
                throw new InvalidArgumentException('Enter both nomination start and end times, or leave both empty.');
            }
            $nominationOpensAt = $this->parseUtcDate($fields['nomination_opens_at'], 'Nomination start');
            $nominationClosesAt = $this->parseUtcDate($fields['nomination_closes_at'], 'Nomination end');
            if ($nominationClosesAt <= $nominationOpensAt || $nominationClosesAt > $endsAt) {
                throw new InvalidArgumentException('The nomination window must end after it starts and before the event ends.');
            }
        }

        return [
            'name' => $name,
            'event_type' => $eventType,
            'description' => $description,
            'voting_mode' => $votingMode,
            'result_visibility' => $visibility,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
            'max_votes_per_voter' => $maxVotes,
            'price_per_vote' => $price,
            'currency' => $currency,
            'nomination_opens_at' => $nominationOpensAt?->format('Y-m-d H:i:s'),
            'nomination_closes_at' => $nominationClosesAt?->format('Y-m-d H:i:s'),
        ];
    }

    private function validateImageKey(mixed $storageKey): ?string
    {
        if ($storageKey === null) {
            return null;
        }
        if (!ImageStorage::isValidKey($storageKey)) {
            throw new InvalidArgumentException('Choose a valid event image.');
        }

        return $storageKey;
    }

    private function parseUtcDate(mixed $value, string $label): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$label} is required.");
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Enter a valid ' . strtolower($label) . ' date and time.');
        }

        return $date;
    }

    private function uniqueSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'event';
        }

        do {
            $candidate = $slug . '-' . bin2hex(random_bytes(5));
            $statement = $this->database->prepare('SELECT 1 FROM events WHERE slug = :slug');
            $statement->execute(['slug' => $candidate]);
        } while ($statement->fetchColumn() !== false);

        return $candidate;
    }
}
