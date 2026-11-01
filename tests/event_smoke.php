<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$database = database_connection();
$auth = new AuthService($database);
$access = new AccessControl($database);
$events = new EventService($database, $access);
$categories = new CategoryService($database, $access);
$contestants = new ContestantService($database, $access);
$nominations = new NominationService($database, $access);
$votes = new VoteService($database);
$analytics = new OrganizerAnalyticsService($database, $access);
$payments = new PaymentSimulator($database);
$unique = bin2hex(random_bytes(8));
$organizerEmail = "event-smoke-owner-{$unique}@example.test";
$outsiderEmail = "event-smoke-outsider-{$unique}@example.test";
$password = 'WInVote-Event-Smoke-' . $unique;
$organizerId = null;
$outsiderId = null;
$createdEventIds = [];
$createdVoteCategoryIds = [];
$createdCategoryIds = [];
$createdContestantIds = [];
$createdNominationIds = [];
$assertions = 0;
$testStartsAt = new DateTimeImmutable('+10 days', new DateTimeZone('UTC'));
$testEndsAt = $testStartsAt->modify('+16 days');
$input = [
    'name' => 'Event Smoke ' . $unique,
    'event_type' => 'University awards',
    'description' => 'Temporary event created by the database-backed smoke test.',
    'voting_mode' => 'free_multiple',
    'result_visibility' => 'after_close',
    'starts_at' => $testStartsAt->format('Y-m-d\TH:i'),
    'ends_at' => $testEndsAt->format('Y-m-d\TH:i'),
    'nomination_opens_at' => $testStartsAt->modify('-30 days')->format('Y-m-d\TH:i'),
    'nomination_closes_at' => $testStartsAt->modify('-1 day')->format('Y-m-d\TH:i'),
    'max_votes_per_voter' => '5',
    'price_per_vote' => '0.00',
    'currency' => 'GHS',
];

try {
    $auth->register($organizerEmail, password_hash($password, PASSWORD_DEFAULT), 'Event Smoke Owner', 'organizer');
    $auth->register($outsiderEmail, password_hash($password, PASSWORD_DEFAULT), 'Event Smoke Outsider', 'voter');

    $ownerQuery = $database->prepare('SELECT id FROM users WHERE email = :email');
    $ownerQuery->execute(['email' => $organizerEmail]);
    $organizerId = (int) $ownerQuery->fetchColumn();
    $outsiderQuery = $database->prepare('SELECT id FROM users WHERE email = :email');
    $outsiderQuery->execute(['email' => $outsiderEmail]);
    $outsiderId = (int) $outsiderQuery->fetchColumn();

    $approveTestOrganizer = $database->prepare(
        "UPDATE organizer_profiles SET approval_status = 'approved' WHERE owner_user_id = :user_id"
    );
    $approveTestOrganizer->execute(['user_id' => $organizerId]);
    $profileQuery = $database->prepare(
        'SELECT id FROM organizer_profiles WHERE owner_user_id = :user_id'
    );
    $profileQuery->execute(['user_id' => $organizerId]);
    $profileId = (int) $profileQuery->fetchColumn();

    $eventId = $events->create($organizerId, $profileId, $input);
    $createdEventIds[] = $eventId;
    $assertions++;
    if ($events->publicEventDetails($eventId) !== null) {
        throw new RuntimeException('An unpublished draft appeared in public event details.');
    }
    $assertions++;

    $ownerRoles = ['organizer'];
    if (!$access->canManageEvent($organizerId, $ownerRoles, $eventId)) {
        throw new RuntimeException('An organizer could not access an event they own.');
    }
    if ($access->canManageEvent($outsiderId, ['voter'], $eventId)) {
        throw new RuntimeException('An unrelated user was granted access to the organizer event.');
    }
    $assertions++;
    try {
        $analytics->eventInsights($eventId, $outsiderId, ['voter']);
        throw new RuntimeException('An unrelated voter accessed private event analytics.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'not found or you do not have access')) {
            throw $exception;
        }
        $assertions++;
    }

    $event = $events->findForManagement($eventId, $organizerId, $ownerRoles);
    if ($event['event_status'] !== 'draft' || !str_starts_with($event['slug'], 'event-smoke-' . $unique)) {
        throw new RuntimeException('New organizer events must be saved as uniquely identified drafts.');
    }
    $assertions++;

    $updatedInput = $input;
    $updatedInput['name'] .= ' edited';
    $events->update($eventId, $organizerId, $ownerRoles, $updatedInput);
    $assertions++;

    $categoryId = $categories->create($eventId, $organizerId, $ownerRoles, [
        'name' => 'Smoke category',
        'description' => 'Temporary category for the smoke test.',
    ]);
    $createdCategoryIds[] = $categoryId;
    $category = $categories->findForManagement($categoryId, $organizerId, $ownerRoles);
    if ((int) $category['event_id'] !== $eventId || $category['event_name'] !== $updatedInput['name']) {
        throw new RuntimeException('Category management returned an incorrect event association.');
    }
    $assertions++;

    $categories->update($categoryId, $organizerId, $ownerRoles, [
        'name' => 'Smoke category updated',
        'description' => 'Updated test description.',
    ]);
    $categories->deactivate($categoryId, $organizerId, $ownerRoles);
    $categoryStatus = $database->prepare('SELECT category_status FROM categories WHERE id = :category_id');
    $categoryStatus->execute(['category_id' => $categoryId]);
    if ($categoryStatus->fetchColumn() !== 'inactive') {
        throw new RuntimeException('Category deactivation did not preserve the category as inactive.');
    }
    $assertions++;

    $contestantCategoryId = $categories->create($eventId, $organizerId, $ownerRoles, [
        'name' => 'Contestant category',
        'description' => '',
    ]);
    $createdCategoryIds[] = $contestantCategoryId;
    $contestantId = $contestants->create($eventId, $organizerId, $ownerRoles, [
        'category_id' => (string) $contestantCategoryId,
        'display_name' => 'Smoke Contestant',
        'biography' => 'Temporary contestant for the event smoke test.',
    ]);
    $createdContestantIds[] = $contestantId;
    $contestant = $contestants->findForManagement($contestantId, $organizerId, $ownerRoles);
    if ((int) $contestant['category_id'] !== $contestantCategoryId
        || $contestant['event_name'] !== $updatedInput['name']) {
        throw new RuntimeException('Contestant was not linked to the selected event category.');
    }
    if ($access->canManageEvent($outsiderId, ['voter'], $eventId)) {
        throw new RuntimeException('A user outside the organizer was granted contestant-management access.');
    }
    $assertions++;

    $contestants->update($contestantId, $organizerId, $ownerRoles, [
        'category_id' => (string) $contestantCategoryId,
        'display_name' => 'Smoke Contestant Updated',
        'biography' => 'Updated temporary biography.',
    ]);
    $contestants->deactivate($contestantId, $organizerId, $ownerRoles);
    $contestantStatus = $database->prepare(
        'SELECT contestant_status FROM contestants WHERE id = :contestant_id'
    );
    $contestantStatus->execute(['contestant_id' => $contestantId]);
    if ($contestantStatus->fetchColumn() !== 'inactive') {
        throw new RuntimeException('Contestant deactivation did not preserve the contestant record.');
    }
    $assertions++;

    try {
        $invalidPaidEvent = $input;
        $invalidPaidEvent['voting_mode'] = 'paid_multiple';
        $events->create($organizerId, $profileId, $invalidPaidEvent);
        throw new RuntimeException('Paid voting accepted a zero vote price.');
    } catch (InvalidArgumentException) {
        $assertions++;
    }

    try {
        $invalidWindow = $input;
        $invalidWindow['ends_at'] = '2026-11-01T10:00';
        $events->create($organizerId, $profileId, $invalidWindow);
        throw new RuntimeException('Event creation accepted an end time before the start time.');
    } catch (InvalidArgumentException) {
        $assertions++;
    }

    $paidEvent = $input;
    $paidEvent['name'] .= ' paid';
    $paidEvent['voting_mode'] = 'paid_multiple';
    $paidEvent['price_per_vote'] = '2.50';
    $paidEventId = $events->create($organizerId, $profileId, $paidEvent);
    $createdEventIds[] = $paidEventId;
    $paidCategoryId = $categories->create($paidEventId, $organizerId, $ownerRoles, [
        'name' => 'Paid smoke category',
        'description' => '',
    ]);
    $createdVoteCategoryIds[] = $paidCategoryId;
    $paidContestantId = $contestants->create($paidEventId, $organizerId, $ownerRoles, [
        'category_id' => (string) $paidCategoryId,
        'display_name' => 'Paid Smoke Contestant',
        'biography' => '',
    ]);
    $createdContestantIds[] = $paidContestantId;
    $assertions++;

    $events->transitionStatus($eventId, $organizerId, $ownerRoles, 'pending_review');
    try {
        $events->transitionStatus($eventId, $organizerId, $ownerRoles, 'published');
        throw new RuntimeException('An organizer was able to publish an event without platform approval.');
    } catch (DomainException) {
        $assertions++;
    }

    $finalEvent = $events->findForManagement($eventId, $organizerId, $ownerRoles);
    if ($finalEvent['event_status'] !== 'pending_review') {
        throw new RuntimeException('An invalid event status transition changed the stored status.');
    }
    $assertions++;

    $publishTestEvent = $database->prepare(
        "UPDATE events SET event_status = 'published' WHERE id = :event_id"
    );
    $publishTestEvent->execute(['event_id' => $eventId]);
    $publicEvents = $events->listPublicEvents();
    $publicEvent = null;
    foreach ($publicEvents as $listedEvent) {
        if ((int) $listedEvent['id'] === $eventId) {
            $publicEvent = $listedEvent;
            break;
        }
    }
    if (!is_array($publicEvent) || $publicEvent['public_status'] !== 'Upcoming') {
        throw new RuntimeException('An approved upcoming event was missing from public event discovery.');
    }
    $assertions++;
    $publicDetails = $events->publicEventDetails($eventId);
    if (!is_array($publicDetails)
        || $publicDetails['event']['name'] !== $updatedInput['name']
        || (int) $publicDetails['event']['has_public_results'] !== 0
        || !in_array($contestantCategoryId, array_column($publicDetails['categories'], 'id'), true)) {
        throw new RuntimeException('Public event details did not return stored event/category data or exposed hidden results.');
    }
    $publishedCategoryId = $categories->create($eventId, $organizerId, $ownerRoles, [
        'name' => 'Organizer added after approval',
        'description' => 'A category created by its owner before voting begins.',
    ]);
    $createdCategoryIds[] = $publishedCategoryId;
    $assertions++;
    $directNomineeId = $contestants->create($eventId, $organizerId, $ownerRoles, [
        'category_id' => (string) $contestantCategoryId,
        'display_name' => 'Organizer Added Smoke Nominee',
        'biography' => 'Added directly by the owner after event approval.',
    ]);
    $createdContestantIds[] = $directNomineeId;
    $publicContestant = $events->publicContestantDetails($directNomineeId);
    if (!is_array($publicContestant)
        || $publicContestant['display_name'] !== 'Organizer Added Smoke Nominee'
        || $publicContestant['public_vote_count'] !== null) {
        throw new RuntimeException('Public nominee details did not return stored data or hid vote totals incorrectly.');
    }
    $assertions += 2;
    $nominationId = $nominations->submit($outsiderId, [
        'event_id' => (string) $eventId,
        'category_id' => (string) $contestantCategoryId,
        'applicant_name' => 'Smoke Nominator',
        'applicant_email' => $outsiderEmail,
        'nominee_name' => 'Nominated Smoke Contestant',
        'nominee_email' => '',
        'biography' => 'Temporary public nomination for testing.',
    ]);
    $createdNominationIds[] = $nominationId;
    $assertions++;

    try {
        $nominations->review($nominationId, $outsiderId, ['voter'], 'approved', '');
        throw new RuntimeException('A user outside the event organizer was able to review a nomination.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'not found or you do not have access')) {
            throw $exception;
        }
        $assertions++;
    }

    $nominations->review($nominationId, $organizerId, $ownerRoles, 'needs_information', 'Please add supporting context.');
    $nominations->review($nominationId, $organizerId, $ownerRoles, 'approved', '');
    $approvedStatus = $database->prepare(
        'SELECT nomination_status FROM nominations WHERE id = :nomination_id'
    );
    $approvedStatus->execute(['nomination_id' => $nominationId]);
    if ($approvedStatus->fetchColumn() !== 'approved') {
        throw new RuntimeException('Nomination approval did not update its review status.');
    }
    $approvedContestant = $database->prepare(
        'SELECT id FROM contestants WHERE nomination_id = :nomination_id'
    );
    $approvedContestant->execute(['nomination_id' => $nominationId]);
    $approvedContestantId = (int) $approvedContestant->fetchColumn();
    if ($approvedContestantId < 1) {
        throw new RuntimeException('Approved nomination did not create its linked contestant.');
    }
    $createdContestantIds[] = $approvedContestantId;
    $assertions++;

    $openVotingWindow = $database->prepare(
        'UPDATE events
         SET starts_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR,
             ends_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR,
             result_visibility = \'after_close\'
         WHERE id = :event_id'
    );
    $openVotingWindow->execute(['event_id' => $eventId]);
    $openEventIds = array_map('intval', array_column($votes->listOpenEvents(), 'id'));
    if (!in_array($eventId, $openEventIds, true)) {
        throw new RuntimeException('An open event with an active ballot was missing from vote selection.');
    }
    $openBallotOptions = $votes->listBallotOptions();
    $hasSmokeBallot = false;
    foreach ($openBallotOptions as $option) {
        if ((int) $option['event_id'] === $eventId
            && (int) $option['category_id'] === $contestantCategoryId
            && (int) $option['contestant_id'] === $approvedContestantId) {
            $hasSmokeBallot = true;
            break;
        }
    }
    if (!$hasSmokeBallot) {
        throw new RuntimeException('The configured category and nominee were missing from live ballot options.');
    }
    $assertions += 2;

    $emptyBallotInput = $input;
    $emptyBallotInput['name'] .= ' empty ballot';
    $emptyBallotEventId = $events->create($organizerId, $profileId, $emptyBallotInput);
    $createdEventIds[] = $emptyBallotEventId;
    $publishEmptyBallot = $database->prepare(
        "UPDATE events
         SET event_status = 'published',
             starts_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR,
             ends_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR
         WHERE id = :event_id"
    );
    $publishEmptyBallot->execute(['event_id' => $emptyBallotEventId]);
    $listedOpenIds = array_map('intval', array_column($votes->listOpenEvents(), 'id'));
    if (in_array($emptyBallotEventId, $listedOpenIds, true) || !$votes->hasOpenEvents()) {
        throw new RuntimeException('Voting listed an event without an active ballot or missed an open event.');
    }
    $assertions++;

    $editableEventInput = $input;
    $editableEventInput['name'] .= ' published edit';
    $editableEventId = $events->create($organizerId, $profileId, $editableEventInput);
    $createdEventIds[] = $editableEventId;
    $markEditablePublished = $database->prepare(
        "UPDATE events
         SET event_status = 'published', approved_by = :approved_by, approved_at = UTC_TIMESTAMP()
         WHERE id = :event_id"
    );
    $markEditablePublished->execute([
        'approved_by' => $organizerId,
        'event_id' => $editableEventId,
    ]);
    $editableEventInput['name'] .= ' revised';
    $events->update($editableEventId, $organizerId, $ownerRoles, $editableEventInput);
    $editableStatus = $database->prepare(
        'SELECT event_status, approved_by FROM events WHERE id = :event_id'
    );
    $editableStatus->execute(['event_id' => $editableEventId]);
    $revisedEvent = $editableStatus->fetch();
    if (!is_array($revisedEvent)
        || $revisedEvent['event_status'] !== 'pending_review'
        || $revisedEvent['approved_by'] !== null) {
        throw new RuntimeException('Editing a published event did not request renewed admin review.');
    }
    $reviewCategoryId = $categories->create($editableEventId, $organizerId, $ownerRoles, [
        'name' => 'Review-stage category',
        'description' => '',
    ]);
    $reviewNomineeId = $contestants->create($editableEventId, $organizerId, $ownerRoles, [
        'category_id' => (string) $reviewCategoryId,
        'display_name' => 'Review-stage nominee',
        'biography' => '',
    ]);
    if ($reviewNomineeId < 1) {
        throw new RuntimeException('An organizer could not complete ballot setup during event review.');
    }
    $createdCategoryIds[] = $reviewCategoryId;
    $createdContestantIds[] = $reviewNomineeId;
    $assertions += 2;
    try {
        $categories->create($eventId, $organizerId, $ownerRoles, [
            'name' => 'Late category',
            'description' => '',
        ]);
        throw new RuntimeException('An organizer changed categories after voting had started.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'before voting starts')) {
            throw $exception;
        }
        $assertions++;
    }
    try {
        $contestants->create($eventId, $organizerId, $ownerRoles, [
            'category_id' => (string) $contestantCategoryId,
            'display_name' => 'Late Smoke Nominee',
            'biography' => '',
        ]);
        throw new RuntimeException('An organizer changed nominees after voting had started.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'before voting starts')) {
            throw $exception;
        }
        $assertions++;
    }
    $events->transitionStatus($eventId, $organizerId, $ownerRoles, 'paused');
    try {
        $events->update($eventId, $organizerId, $ownerRoles, $input);
        throw new RuntimeException('An organizer edited event rules after voting had started.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'before voting starts')) {
            throw $exception;
        }
        $assertions++;
    }
    $restorePublishedEvent = $database->prepare(
        "UPDATE events SET event_status = 'published' WHERE id = :event_id"
    );
    $restorePublishedEvent->execute(['event_id' => $eventId]);
    $voteKey = bin2hex(random_bytes(32));
    $castVote = $votes->cast(
        $eventId,
        $contestantCategoryId,
        $approvedContestantId,
        $outsiderId,
        2,
        $voteKey
    );
    $repeatedVote = $votes->cast(
        $eventId,
        $contestantCategoryId,
        $approvedContestantId,
        $outsiderId,
        2,
        $voteKey
    );
    if ($repeatedVote['id'] !== $castVote['id'] || !$repeatedVote['already_recorded']) {
        throw new RuntimeException('Repeating an identical vote request recorded another ballot.');
    }
    $eligibilityStatus = $database->prepare(
        'SELECT eligibility_status FROM voter_eligibility WHERE event_id = :event_id AND user_id = :user_id'
    );
    $eligibilityStatus->execute(['event_id' => $eventId, 'user_id' => $outsiderId]);
    if ($eligibilityStatus->fetchColumn() !== 'eligible') {
        throw new RuntimeException('An active voter was not automatically marked eligible for the event.');
    }
    $assertions++;

    try {
        $votes->cast(
            $eventId,
            $contestantCategoryId,
            $approvedContestantId,
            $outsiderId,
            4,
            bin2hex(random_bytes(32))
        );
        throw new RuntimeException('A vote was recorded above the event-wide voter limit.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'event limit')) {
            throw $exception;
        }
        $assertions++;
    }

    try {
        $votes->publicResults($eventId);
        throw new RuntimeException('Results with after-close visibility were exposed before voting ended.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'not publicly available')) {
            throw $exception;
        }
        $assertions++;
    }

    $makeResultsLive = $database->prepare(
        "UPDATE events SET result_visibility = 'live' WHERE id = :event_id"
    );
    $makeResultsLive->execute(['event_id' => $eventId]);
    $liveResults = $votes->publicResults($eventId);
    if (count($liveResults['results']) !== 2 || (int) $liveResults['results'][0]['vote_count'] !== 2) {
        throw new RuntimeException('Public results did not calculate the recorded vote quantity.');
    }
    $assertions++;
    $eventInsights = $analytics->eventInsights($eventId, $organizerId, $ownerRoles);
    if ((int) $eventInsights['totals']['total_votes'] !== 2
        || (int) $eventInsights['totals']['unique_voters'] !== 1
        || count($eventInsights['standings']) !== 2
        || array_sum(array_column($eventInsights['trend'], 'vote_count')) !== 2) {
        throw new RuntimeException('Organizer insights did not report current credited votes and standings.');
    }
    $assertions++;

    $publishPaidEvent = $database->prepare(
        "UPDATE events
         SET event_status = 'published',
             starts_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR,
             ends_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR
         WHERE id = :event_id"
    );
    $publishPaidEvent->execute(['event_id' => $paidEventId]);

    $previousAppEnv = getenv('APP_ENV');
    $previousPaymentMode = getenv('PAYMENT_MODE');
    $previousRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    putenv('APP_ENV=testing');
    putenv('PAYMENT_MODE=simulator');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    if (!PaymentSimulator::isAvailableForRequest()) {
        throw new RuntimeException('The local simulator did not enable for a loopback test request.');
    }

    $paymentKey = bin2hex(random_bytes(32));
    $paymentReference = $payments->start(
        $paidEventId,
        $paidCategoryId,
        $paidContestantId,
        $outsiderId,
        2,
        $paymentKey
    );
    $repeatedPaymentReference = $payments->start(
        $paidEventId,
        $paidCategoryId,
        $paidContestantId,
        $outsiderId,
        2,
        $paymentKey
    );
    if ($paymentReference !== $repeatedPaymentReference) {
        throw new RuntimeException('Repeating a payment start created another pending transaction.');
    }
    $pendingPayment = $payments->findForVoter($paymentReference, $outsiderId);
    if (!is_array($pendingPayment)
        || $pendingPayment['transaction_status'] !== 'pending'
        || $pendingPayment['amount'] !== '5.00') {
        throw new RuntimeException('The pending simulator transaction did not store its expected amount/status.');
    }
    $assertions += 2;

    $paidOutcome = $payments->complete($paymentReference, $outsiderId, 'success');
    $repeatedPaidOutcome = $payments->complete($paymentReference, $outsiderId, 'success');
    $creditedPaidVote = $database->prepare(
        "SELECT COUNT(*), COALESCE(SUM(vote_quantity), 0)
         FROM votes
         WHERE transaction_id = (
             SELECT id FROM transactions WHERE merchant_reference = :merchant_reference
         ) AND vote_status = 'credited'"
    );
    $creditedPaidVote->execute(['merchant_reference' => $paymentReference]);
    $paidVoteTotals = $creditedPaidVote->fetch(PDO::FETCH_NUM);
    if (!$paidOutcome['successful']
        || !$repeatedPaidOutcome['already_completed']
        || !is_array($paidVoteTotals)
        || (int) $paidVoteTotals[0] !== 1
        || (int) $paidVoteTotals[1] !== 2) {
        throw new RuntimeException('A successful simulated payment did not credit exactly one vote record.');
    }
    $assertions++;
    $paidInsights = $analytics->eventInsights($paidEventId, $organizerId, $ownerRoles);
    if ((float) $paidInsights['payments']['simulated_gross'] !== 5.0
        || (int) $paidInsights['payments']['successful_transactions'] !== 1) {
        throw new RuntimeException('Organizer insights did not total successful simulated payments accurately.');
    }
    $assertions++;

    $failedReference = $payments->start(
        $paidEventId,
        $paidCategoryId,
        $paidContestantId,
        $outsiderId,
        1,
        bin2hex(random_bytes(32))
    );
    $failedOutcome = $payments->complete($failedReference, $outsiderId, 'failure');
    $failedPayment = $payments->findForVoter($failedReference, $outsiderId);
    $failedVoteCount = $database->prepare(
        "SELECT COUNT(*) FROM votes
         WHERE transaction_id = (
             SELECT id FROM transactions WHERE merchant_reference = :merchant_reference
         )"
    );
    $failedVoteCount->execute(['merchant_reference' => $failedReference]);
    if ($failedOutcome['successful']
        || !is_array($failedPayment)
        || $failedPayment['transaction_status'] !== 'failed'
        || (int) $failedVoteCount->fetchColumn() !== 0) {
        throw new RuntimeException('A failed simulated payment was incorrectly credited as a vote.');
    }
    $assertions++;

    putenv('PAYMENT_MODE=disabled');
    try {
        $payments->start(
            $paidEventId,
            $paidCategoryId,
            $paidContestantId,
            $outsiderId,
            1,
            bin2hex(random_bytes(32))
        );
        throw new RuntimeException('The simulator accepted a payment while its configuration was disabled.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'simulator is disabled')) {
            throw $exception;
        }
        $assertions++;
    }
    if ($previousAppEnv === false) {
        putenv('APP_ENV');
    } else {
        putenv('APP_ENV=' . $previousAppEnv);
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

    try {
        $votes->cast(
            $paidEventId,
            $paidCategoryId,
            $paidContestantId,
            $outsiderId,
            1,
            bin2hex(random_bytes(32))
        );
        throw new RuntimeException('An event accepted a vote before paid-vote verification was implemented.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'Paid voting is unavailable')) {
            throw $exception;
        }
        $assertions++;
    }

    $restrictedEvent = $input;
    $restrictedEvent['name'] .= ' restricted';
    $restrictedEvent['voting_mode'] = 'restricted_single';
    $restrictedEvent['result_visibility'] = 'live';
    $restrictedEvent['max_votes_per_voter'] = '1';
    $restrictedEventId = $events->create($organizerId, $profileId, $restrictedEvent);
    $createdEventIds[] = $restrictedEventId;
    $restrictedCategoryId = $categories->create($restrictedEventId, $organizerId, $ownerRoles, [
        'name' => 'Restricted category',
        'description' => '',
    ]);
    $createdVoteCategoryIds[] = $restrictedCategoryId;
    $restrictedContestantId = $contestants->create($restrictedEventId, $organizerId, $ownerRoles, [
        'category_id' => (string) $restrictedCategoryId,
        'display_name' => 'Restricted Smoke Contestant',
        'biography' => '',
    ]);
    $createdContestantIds[] = $restrictedContestantId;
    $openVotingWindow->execute(['event_id' => $restrictedEventId]);
    $publishRestrictedEvent = $database->prepare(
        "UPDATE events SET event_status = 'published' WHERE id = :event_id"
    );
    $publishRestrictedEvent->execute(['event_id' => $restrictedEventId]);
    $votes->cast(
        $restrictedEventId,
        $restrictedCategoryId,
        $restrictedContestantId,
        $outsiderId,
        1,
        bin2hex(random_bytes(32))
    );
    try {
        $votes->cast(
            $restrictedEventId,
            $restrictedCategoryId,
            $restrictedContestantId,
            $outsiderId,
            1,
            bin2hex(random_bytes(32))
        );
        throw new RuntimeException('A voter cast more than one restricted ballot in a category.');
    } catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(), 'already voted')) {
            throw $exception;
        }
        $assertions++;
    }

    $closeEvent = $database->prepare(
        "UPDATE events
         SET event_status = 'closed', result_visibility = 'after_close'
         WHERE id = :event_id"
    );
    $closeEvent->execute(['event_id' => $eventId]);
    $closedResults = $votes->publicResults($eventId);
    if ((int) $closedResults['results'][0]['vote_count'] !== 2) {
        throw new RuntimeException('After-close results did not include credited vote totals.');
    }
    $assertions++;
} finally {
    foreach ($createdEventIds as $createdEventId) {
        $deleteVotes = $database->prepare('DELETE FROM votes WHERE event_id = :event_id');
        $deleteVotes->execute(['event_id' => $createdEventId]);
        $deleteAttempts = $database->prepare(
            'DELETE payment_attempt
             FROM payment_attempts AS payment_attempt
             JOIN transactions AS transaction_record
               ON transaction_record.id = payment_attempt.transaction_id
             WHERE transaction_record.event_id = :event_id'
        );
        $deleteAttempts->execute(['event_id' => $createdEventId]);
        $deleteTransactions = $database->prepare(
            'DELETE FROM transactions WHERE event_id = :event_id'
        );
        $deleteTransactions->execute(['event_id' => $createdEventId]);
        $deleteBallots = $database->prepare('DELETE FROM election_ballots WHERE event_id = :event_id');
        $deleteBallots->execute(['event_id' => $createdEventId]);
        $deleteEligibility = $database->prepare('DELETE FROM voter_eligibility WHERE event_id = :event_id');
        $deleteEligibility->execute(['event_id' => $createdEventId]);
    }

    foreach ($createdContestantIds as $createdContestantId) {
        $deleteAssignments = $database->prepare(
            'DELETE FROM contestant_categories WHERE contestant_id = :contestant_id'
        );
        $deleteAssignments->execute(['contestant_id' => $createdContestantId]);

        $deleteContestant = $database->prepare('DELETE FROM contestants WHERE id = :contestant_id');
        $deleteContestant->execute(['contestant_id' => $createdContestantId]);
    }

    foreach ($createdNominationIds as $createdNominationId) {
        $deleteNomination = $database->prepare('DELETE FROM nominations WHERE id = :nomination_id');
        $deleteNomination->execute(['nomination_id' => $createdNominationId]);
    }

    foreach ($createdCategoryIds as $createdCategoryId) {
        $deleteCategory = $database->prepare('DELETE FROM categories WHERE id = :category_id');
        $deleteCategory->execute(['category_id' => $createdCategoryId]);
    }

    foreach ($createdVoteCategoryIds as $createdCategoryId) {
        $deleteCategory = $database->prepare('DELETE FROM categories WHERE id = :category_id');
        $deleteCategory->execute(['category_id' => $createdCategoryId]);
    }

    foreach ($createdEventIds as $createdEventId) {
        $deleteEvent = $database->prepare('DELETE FROM events WHERE id = :event_id');
        $deleteEvent->execute(['event_id' => $createdEventId]);
    }

    foreach ([$organizerId, $outsiderId] as $userId) {
        if ($userId === null) {
            continue;
        }

        $deleteRoles = $database->prepare('DELETE FROM user_roles WHERE user_id = :user_id');
        $deleteRoles->execute(['user_id' => $userId]);

        $deleteProfile = $database->prepare('DELETE FROM organizer_profiles WHERE owner_user_id = :user_id');
        $deleteProfile->execute(['user_id' => $userId]);

        $deleteUser = $database->prepare('DELETE FROM users WHERE id = :user_id');
        $deleteUser->execute(['user_id' => $userId]);
    }
}

fwrite(STDOUT, "Event management smoke checks passed: {$assertions}\n");
