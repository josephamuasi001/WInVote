<?php
declare(strict_types=1);

final class PaymentSimulator
{
    private const PROVIDER = 'local_simulator';

    public function __construct(private readonly PDO $database)
    {
    }

    public static function isConfigured(): bool
    {
        return in_array(getenv('APP_ENV'), ['development', 'testing'], true)
            && getenv('PAYMENT_MODE') === 'simulator';
    }

    public static function isAvailableForRequest(): bool
    {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

        return self::isConfigured()
            && is_string($remoteAddress)
            && in_array($remoteAddress, ['127.0.0.1', '::1'], true);
    }

    public function start(
        int $eventId,
        int $categoryId,
        int $contestantId,
        int $userId,
        int $quantity,
        string $idempotencyKey
    ): string {
        $this->requireLocalSimulator();
        if ($eventId < 1 || $categoryId < 1 || $contestantId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Choose a valid event, category and contestant.');
        }
        if ($quantity < 1 || $quantity > 10000) {
            throw new InvalidArgumentException('Choose a vote quantity between 1 and 10,000.');
        }
        if (!preg_match('/^[a-f0-9]{32,100}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('The payment request could not be verified. Reload the page and try again.');
        }

        try {
            $this->database->beginTransaction();

            $existingQuery = $this->database->prepare(
                'SELECT payment_attempt.transaction_id, tx.merchant_reference,
                        tx.event_id, tx.category_id, tx.contestant_id,
                        tx.voter_user_id, tx.vote_quantity
                 FROM payment_attempts AS payment_attempt
                 JOIN transactions AS tx ON tx.id = payment_attempt.transaction_id
                 WHERE payment_attempt.provider = :provider
                   AND payment_attempt.request_idempotency_key = :idempotency_key
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingQuery->execute([
                'provider' => self::PROVIDER,
                'idempotency_key' => $idempotencyKey,
            ]);
            $existing = $existingQuery->fetch();
            if (is_array($existing)) {
                if ((int) $existing['event_id'] !== $eventId
                    || (int) $existing['category_id'] !== $categoryId
                    || (int) $existing['contestant_id'] !== $contestantId
                    || (int) $existing['voter_user_id'] !== $userId
                    || (int) $existing['vote_quantity'] !== $quantity) {
                    throw new DomainException('This payment request was already used for a different selection.');
                }

                $this->database->commit();
                return (string) $existing['merchant_reference'];
            }

            $eventQuery = $this->database->prepare(
                'SELECT id, event_status, voting_mode, price_per_vote, currency,
                        max_votes_per_voter,
                        starts_at <= UTC_TIMESTAMP() AS has_started,
                        ends_at > UTC_TIMESTAMP() AS has_not_ended
                 FROM events
                 WHERE id = :event_id
                 FOR UPDATE'
            );
            $eventQuery->execute(['event_id' => $eventId]);
            $event = $eventQuery->fetch();
            if (!is_array($event)
                || $event['event_status'] !== 'published'
                || $event['voting_mode'] !== 'paid_multiple'
                || (int) $event['has_started'] !== 1
                || (int) $event['has_not_ended'] !== 1) {
                throw new DomainException('Paid voting is not open for this event.');
            }

            $this->assertVoter($userId);
            $this->assertTarget($eventId, $categoryId, $contestantId);
            $this->assertVoteLimit($eventId, $userId, $quantity, $event['max_votes_per_voter']);

            $unitPriceMinor = self::toMinorUnits((string) $event['price_per_vote']);
            if ($unitPriceMinor < 1) {
                throw new DomainException('This paid event does not have a valid vote price.');
            }
            $amountMinor = $unitPriceMinor * $quantity;
            if ($amountMinor > 999999999999) {
                throw new InvalidArgumentException('The payment amount is too large.');
            }
            $merchantReference = 'WV-SIM-' . bin2hex(random_bytes(16));

            $insertTransaction = $this->database->prepare(
                "INSERT INTO transactions (
                    event_id, category_id, contestant_id, voter_user_id,
                    merchant_reference, vote_quantity, unit_price, amount, currency,
                    transaction_status
                 ) VALUES (
                    :event_id, :category_id, :contestant_id, :user_id,
                    :merchant_reference, :quantity, :unit_price, :amount, :currency,
                    'pending'
                 )"
            );
            $insertTransaction->execute([
                'event_id' => $eventId,
                'category_id' => $categoryId,
                'contestant_id' => $contestantId,
                'user_id' => $userId,
                'merchant_reference' => $merchantReference,
                'quantity' => $quantity,
                'unit_price' => self::formatMinorUnits($unitPriceMinor),
                'amount' => self::formatMinorUnits($amountMinor),
                'currency' => $event['currency'],
            ]);
            $transactionId = (int) $this->database->lastInsertId();

            $insertAttempt = $this->database->prepare(
                "INSERT INTO payment_attempts (
                    transaction_id, provider, attempt_status, request_idempotency_key
                 ) VALUES (:transaction_id, :provider, 'pending', :idempotency_key)"
            );
            $insertAttempt->execute([
                'transaction_id' => $transactionId,
                'provider' => self::PROVIDER,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->database->commit();

            return $merchantReference;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    public function complete(string $merchantReference, int $userId, string $result): array
    {
        $this->requireLocalSimulator();
        if ($userId < 1
            || !preg_match('/^WV-SIM-[a-f0-9]{32}$/', $merchantReference)
            || !in_array($result, ['success', 'failure'], true)) {
            throw new InvalidArgumentException('Choose a valid simulated payment result.');
        }

        try {
            $this->database->beginTransaction();
            $lookup = $this->database->prepare(
                'SELECT id, event_id FROM transactions
                 WHERE merchant_reference = :merchant_reference
                   AND voter_user_id = :user_id
                 LIMIT 1'
            );
            $lookup->execute([
                'merchant_reference' => $merchantReference,
                'user_id' => $userId,
            ]);
            $summary = $lookup->fetch();
            if (!is_array($summary)) {
                throw new DomainException('The simulated payment could not be found.');
            }

            $eventLock = $this->database->prepare(
                'SELECT id FROM events WHERE id = :event_id FOR UPDATE'
            );
            $eventLock->execute(['event_id' => (int) $summary['event_id']]);
            if ($eventLock->fetchColumn() === false) {
                throw new DomainException('The event for this payment could not be found.');
            }

            $paymentQuery = $this->database->prepare(
                'SELECT tx.id AS transaction_id, tx.event_id,
                        tx.category_id, tx.contestant_id,
                        tx.voter_user_id, tx.vote_quantity,
                        tx.transaction_status, payment_attempt.id AS attempt_id,
                        payment_attempt.attempt_status
                 FROM transactions AS tx
                 JOIN payment_attempts AS payment_attempt
                   ON payment_attempt.transaction_id = tx.id
                  AND payment_attempt.provider = :provider
                 WHERE tx.id = :transaction_id
                 FOR UPDATE'
            );
            $paymentQuery->execute([
                'provider' => self::PROVIDER,
                'transaction_id' => (int) $summary['id'],
            ]);
            $payment = $paymentQuery->fetch();
            if (!is_array($payment)) {
                throw new DomainException('The simulated payment attempt could not be found.');
            }

            if ($payment['transaction_status'] === 'successful') {
                $this->database->commit();
                return ['already_completed' => true, 'successful' => true];
            }
            if ($payment['transaction_status'] !== 'pending'
                || $payment['attempt_status'] !== 'pending') {
                $this->database->commit();
                return ['already_completed' => true, 'successful' => false];
            }

            if ($result === 'failure') {
                $this->markAttempt((int) $payment['transaction_id'], (int) $payment['attempt_id'], 'failed');
                $this->database->commit();
                return ['already_completed' => false, 'successful' => false];
            }

            $eventQuery = $this->database->prepare(
                "SELECT event_status, voting_mode, max_votes_per_voter,
                        starts_at <= UTC_TIMESTAMP() AS has_started,
                        ends_at > UTC_TIMESTAMP() AS has_not_ended
                 FROM events WHERE id = :event_id"
            );
            $eventQuery->execute(['event_id' => (int) $payment['event_id']]);
            $event = $eventQuery->fetch();
            if (!is_array($event)
                || $event['event_status'] !== 'published'
                || $event['voting_mode'] !== 'paid_multiple'
                || (int) $event['has_started'] !== 1
                || (int) $event['has_not_ended'] !== 1) {
                throw new DomainException('Voting is no longer open. The simulated payment was not credited.');
            }

            $this->assertVoter($userId);
            $this->assertTarget(
                (int) $payment['event_id'],
                (int) $payment['category_id'],
                (int) $payment['contestant_id']
            );
            $this->assertVoteLimit(
                (int) $payment['event_id'],
                $userId,
                (int) $payment['vote_quantity'],
                $event['max_votes_per_voter'],
                (int) $payment['transaction_id']
            );
            $this->assertEligible((int) $payment['event_id'], $userId);

            $updateTransaction = $this->database->prepare(
                "UPDATE transactions SET transaction_status = 'successful',
                    completed_at = UTC_TIMESTAMP() WHERE id = :transaction_id"
            );
            $updateTransaction->execute(['transaction_id' => (int) $payment['transaction_id']]);
            $updateAttempt = $this->database->prepare(
                "UPDATE payment_attempts SET attempt_status = 'successful',
                    verified_at = UTC_TIMESTAMP(), response_code = 'SIMULATED_SUCCESS',
                    response_summary = 'Local development simulation only'
                 WHERE id = :attempt_id"
            );
            $updateAttempt->execute(['attempt_id' => (int) $payment['attempt_id']]);

            $insertVote = $this->database->prepare(
                "INSERT INTO votes (
                    event_id, category_id, contestant_id, voter_user_id,
                    transaction_id, payment_attempt_id, idempotency_key,
                    vote_quantity, vote_status
                 ) VALUES (
                    :event_id, :category_id, :contestant_id, :user_id,
                    :transaction_id, :attempt_id, :idempotency_key,
                    :quantity, 'credited'
                 )"
            );
            $insertVote->execute([
                'event_id' => (int) $payment['event_id'],
                'category_id' => (int) $payment['category_id'],
                'contestant_id' => (int) $payment['contestant_id'],
                'user_id' => $userId,
                'transaction_id' => (int) $payment['transaction_id'],
                'attempt_id' => (int) $payment['attempt_id'],
                'idempotency_key' => 'paid:' . $merchantReference,
                'quantity' => (int) $payment['vote_quantity'],
            ]);
            $this->database->commit();

            return ['already_completed' => false, 'successful' => true];
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    public function findForVoter(string $merchantReference, int $userId): ?array
    {
        $this->requireLocalSimulator();
        if ($userId < 1 || !preg_match('/^WV-SIM-[a-f0-9]{32}$/', $merchantReference)) {
            throw new InvalidArgumentException('The simulated payment reference is invalid.');
        }

        $statement = $this->database->prepare(
            'SELECT tx.merchant_reference, tx.event_id,
                    tx.vote_quantity, tx.unit_price,
                    tx.amount, tx.currency,
                    tx.transaction_status, event.name AS event_name,
                    category.name AS category_name, contestant.display_name,
                    payment_attempt.attempt_status
             FROM transactions AS tx
             JOIN events AS event ON event.id = tx.event_id
             JOIN categories AS category ON category.id = tx.category_id
             JOIN contestants AS contestant ON contestant.id = tx.contestant_id
             JOIN payment_attempts AS payment_attempt
               ON payment_attempt.transaction_id = tx.id
              AND payment_attempt.provider = :provider
             WHERE tx.merchant_reference = :merchant_reference
               AND tx.voter_user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'provider' => self::PROVIDER,
            'merchant_reference' => $merchantReference,
            'user_id' => $userId,
        ]);
        $payment = $statement->fetch();

        return is_array($payment) ? $payment : null;
    }

    private function assertVoter(int $userId): void
    {
        $userQuery = $this->database->prepare(
            'SELECT account_status FROM users WHERE id = :user_id FOR UPDATE'
        );
        $userQuery->execute(['user_id' => $userId]);
        if ($userQuery->fetchColumn() !== 'active') {
            throw new DomainException('An active voter account is required to vote.');
        }

        $statement = $this->database->prepare(
            "SELECT 1
             FROM user_roles AS user_role
             JOIN roles AS role ON role.id = user_role.role_id AND role.name = 'voter'
             WHERE user_role.user_id = :user_id
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('An active voter account is required to vote.');
        }
    }

    private function assertTarget(int $eventId, int $categoryId, int $contestantId): void
    {
        $statement = $this->database->prepare(
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
        $statement->execute([
            'category_id' => $categoryId,
            'event_id' => $eventId,
            'contestant_id' => $contestantId,
        ]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('That contestant is not available in the selected event category.');
        }
    }

    private function assertVoteLimit(
        int $eventId,
        int $userId,
        int $quantity,
        mixed $limit,
        ?int $excludedTransactionId = null
    ): void
    {
        if ($limit === null) {
            return;
        }

        $statement = $this->database->prepare(
            "SELECT COALESCE(SUM(vote_quantity), 0)
             FROM votes
             WHERE event_id = :event_id
               AND voter_user_id = :user_id
               AND vote_status = 'credited'"
        );
        $statement->execute(['event_id' => $eventId, 'user_id' => $userId]);
        $creditedVotes = (int) $statement->fetchColumn();

        $excludePending = $excludedTransactionId === null ? '' : ' AND id <> :excluded_transaction_id';
        $pendingQuery = $this->database->prepare(
            "SELECT COALESCE(SUM(vote_quantity), 0)
             FROM transactions
             WHERE event_id = :event_id
               AND voter_user_id = :user_id
               AND transaction_status = 'pending'{$excludePending}"
        );
        $pendingParameters = [
            'event_id' => $eventId,
            'user_id' => $userId,
        ];
        if ($excludedTransactionId !== null) {
            $pendingParameters['excluded_transaction_id'] = $excludedTransactionId;
        }
        $pendingQuery->execute($pendingParameters);
        $pendingVotes = (int) $pendingQuery->fetchColumn();
        if ($creditedVotes + $pendingVotes + $quantity > (int) $limit) {
            throw new DomainException('This payment would exceed the event limit for your account.');
        }
    }

    private function assertEligible(int $eventId, int $userId): void
    {
        $upsert = $this->database->prepare(
            "INSERT INTO voter_eligibility (
                event_id, user_id, eligibility_status, verified_at
             ) VALUES (:event_id, :user_id, 'eligible', UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                eligibility_status = IF(eligibility_status = 'revoked', 'revoked', 'eligible'),
                verified_by = IF(eligibility_status = 'revoked', verified_by, NULL),
                verified_at = IF(eligibility_status = 'revoked', verified_at, UTC_TIMESTAMP())"
        );
        $upsert->execute(['event_id' => $eventId, 'user_id' => $userId]);

        $statement = $this->database->prepare(
            'SELECT eligibility_status FROM voter_eligibility
             WHERE event_id = :event_id AND user_id = :user_id FOR UPDATE'
        );
        $statement->execute(['event_id' => $eventId, 'user_id' => $userId]);
        if ($statement->fetchColumn() !== 'eligible') {
            throw new DomainException('Your eligibility to vote in this event has been revoked.');
        }
    }

    private function markAttempt(int $transactionId, int $attemptId, string $status): void
    {
        $updateTransaction = $this->database->prepare(
            'UPDATE transactions SET transaction_status = :status WHERE id = :transaction_id'
        );
        $updateTransaction->execute(['status' => $status, 'transaction_id' => $transactionId]);
        $updateAttempt = $this->database->prepare(
            'UPDATE payment_attempts SET attempt_status = :status,
                verified_at = UTC_TIMESTAMP(), response_code = :response_code,
                response_summary = :response_summary WHERE id = :attempt_id'
        );
        $updateAttempt->execute([
            'status' => $status,
            'response_code' => 'SIMULATED_FAILURE',
            'response_summary' => 'Local development simulation only',
            'attempt_id' => $attemptId,
        ]);
    }

    private function requireLocalSimulator(): void
    {
        if (!self::isAvailableForRequest()) {
            throw new DomainException(
                'The payment simulator is disabled. Enable it only for local development.'
            );
        }
    }

    private static function toMinorUnits(string $amount): int
    {
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw new DomainException('This event has an invalid vote price.');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private static function formatMinorUnits(int $amount): string
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0');
    }
}
