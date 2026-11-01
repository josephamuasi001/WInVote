<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$database = database_connection();
$auth = new AuthService($database);
$access = new AccessControl($database);
$events = new EventService($database, $access);
$categories = new CategoryService($database, $access);
$contestants = new ContestantService($database, $access);
$adminService = new AdminService($database);
$unique = bin2hex(random_bytes(8));
$adminEmail = "admin-smoke-{$unique}@example.test";
$approvedOrganizerEmail = "admin-smoke-approved-{$unique}@example.test";
$rejectedOrganizerEmail = "admin-smoke-rejected-{$unique}@example.test";
$password = 'WInVote-Admin-Smoke-' . $unique;
$adminId = null;
$eventIds = [];
$originalSession = $_SESSION;
$assertions = 0;
$eventInput = [
    'name' => 'Admin Smoke ' . $unique,
    'event_type' => 'University awards',
    'description' => 'Temporary event created by the admin smoke test.',
    'voting_mode' => 'free_multiple',
    'result_visibility' => 'after_close',
    'starts_at' => '2026-11-12T10:00',
    'ends_at' => '2026-11-28T18:00',
    'nomination_opens_at' => '',
    'nomination_closes_at' => '',
    'max_votes_per_voter' => '',
    'price_per_vote' => '0.00',
    'currency' => 'GHS',
];

try {
    $auth->register($adminEmail, password_hash($password, PASSWORD_DEFAULT), 'Admin Smoke', 'voter');
    $auth->register(
        $approvedOrganizerEmail,
        password_hash($password, PASSWORD_DEFAULT),
        'Approved Smoke Organizer',
        'organizer'
    );
    $auth->register(
        $rejectedOrganizerEmail,
        password_hash($password, PASSWORD_DEFAULT),
        'Rejected Smoke Organizer',
        'organizer'
    );

    $findUser = $database->prepare('SELECT id FROM users WHERE email = :email');
    $findUser->execute(['email' => $adminEmail]);
    $adminId = (int) $findUser->fetchColumn();
    $findUser->execute(['email' => $approvedOrganizerEmail]);
    $approvedOrganizerId = (int) $findUser->fetchColumn();
    $findUser->execute(['email' => $rejectedOrganizerEmail]);
    $rejectedOrganizerId = (int) $findUser->fetchColumn();

    $grantAdmin = $database->prepare(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT :user_id, id FROM roles WHERE name = \'super_admin\''
    );
    $grantAdmin->execute(['user_id' => $adminId]);

    $findProfile = $database->prepare(
        'SELECT id FROM organizer_profiles WHERE owner_user_id = :user_id'
    );
    $findProfile->execute(['user_id' => $approvedOrganizerId]);
    $approvedOrganizerProfileId = (int) $findProfile->fetchColumn();
    $findProfile->execute(['user_id' => $rejectedOrganizerId]);
    $rejectedOrganizerProfileId = (int) $findProfile->fetchColumn();

    $pendingOverview = $adminService->overview();
    if ($pendingOverview['pending_organizers'] < 2) {
        throw new RuntimeException('Pending organizer applications were not included in the admin overview.');
    }

    $adminService->reviewOrganizer($approvedOrganizerProfileId, $adminId, 'approved');
    $adminService->reviewOrganizer($rejectedOrganizerProfileId, $adminId, 'rejected');
    $organizerStatuses = $database->prepare(
        'SELECT approval_status, approved_by
         FROM organizer_profiles WHERE id = :organizer_id'
    );
    $organizerStatuses->execute(['organizer_id' => $approvedOrganizerProfileId]);
    $approvedStatus = $organizerStatuses->fetch();
    if (!is_array($approvedStatus)
        || $approvedStatus['approval_status'] !== 'approved'
        || (int) $approvedStatus['approved_by'] !== $adminId) {
        throw new RuntimeException('Organizer approval did not record its admin reviewer.');
    }
    $organizerStatuses->execute(['organizer_id' => $rejectedOrganizerProfileId]);
    if ($organizerStatuses->fetchColumn() !== 'rejected') {
        throw new RuntimeException('Organizer rejection did not update its review status.');
    }
    $assertions += 3;

    $directoryOrganizers = $adminService->allOrganizers();
    $directoryUsers = $adminService->allUsers();
    if (!in_array($approvedOrganizerEmail, array_column($directoryOrganizers, 'email'), true)
        || !in_array($adminEmail, array_column($directoryUsers, 'email'), true)) {
        throw new RuntimeException('The admin directories did not return current organizer and user records.');
    }
    $assertions++;

    $_SESSION['user'] = [
        'id' => $approvedOrganizerId,
        'email' => $approvedOrganizerEmail,
        'full_name' => 'Approved Smoke Organizer',
        'roles' => ['super_admin'],
    ];
    try {
        $access->requireRole('super_admin');
        throw new RuntimeException('A non-admin account was granted platform administration access.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Your account does not have permission to access this page.') {
            throw $exception;
        }
        $assertions++;
    }
    http_response_code(200);
    $_SESSION['user'] = [
        'id' => $adminId,
        'email' => $adminEmail,
        'full_name' => 'Admin Smoke',
        'roles' => ['super_admin'],
    ];
    if ($access->requireRole('super_admin')['id'] !== $adminId) {
        throw new RuntimeException('The super-admin account could not access protected administration.');
    }
    $assertions++;

    $incompleteEventId = $events->create(
        $approvedOrganizerId,
        $approvedOrganizerProfileId,
        $eventInput
    );
    $eventIds[] = $incompleteEventId;
    $events->transitionStatus(
        $incompleteEventId,
        $approvedOrganizerId,
        ['organizer'],
        'pending_review'
    );
    try {
        $adminService->reviewEvent($incompleteEventId, $adminId, 'approved');
        throw new RuntimeException('An event without an active ballot was published.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'at least one active category and nominee')) {
            throw $exception;
        }
        $assertions++;
    }
    $eventStatus = $database->prepare(
        'SELECT event_status, approved_by FROM events WHERE id = :event_id'
    );
    $eventStatus->execute(['event_id' => $incompleteEventId]);
    $stillPending = $eventStatus->fetch();
    if (!is_array($stillPending)
        || $stillPending['event_status'] !== 'pending_review'
        || $stillPending['approved_by'] !== null) {
        throw new RuntimeException('An incomplete event approval attempt changed its status.');
    }
    $adminService->reviewEvent($incompleteEventId, $adminId, 'rejected');

    $eventId = $events->create($approvedOrganizerId, $approvedOrganizerProfileId, $eventInput);
    $eventIds[] = $eventId;
    $categoryId = $categories->create($eventId, $approvedOrganizerId, ['organizer'], [
        'name' => 'Admin Smoke Category',
        'description' => '',
    ]);
    $contestantId = $contestants->create($eventId, $approvedOrganizerId, ['organizer'], [
        'category_id' => (string) $categoryId,
        'display_name' => 'Admin Smoke Nominee',
        'biography' => '',
    ]);
    $events->transitionStatus($eventId, $approvedOrganizerId, ['organizer'], 'pending_review');
    $adminService->reviewEvent($eventId, $adminId, 'approved');
    $eventStatus = $database->prepare(
        'SELECT event_status, approved_by FROM events WHERE id = :event_id'
    );
    $eventStatus->execute(['event_id' => $eventId]);
    $publishedEvent = $eventStatus->fetch();
    if (!is_array($publishedEvent)
        || $publishedEvent['event_status'] !== 'published'
        || (int) $publishedEvent['approved_by'] !== $adminId) {
        throw new RuntimeException('Event approval did not publish the event and record its reviewer.');
    }
    $assertions++;
    $listedEvents = $adminService->allEvents();
    $listedTestEvent = null;
    foreach ($listedEvents as $listedEvent) {
        if ((int) $listedEvent['id'] === $eventId) {
            $listedTestEvent = $listedEvent;
            break;
        }
    }
    if (!is_array($listedTestEvent)
        || (int) $listedTestEvent['category_count'] !== 1
        || (int) $listedTestEvent['nominee_count'] !== 1) {
        throw new RuntimeException('The admin event directory did not show ballot setup counts.');
    }
    $assertions++;

    $paidEventInput = $eventInput;
    $paidEventInput['name'] .= ' paid';
    $paidEventInput['voting_mode'] = 'paid_multiple';
    $paidEventInput['price_per_vote'] = '2.50';
    $paidEventId = $events->create(
        $approvedOrganizerId,
        $approvedOrganizerProfileId,
        $paidEventInput
    );
    $eventIds[] = $paidEventId;
    $paidCategoryId = $categories->create($paidEventId, $approvedOrganizerId, ['organizer'], [
        'name' => 'Paid Smoke Category',
        'description' => '',
    ]);
    $contestants->create($paidEventId, $approvedOrganizerId, ['organizer'], [
        'category_id' => (string) $paidCategoryId,
        'display_name' => 'Paid Smoke Nominee',
        'biography' => '',
    ]);
    $events->transitionStatus($paidEventId, $approvedOrganizerId, ['organizer'], 'pending_review');
    try {
        $adminService->reviewEvent($paidEventId, $adminId, 'approved');
        throw new RuntimeException('A paid-voting event was published before payment verification was available.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'Paid-voting events require a verified payment provider')) {
            throw $exception;
        }
        $assertions++;
    }
    $eventStatus->execute(['event_id' => $paidEventId]);
    if ($eventStatus->fetchColumn() !== 'pending_review') {
        throw new RuntimeException('A blocked paid-voting approval changed the event status.');
    }
    $adminService->reviewEvent($paidEventId, $adminId, 'rejected');
    $assertions += 2;

    $localPaidEventInput = $eventInput;
    $localPaidEventInput['name'] .= ' local payment simulation';
    $localPaidEventInput['voting_mode'] = 'paid_multiple';
    $localPaidEventInput['price_per_vote'] = '2.50';
    $localPaidEventId = $events->create(
        $approvedOrganizerId,
        $approvedOrganizerProfileId,
        $localPaidEventInput
    );
    $eventIds[] = $localPaidEventId;
    $localCategoryId = $categories->create(
        $localPaidEventId,
        $approvedOrganizerId,
        ['organizer'],
        ['name' => 'Local Payment Smoke Category', 'description' => '']
    );
    $contestants->create($localPaidEventId, $approvedOrganizerId, ['organizer'], [
        'category_id' => (string) $localCategoryId,
        'display_name' => 'Local Payment Smoke Nominee',
        'biography' => '',
    ]);
    $events->transitionStatus($localPaidEventId, $approvedOrganizerId, ['organizer'], 'pending_review');

    $previousEnvironment = getenv('APP_ENV');
    $previousPaymentMode = getenv('PAYMENT_MODE');
    $previousRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    try {
        putenv('APP_ENV=testing');
        putenv('PAYMENT_MODE=simulator');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $adminService->reviewEvent($localPaidEventId, $adminId, 'approved');
    } finally {
        if ($previousEnvironment === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $previousEnvironment);
        }
        if ($previousPaymentMode === false) {
            putenv('PAYMENT_MODE');
        } else {
            putenv('PAYMENT_MODE=' . $previousPaymentMode);
        }
        if ($previousRemoteAddress === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $previousRemoteAddress;
        }
    }

    $eventStatus->execute(['event_id' => $localPaidEventId]);
    if ($eventStatus->fetchColumn() !== 'published') {
        throw new RuntimeException('The local simulator could not publish an event on loopback.');
    }
    $assertions++;

    $auditCount = $database->prepare(
        'SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :admin_id'
    );
    $auditCount->execute(['admin_id' => $adminId]);
    if ((int) $auditCount->fetchColumn() !== 6) {
        throw new RuntimeException('Successful admin decisions were not recorded in the audit log.');
    }
    $assertions++;
} finally {
    $_SESSION = $originalSession;

    if ($eventIds !== []) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $deleteAssignments = $database->prepare(
            "DELETE FROM contestant_categories WHERE event_id IN ({$placeholders})"
        );
        $deleteAssignments->execute($eventIds);
        $deleteContestants = $database->prepare(
            "DELETE FROM contestants WHERE event_id IN ({$placeholders})"
        );
        $deleteContestants->execute($eventIds);
        $deleteCategories = $database->prepare(
            "DELETE FROM categories WHERE event_id IN ({$placeholders})"
        );
        $deleteCategories->execute($eventIds);
        $deleteEvents = $database->prepare("DELETE FROM events WHERE id IN ({$placeholders})");
        $deleteEvents->execute($eventIds);
    }
    foreach ([$adminEmail, $approvedOrganizerEmail, $rejectedOrganizerEmail] as $email) {
        $findUser = $database->prepare('SELECT id FROM users WHERE email = :email');
        $findUser->execute(['email' => $email]);
        $userId = $findUser->fetchColumn();
        if ($userId === false) {
            continue;
        }
        $deleteAudit = $database->prepare('DELETE FROM audit_logs WHERE actor_user_id = :user_id');
        $deleteAudit->execute(['user_id' => $userId]);

        $deleteProfile = $database->prepare(
            'DELETE FROM organizer_profiles WHERE owner_user_id = :user_id'
        );
        $deleteProfile->execute(['user_id' => $userId]);

        $deleteRoles = $database->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
        $deleteRoles->execute(['user_id' => $userId]);

        $deleteUser = $database->prepare('DELETE FROM users WHERE id = :user_id');
        $deleteUser->execute(['user_id' => $userId]);
    }
}

fwrite(STDOUT, "Platform administration smoke checks passed: {$assertions}\n");
