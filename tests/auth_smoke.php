<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$database = database_connection();
$auth = new AuthService($database);
$email = 'auth-smoke-' . bin2hex(random_bytes(8)) . '@example.test';
$password = 'WInVote-Smoke-' . bin2hex(random_bytes(8));
$userId = null;
$originalSession = $_SESSION;
$assertions = 0;

try {
    $auth->register($email, password_hash($password, PASSWORD_DEFAULT), 'Auth Smoke Test', 'organizer');
    $assertions++;

    $userQuery = $database->prepare(
        'SELECT u.id, op.approval_status
         FROM users AS u
         JOIN organizer_profiles AS op ON op.owner_user_id = u.id
         WHERE u.email = :email'
    );
    $userQuery->execute(['email' => $email]);
    $registeredUser = $userQuery->fetch();
    if (!is_array($registeredUser) || $registeredUser['approval_status'] !== 'pending') {
        throw new RuntimeException('Organizer registration did not create a pending organizer profile.');
    }
    $userId = (int) $registeredUser['id'];
    $assertions++;

    $authenticatedUser = $auth->authenticate($email, $password);
    if (!is_array($authenticatedUser)
        || !str_contains((string) $authenticatedUser['roles'], 'organizer')) {
        throw new RuntimeException('A newly registered organizer could not authenticate.');
    }
    $assertions++;

    if ($auth->authenticate($email, 'incorrect-password') !== null) {
        throw new RuntimeException('Authentication accepted an incorrect password.');
    }
    $assertions++;

    $access = new AccessControl($database);
    $_SESSION['user'] = [
        'id' => $userId,
        'email' => $email,
        'full_name' => 'Auth Smoke Test',
        'roles' => ['super_admin'],
    ];
    if ($access->requireRole('organizer')['roles'] !== ['organizer']) {
        throw new RuntimeException('Authorization did not refresh roles from the database.');
    }
    $assertions++;

    $removeRole = $database->prepare(
        'DELETE user_role
         FROM user_roles AS user_role
         JOIN roles AS role ON role.id = user_role.role_id
         WHERE user_role.user_id = :user_id AND role.name = \'organizer\''
    );
    $removeRole->execute(['user_id' => $userId]);
    try {
        $access->requireRole('organizer');
        throw new RuntimeException('A revoked organizer role remained available in an existing session.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Your account does not have permission to access this page.') {
            throw $exception;
        }
        $assertions++;
    }
    http_response_code(200);
    $assignRole = $database->prepare(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :user_id, id FROM roles WHERE name = \'organizer\''
    );
    $assignRole->execute(['user_id' => $userId]);
    $suspendUser = $database->prepare(
        "UPDATE users SET account_status = 'suspended' WHERE id = :user_id"
    );
    $suspendUser->execute(['user_id' => $userId]);
    $_SESSION['user'] = [
        'id' => $userId,
        'email' => $email,
        'full_name' => 'Auth Smoke Test',
        'roles' => ['organizer'],
    ];
    try {
        $access->requireUser();
        throw new RuntimeException('A suspended account remained authenticated in an existing session.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Your account is no longer active. Sign in again.') {
            throw $exception;
        }
        $assertions++;
    }
    http_response_code(200);
    $reactivateUser = $database->prepare(
        "UPDATE users SET account_status = 'active' WHERE id = :user_id"
    );
    $reactivateUser->execute(['user_id' => $userId]);

    try {
        $auth->register($email, password_hash($password, PASSWORD_DEFAULT), 'Duplicate Test', 'voter');
        throw new RuntimeException('Registration accepted a duplicate email address.');
    } catch (DomainException) {
        $assertions++;
    }
} finally {
    $_SESSION = $originalSession;

    if ($userId !== null) {
        $deleteRoles = $database->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
        $deleteRoles->execute(['user_id' => $userId]);

        $deleteProfile = $database->prepare('DELETE FROM organizer_profiles WHERE owner_user_id = :user_id');
        $deleteProfile->execute(['user_id' => $userId]);

        $deleteUser = $database->prepare('DELETE FROM users WHERE id = :user_id AND email = :email');
        $deleteUser->execute(['user_id' => $userId, 'email' => $email]);
    }
}

fwrite(STDOUT, "Authentication smoke checks passed: {$assertions}\n");
