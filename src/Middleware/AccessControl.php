<?php
declare(strict_types=1);

final class AccessControl
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function requireUser(): array
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user)
            || !isset($user['id'], $user['roles'])
            || !is_array($user['roles'])) {
            http_response_code(401);
            throw new RuntimeException('Sign in to access this page.');
        }

        $userId = filter_var($user['id'], FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            http_response_code(401);
            throw new RuntimeException('Sign in to access this page.');
        }

        $statement = $this->database->prepare(
            "SELECT user.id, user.email, user.full_name, user.account_status,
                    organizer.approval_status AS organizer_status,
                    GROUP_CONCAT(role.name ORDER BY role.name SEPARATOR ',') AS roles
             FROM users AS user
             LEFT JOIN organizer_profiles AS organizer ON organizer.owner_user_id = user.id
             LEFT JOIN user_roles AS user_role ON user_role.user_id = user.id
             LEFT JOIN roles AS role ON role.id = user_role.role_id
             WHERE user.id = :user_id
             GROUP BY user.id, user.email, user.full_name, user.account_status,
                      organizer.approval_status
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $currentUser = $statement->fetch();
        if (!is_array($currentUser) || $currentUser['account_status'] !== 'active') {
            $_SESSION = [];
            session_regenerate_id(true);
            http_response_code(401);
            throw new RuntimeException('Your account is no longer active. Sign in again.');
        }

        $currentUser['id'] = (int) $currentUser['id'];
        $currentUser['roles'] = $currentUser['roles'] === null
            ? []
            : explode(',', $currentUser['roles']);
        $_SESSION['user'] = $currentUser;

        return $currentUser;
    }

    public function requireRole(string $role): array
    {
        $user = $this->requireUser();
        if (!in_array($role, $user['roles'], true)) {
            http_response_code(403);
            throw new RuntimeException('Your account does not have permission to access this page.');
        }

        return $user;
    }

    public function requireApprovedOrganizer(int $userId): int
    {
        $statement = $this->database->prepare(
            "SELECT id
             FROM organizer_profiles
             WHERE owner_user_id = :user_id AND approval_status = 'approved'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $organizerId = $statement->fetchColumn();

        if ($organizerId === false) {
            http_response_code(403);
            throw new RuntimeException('Organizer access is available after platform approval.');
        }

        return (int) $organizerId;
    }

    public function canManageEvent(int $userId, array $roles, int $eventId): bool
    {
        if (in_array('super_admin', $roles, true)) {
            return true;
        }

        $statement = $this->database->prepare(
            'SELECT 1
             FROM events AS e
             LEFT JOIN organizer_profiles AS op ON op.id = e.organizer_id
             LEFT JOIN event_admins AS ea ON ea.event_id = e.id AND ea.user_id = :assigned_user_id
             WHERE e.id = :event_id
               AND (
                 (op.owner_user_id = :owner_user_id AND op.approval_status = \'approved\')
                 OR ea.user_id IS NOT NULL
                    AND ea.access_role IN (\'manager\', \'editor\')
               )
             LIMIT 1'
        );
        $statement->execute([
            'assigned_user_id' => $userId,
            'event_id' => $eventId,
            'owner_user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
