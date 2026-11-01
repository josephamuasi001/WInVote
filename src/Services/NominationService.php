<?php
declare(strict_types=1);

final class NominationService
{
    public function __construct(
        private readonly PDO $database,
        private readonly AccessControl $access
    ) {
    }

    public function listForOrganizer(int $organizerId): array
    {
        $statement = $this->database->prepare(
            'SELECT nomination.id, nomination.event_id, nomination.category_id,
                    nomination.applicant_name, nomination.applicant_email,
                    nomination.nominee_name, nomination.nominee_email, nomination.biography,
                    nomination.profile_image_key,
                    nomination.nomination_status, nomination.review_notes,
                    nomination.created_at, event.name AS event_name, category.name AS category_name
             FROM nominations AS nomination
             JOIN events AS event ON event.id = nomination.event_id
             JOIN categories AS category
               ON category.id = nomination.category_id AND category.event_id = nomination.event_id
             WHERE event.organizer_id = :organizer_id
             ORDER BY nomination.created_at DESC, nomination.id DESC'
        );
        $statement->execute(['organizer_id' => $organizerId]);

        return $statement->fetchAll();
    }

    public function openEvents(): array
    {
        $statement = $this->database->query(
            "SELECT id, name, organizer_id, nomination_opens_at, nomination_closes_at
             FROM events
             WHERE event_status = 'published'
               AND nomination_opens_at IS NOT NULL
               AND nomination_closes_at IS NOT NULL
               AND UTC_TIMESTAMP() BETWEEN nomination_opens_at AND nomination_closes_at
             ORDER BY nomination_closes_at, name"
        );

        return $statement->fetchAll();
    }

    public function activeCategories(int $eventId): array
    {
        $statement = $this->database->prepare(
            "SELECT id, event_id, name
             FROM categories
             WHERE event_id = :event_id AND category_status = 'active'
             ORDER BY name"
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }

    public function submit(?int $submitterId, array $input): int
    {
        $eventId = $this->positiveInteger($input['event_id'] ?? null, 'Choose an event accepting nominations.');
        $categoryId = $this->positiveInteger($input['category_id'] ?? null, 'Choose an event category.');
        $applicantName = $this->requiredText($input['applicant_name'] ?? null, 'Enter your name.', 160);
        $applicantEmail = $this->email($input['applicant_email'] ?? null, 'Enter a valid contact email address.');
        $nomineeName = $this->requiredText($input['nominee_name'] ?? null, 'Enter the nominee name.', 160);
        $nomineeEmail = $this->optionalEmail($input['nominee_email'] ?? null);
        $biography = $this->optionalText($input['biography'] ?? '', 'Nomination details must be text no longer than 20,000 characters.', 20000);

        $this->database->beginTransaction();
        try {
            $eventQuery = $this->database->prepare(
                "SELECT id
                 FROM events
                 WHERE id = :event_id
                   AND event_status = 'published'
                   AND nomination_opens_at IS NOT NULL
                   AND nomination_closes_at IS NOT NULL
                   AND UTC_TIMESTAMP() BETWEEN nomination_opens_at AND nomination_closes_at
                 FOR UPDATE"
            );
            $eventQuery->execute(['event_id' => $eventId]);
            if ($eventQuery->fetchColumn() === false) {
                throw new DomainException('This event is not currently accepting nominations.');
            }

            $categoryQuery = $this->database->prepare(
                "SELECT id
                 FROM categories
                 WHERE id = :category_id
                   AND event_id = :event_id
                   AND category_status = 'active'
                 LIMIT 1"
            );
            $categoryQuery->execute([
                'category_id' => $categoryId,
                'event_id' => $eventId,
            ]);
            if ($categoryQuery->fetchColumn() === false) {
                throw new InvalidArgumentException('Choose an active category belonging to this event.');
            }

            $insert = $this->database->prepare(
                "INSERT INTO nominations (
                    event_id, category_id, submitted_by, applicant_name, applicant_email,
                    nominee_name, nominee_email, biography, profile_image_key, nomination_status
                 ) VALUES (
                    :event_id, :category_id, :submitted_by, :applicant_name, :applicant_email,
                    :nominee_name, :nominee_email, :biography, :profile_image_key, 'pending'
                 )"
            );
            $insert->execute([
                'event_id' => $eventId,
                'category_id' => $categoryId,
                'submitted_by' => $submitterId,
                'applicant_name' => $applicantName,
                'applicant_email' => $applicantEmail,
                'nominee_name' => $nomineeName,
                'nominee_email' => $nomineeEmail,
                'biography' => $biography,
                'profile_image_key' => $this->validateImageKey($input['profile_image_key'] ?? null),
            ]);
            $nominationId = (int) $this->database->lastInsertId();
            $this->database->commit();

            return $nominationId;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function review(
        int $nominationId,
        int $userId,
        array $roles,
        string $decision,
        mixed $reviewNotes
    ): void {
        if (!in_array($decision, ['approved', 'rejected', 'needs_information'], true)) {
            throw new InvalidArgumentException('Choose an allowed nomination review decision.');
        }
        $notes = $this->optionalText(
            $reviewNotes,
            'Review notes must be text no longer than 5,000 characters.',
            5000
        );
        if ($decision === 'needs_information' && ($notes === null || $notes === '')) {
            throw new InvalidArgumentException('Add a note explaining what information is needed.');
        }

        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT nomination.id, nomination.event_id, nomination.category_id,
                        nomination.nominee_name, nomination.biography, nomination.profile_image_key,
                        nomination.nomination_status,
                        event.event_status, event.starts_at, event.nomination_closes_at,
                        category.category_status
                 FROM nominations AS nomination
                 JOIN events AS event ON event.id = nomination.event_id
                 JOIN categories AS category
                   ON category.id = nomination.category_id
                  AND category.event_id = nomination.event_id
                 WHERE nomination.id = :nomination_id
                 FOR UPDATE'
            );
            $statement->execute(['nomination_id' => $nominationId]);
            $nomination = $statement->fetch();
            if (!is_array($nomination)
                || !$this->access->canManageEvent($userId, $roles, (int) $nomination['event_id'])) {
                http_response_code(404);
                throw new RuntimeException('The nomination was not found or you do not have access to it.');
            }
            if (!in_array($nomination['nomination_status'], ['pending', 'needs_information'], true)) {
                throw new DomainException('Only pending nominations can be reviewed.');
            }

            if ($decision === 'approved') {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $votingStartsAt = new DateTimeImmutable($nomination['starts_at'], new DateTimeZone('UTC'));
                $nominationClosesAt = $nomination['nomination_closes_at'] === null
                    ? null
                    : new DateTimeImmutable($nomination['nomination_closes_at'], new DateTimeZone('UTC'));
                if ($nomination['event_status'] !== 'published'
                    || $nomination['category_status'] !== 'active'
                    || $nominationClosesAt === null
                    || $now >= $votingStartsAt
                    || $now > $nominationClosesAt) {
                    throw new DomainException('This nomination can no longer be approved for the event.');
                }

                $contestant = $this->database->prepare(
                    "INSERT INTO contestants (
                        event_id, nomination_id, created_by, display_name, biography,
                        profile_image_key, contestant_status
                     ) VALUES (
                        :event_id, :nomination_id, :created_by, :display_name, :biography,
                        :profile_image_key, 'active'
                     )"
                );
                $contestant->execute([
                    'event_id' => $nomination['event_id'],
                    'nomination_id' => $nominationId,
                    'created_by' => $userId,
                    'display_name' => $nomination['nominee_name'],
                    'biography' => $nomination['biography'],
                    'profile_image_key' => $nomination['profile_image_key'],
                ]);
                $contestantId = (int) $this->database->lastInsertId();

                $assignment = $this->database->prepare(
                    'INSERT INTO contestant_categories (event_id, category_id, contestant_id)
                     VALUES (:event_id, :category_id, :contestant_id)'
                );
                $assignment->execute([
                    'event_id' => $nomination['event_id'],
                    'category_id' => $nomination['category_id'],
                    'contestant_id' => $contestantId,
                ]);
            }

            $update = $this->database->prepare(
                'UPDATE nominations
                 SET nomination_status = :nomination_status, review_notes = :review_notes,
                     reviewed_by = :reviewed_by, reviewed_at = UTC_TIMESTAMP()
                 WHERE id = :nomination_id'
            );
            $update->execute([
                'nomination_status' => $decision,
                'review_notes' => $notes,
                'reviewed_by' => $userId,
                'nomination_id' => $nominationId,
            ]);
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    private function positiveInteger(mixed $value, string $message): int
    {
        if ((!is_string($value) && !is_int($value))
            || !ctype_digit((string) $value)
            || (int) $value < 1) {
            throw new InvalidArgumentException($message);
        }

        return (int) $value;
    }

    private function requiredText(mixed $value, string $message, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($message);
        }
        $text = trim($value);
        if ($text === '' || mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function optionalText(mixed $value, string $message, int $maxLength): ?string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException($message);
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }

    private function email(mixed $value, string $message): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($message);
        }
        $email = strtolower(trim($value));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException($message);
        }

        return $email;
    }

    private function optionalEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Nominee email must be text.');
        }
        $email = trim($value);
        if ($email === '') {
            return null;
        }

        return $this->email($email, 'Enter a valid nominee email address.');
    }

    private function validateImageKey(mixed $storageKey): ?string
    {
        if ($storageKey === null) {
            return null;
        }
        if (!ImageStorage::isValidKey($storageKey)) {
            throw new InvalidArgumentException('Choose a valid nominee image.');
        }

        return $storageKey;
    }
}
