<?php
declare(strict_types=1);

final class ContestantService
{
    public function __construct(
        private readonly PDO $database,
        private readonly AccessControl $access
    ) {
    }

    public function listForOrganizer(int $organizerId): array
    {
        $statement = $this->database->prepare(
            'SELECT contestant.id, contestant.event_id, contestant.display_name,
                    contestant.biography, contestant.profile_image_key, contestant.contestant_status,
                    event.name AS event_name, event.event_status, event.starts_at,
                    GROUP_CONCAT(category.name ORDER BY category.name SEPARATOR \', \') AS category_names
             FROM contestants AS contestant
             JOIN events AS event ON event.id = contestant.event_id
             LEFT JOIN contestant_categories AS assignment
               ON assignment.contestant_id = contestant.id
              AND assignment.event_id = contestant.event_id
             LEFT JOIN categories AS category
               ON category.id = assignment.category_id
              AND category.event_id = assignment.event_id
             WHERE event.organizer_id = :organizer_id
             GROUP BY contestant.id, contestant.event_id, contestant.display_name,
                      contestant.biography, contestant.profile_image_key, contestant.contestant_status,
                      event.name, event.event_status, event.starts_at
             ORDER BY event.created_at DESC, contestant.display_name'
        );
        $statement->execute(['organizer_id' => $organizerId]);

        return $statement->fetchAll();
    }

    public function findForManagement(int $contestantId, int $userId, array $roles): array
    {
        $contestant = $this->findOwnedContestant($contestantId, $userId, $roles);
        $this->requireEditableEvent((int) $contestant['event_id'], $userId, $roles);

        $categoryQuery = $this->database->prepare(
            'SELECT category_id
             FROM contestant_categories
             WHERE contestant_id = :contestant_id AND event_id = :event_id
             ORDER BY category_id
             LIMIT 1'
        );
        $categoryQuery->execute([
            'contestant_id' => $contestantId,
            'event_id' => $contestant['event_id'],
        ]);
        $contestant['category_id'] = $categoryQuery->fetchColumn();

        return $contestant;
    }

    public function create(int $eventId, int $userId, array $roles, array $input): int
    {
        $this->requireEditableEvent($eventId, $userId, $roles);
        $categoryId = $this->validateCategory($eventId, $input['category_id'] ?? null);
        $name = $this->validateName($input['display_name'] ?? null);
        $biography = $this->validateBiography($input['biography'] ?? '');

        $this->database->beginTransaction();
        try {
            $insertContestant = $this->database->prepare(
                "INSERT INTO contestants (
                    event_id, created_by, display_name, biography, profile_image_key, contestant_status
                 )
                 VALUES (:event_id, :created_by, :display_name, :biography, :profile_image_key, 'active')"
            );
            $insertContestant->execute([
                'event_id' => $eventId,
                'created_by' => $userId,
                'display_name' => $name,
                'biography' => $biography,
                'profile_image_key' => $this->validateImageKey($input['profile_image_key'] ?? null),
            ]);
            $contestantId = (int) $this->database->lastInsertId();

            $this->assignCategory($eventId, $categoryId, $contestantId);
            $this->database->commit();

            return $contestantId;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function update(int $contestantId, int $userId, array $roles, array $input): void
    {
        $contestant = $this->findForManagement($contestantId, $userId, $roles);
        $categoryId = $this->validateCategory(
            (int) $contestant['event_id'],
            $input['category_id'] ?? null
        );
        $name = $this->validateName($input['display_name'] ?? null);
        $biography = $this->validateBiography($input['biography'] ?? '');

        $this->database->beginTransaction();
        try {
            $updateContestant = $this->database->prepare(
                'UPDATE contestants
                 SET display_name = :display_name, biography = :biography,
                     profile_image_key = COALESCE(:profile_image_key, profile_image_key)
                 WHERE id = :contestant_id'
            );
            $updateContestant->execute([
                'display_name' => $name,
                'biography' => $biography,
                'profile_image_key' => $this->validateImageKey($input['profile_image_key'] ?? null),
                'contestant_id' => $contestantId,
            ]);
            $this->assignCategory((int) $contestant['event_id'], $categoryId, $contestantId);
            $this->database->commit();
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function deactivate(int $contestantId, int $userId, array $roles): void
    {
        $contestant = $this->findForManagement($contestantId, $userId, $roles);
        $statement = $this->database->prepare(
            "UPDATE contestants SET contestant_status = 'inactive' WHERE id = :contestant_id"
        );
        $statement->execute(['contestant_id' => $contestantId]);
    }

    private function findOwnedContestant(int $contestantId, int $userId, array $roles): array
    {
        $statement = $this->database->prepare(
            'SELECT contestant.id, contestant.event_id, contestant.display_name,
                    contestant.biography, contestant.profile_image_key,
                    contestant.contestant_status, event.name AS event_name
             FROM contestants AS contestant
             JOIN events AS event ON event.id = contestant.event_id
             WHERE contestant.id = :contestant_id
             LIMIT 1'
        );
        $statement->execute(['contestant_id' => $contestantId]);
        $contestant = $statement->fetch();
        if (!is_array($contestant)
            || !$this->access->canManageEvent($userId, $roles, (int) $contestant['event_id'])) {
            http_response_code(404);
            throw new RuntimeException('The contestant was not found or you do not have access to it.');
        }

        return $contestant;
    }

    private function requireEditableEvent(int $eventId, int $userId, array $roles): void
    {
        $event = (new EventService($this->database, $this->access))
            ->findForManagement($eventId, $userId, $roles);
        $canEditStatus = in_array(
            $event['event_status'],
            ['draft', 'rejected', 'pending_review'],
            true
        );
        $canEditBeforeVoting = in_array($event['event_status'], ['published', 'paused'], true)
            && new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC'))
                > new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$canEditStatus && !$canEditBeforeVoting) {
            throw new DomainException('Nominees can only be changed before voting starts.');
        }
    }

    private function validateCategory(int $eventId, mixed $categoryId): int
    {
        if (!is_string($categoryId) || !ctype_digit($categoryId) || (int) $categoryId < 1) {
            throw new InvalidArgumentException('Choose a valid event category.');
        }

        $statement = $this->database->prepare(
            "SELECT id
             FROM categories
             WHERE id = :category_id
               AND event_id = :event_id
               AND category_status = 'active'
             LIMIT 1"
        );
        $statement->execute([
            'category_id' => (int) $categoryId,
            'event_id' => $eventId,
        ]);
        $foundId = $statement->fetchColumn();
        if ($foundId === false) {
            throw new InvalidArgumentException('Choose an active category belonging to this event.');
        }

        return (int) $foundId;
    }

    private function assignCategory(int $eventId, int $categoryId, int $contestantId): void
    {
        $statement = $this->database->prepare(
            'INSERT IGNORE INTO contestant_categories (event_id, category_id, contestant_id)
             VALUES (:event_id, :category_id, :contestant_id)'
        );
        $statement->execute([
            'event_id' => $eventId,
            'category_id' => $categoryId,
            'contestant_id' => $contestantId,
        ]);
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

    private function validateName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Contestant name is required.');
        }
        $name = trim($value);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new InvalidArgumentException('Contestant name is required and must be at most 160 characters.');
        }

        return $name;
    }

    private function validateBiography(mixed $value): ?string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > 20000) {
            throw new InvalidArgumentException('Biography must be text no longer than 20,000 characters.');
        }
        $biography = trim($value);

        return $biography === '' ? null : $biography;
    }
}
