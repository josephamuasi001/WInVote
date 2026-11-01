<?php
declare(strict_types=1);

final class CategoryService
{
    public function __construct(
        private readonly PDO $database,
        private readonly AccessControl $access
    ) {
    }

    public function listForOrganizer(int $organizerId): array
    {
        $statement = $this->database->prepare(
            'SELECT c.id, c.event_id, c.name, c.description, c.image_key, c.category_status,
                    e.name AS event_name, e.event_status, e.starts_at,
                    COUNT(DISTINCT cc.contestant_id) AS contestant_count
             FROM categories AS c
             JOIN events AS e ON e.id = c.event_id
             LEFT JOIN contestant_categories AS cc ON cc.category_id = c.id
             WHERE e.organizer_id = :organizer_id
             GROUP BY c.id, c.event_id, c.name, c.description, c.image_key, c.category_status,
                      e.name, e.event_status, e.starts_at
             ORDER BY e.created_at DESC, c.name'
        );
        $statement->execute(['organizer_id' => $organizerId]);

        return $statement->fetchAll();
    }

    public function create(int $eventId, int $userId, array $roles, array $input): int
    {
        $this->requireEditableEvent($eventId, $userId, $roles);
        $name = $this->validateName($input['name'] ?? null);
        $description = $this->validateDescription($input['description'] ?? '');

        $statement = $this->database->prepare(
            'INSERT INTO categories (event_id, name, description, image_key)
             VALUES (:event_id, :name, :description, :image_key)'
        );
        $statement->execute([
            'event_id' => $eventId,
            'name' => $name,
            'description' => $description,
            'image_key' => $this->validateImageKey($input['image_key'] ?? null),
        ]);

        return (int) $this->database->lastInsertId();
    }

    public function findForManagement(int $categoryId, int $userId, array $roles): array
    {
        $category = $this->findOwnedCategory($categoryId, $userId, $roles);
        $this->requireEditableEvent((int) $category['event_id'], $userId, $roles);

        return $category;
    }

    public function update(int $categoryId, int $userId, array $roles, array $input): void
    {
        $category = $this->findOwnedCategory($categoryId, $userId, $roles);
        $this->requireEditableEvent((int) $category['event_id'], $userId, $roles);
        $statement = $this->database->prepare(
            'UPDATE categories
             SET name = :name, description = :description,
                 image_key = COALESCE(:image_key, image_key)
             WHERE id = :category_id'
        );
        $statement->execute([
            'name' => $this->validateName($input['name'] ?? null),
            'description' => $this->validateDescription($input['description'] ?? ''),
            'image_key' => $this->validateImageKey($input['image_key'] ?? null),
            'category_id' => $categoryId,
        ]);
    }

    public function deactivate(int $categoryId, int $userId, array $roles): void
    {
        $category = $this->findOwnedCategory($categoryId, $userId, $roles);
        $this->requireEditableEvent((int) $category['event_id'], $userId, $roles);
        $statement = $this->database->prepare(
            "UPDATE categories SET category_status = 'inactive' WHERE id = :category_id"
        );
        $statement->execute(['category_id' => $categoryId]);
    }

    private function findOwnedCategory(int $categoryId, int $userId, array $roles): array
    {
        $statement = $this->database->prepare(
            'SELECT c.id, c.event_id, c.name, c.description, c.image_key, e.name AS event_name
             FROM categories AS c
             JOIN events AS e ON e.id = c.event_id
             WHERE c.id = :category_id
             LIMIT 1'
        );
        $statement->execute(['category_id' => $categoryId]);
        $category = $statement->fetch();
        if (!is_array($category)
            || !$this->access->canManageEvent($userId, $roles, (int) $category['event_id'])) {
            http_response_code(404);
            throw new RuntimeException('The category was not found or you do not have access to it.');
        }

        return $category;
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
            throw new DomainException('Categories can only be changed before voting starts.');
        }
    }

    private function validateName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Category name is required.');
        }
        $name = trim($value);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 140) {
            throw new InvalidArgumentException('Category name is required and must be at most 140 characters.');
        }

        return $name;
    }

    private function validateDescription(mixed $value): ?string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > 10000) {
            throw new InvalidArgumentException('Category description must be text no longer than 10,000 characters.');
        }
        $description = trim($value);

        return $description === '' ? null : $description;
    }

    private function validateImageKey(mixed $storageKey): ?string
    {
        if ($storageKey === null) {
            return null;
        }
        if (!ImageStorage::isValidKey($storageKey)) {
            throw new InvalidArgumentException('Choose a valid category image.');
        }

        return $storageKey;
    }
}
