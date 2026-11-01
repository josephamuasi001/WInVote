<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 2) {
    fwrite(STDERR, "Usage: php scripts\\grant_organizer_access.php email@example.com\n");
    exit(2);
}

$email = strtolower(trim($arguments[1]));
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid account email address.\n");
    exit(2);
}

$database = database_connection();
$database->beginTransaction();
try {
    $userQuery = $database->prepare(
        "SELECT user.id, user.full_name
         FROM users AS user
         JOIN user_roles AS user_role ON user_role.user_id = user.id
         JOIN roles AS role ON role.id = user_role.role_id AND role.name = 'super_admin'
         WHERE user.email = :email AND user.account_status = 'active'
         FOR UPDATE"
    );
    $userQuery->execute(['email' => $email]);
    $user = $userQuery->fetch();
    if (!is_array($user)) {
        throw new RuntimeException('No active super-admin account exists with that email address.');
    }

    $roleQuery = $database->prepare("SELECT id FROM roles WHERE name = 'organizer' LIMIT 1");
    $roleQuery->execute();
    $organizerRoleId = $roleQuery->fetchColumn();
    if ($organizerRoleId === false) {
        throw new RuntimeException('The organizer role is not configured in the database.');
    }

    $profileQuery = $database->prepare(
        'SELECT id, approval_status
         FROM organizer_profiles
         WHERE owner_user_id = :user_id
         FOR UPDATE'
    );
    $profileQuery->execute(['user_id' => $user['id']]);
    $profile = $profileQuery->fetch();

    if (is_array($profile) && in_array($profile['approval_status'], ['suspended', 'rejected'], true)) {
        throw new RuntimeException('This organizer profile is suspended or rejected and cannot be granted access.');
    }

    $changed = false;
    if (!is_array($profile)) {
        $createProfile = $database->prepare(
            "INSERT INTO organizer_profiles (
                owner_user_id, organization_name, approval_status, approved_by, approved_at
             ) VALUES (
                :owner_user_id, :organization_name, 'approved', :approved_by, UTC_TIMESTAMP()
             )"
        );
        $createProfile->execute([
            'owner_user_id' => $user['id'],
            'organization_name' => $user['full_name'],
            'approved_by' => $user['id'],
        ]);
        $profileId = (int) $database->lastInsertId();
        $changed = true;
    } else {
        $profileId = (int) $profile['id'];
        if ($profile['approval_status'] !== 'approved') {
            $approveProfile = $database->prepare(
                "UPDATE organizer_profiles
                 SET approval_status = 'approved',
                     approved_by = :approved_by,
                     approved_at = UTC_TIMESTAMP()
                 WHERE id = :profile_id"
            );
            $approveProfile->execute([
                'approved_by' => $user['id'],
                'profile_id' => $profileId,
            ]);
            $changed = true;
        }
    }

    $assignRole = $database->prepare(
        'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
    );
    $assignRole->execute([
        'user_id' => $user['id'],
        'role_id' => $organizerRoleId,
    ]);
    $changed = $changed || $assignRole->rowCount() > 0;

    if ($changed) {
        $audit = $database->prepare(
            "INSERT INTO audit_logs (
                actor_user_id, action, resource_type, resource_id,
                outcome, details
             ) VALUES (
                :actor_user_id, 'organizer.access_granted', 'organizer', :resource_id,
                'success', :details
             )"
        );
        $audit->execute([
            'actor_user_id' => $user['id'],
            'resource_id' => $profileId,
            'details' => json_encode(
                ['reason' => 'Explicit super-admin organizer access grant'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    $database->commit();
    fwrite(STDOUT, "Approved organizer access is configured for {$email}.\n");
} catch (Throwable $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
