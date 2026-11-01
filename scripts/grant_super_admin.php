<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 2) {
    fwrite(STDERR, "Usage: php scripts\\grant_super_admin.php email@example.com\n");
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
        "SELECT id
         FROM users
         WHERE email = :email AND account_status = 'active'
         FOR UPDATE"
    );
    $userQuery->execute(['email' => $email]);
    $userId = $userQuery->fetchColumn();
    if ($userId === false) {
        throw new RuntimeException('No active account exists with that email address.');
    }

    $roleQuery = $database->prepare("SELECT id FROM roles WHERE name = 'super_admin' LIMIT 1");
    $roleQuery->execute();
    $roleId = $roleQuery->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('The super_admin role is not configured in the database.');
    }

    $assignRole = $database->prepare(
        'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
    );
    $assignRole->execute(['user_id' => $userId, 'role_id' => $roleId]);
    $database->commit();
    fwrite(STDOUT, "Super-admin access is configured for {$email}.\n");
} catch (Throwable $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
