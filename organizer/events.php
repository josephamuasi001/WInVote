<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

function organizer_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!isset($_SESSION['user'])) {
    header('Location: ../auth.php?action=login', true, 303);
    exit;
}

$errorMessage = null;
$successMessage = null;
$events = [];
$eventToEdit = null;
$user = $_SESSION['user'] ?? null;
$uploadedImageKey = null;
$workspaceAccessMessage = null;

try {
    $database = database_connection();
    $access = new AccessControl($database);
    $user = $access->requireRole('organizer');
    $organizerId = $access->requireApprovedOrganizer((int) $user['id']);
    $eventService = new EventService($database, $access);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $uploadedImageKey = ImageStorage::storeUpload($_FILES['cover_image'] ?? null);
            $input = $_POST;
            $input['cover_image_key'] = $uploadedImageKey;
            $eventId = $eventService->create((int) $user['id'], $organizerId, $input);
            header('Location: events.php?created=' . $eventId, true, 303);
            exit;
        }
        if ($action === 'update') {
            $eventId = (int) ($_POST['event_id'] ?? 0);
            $uploadedImageKey = ImageStorage::storeUpload($_FILES['cover_image'] ?? null);
            $input = $_POST;
            $input['cover_image_key'] = $uploadedImageKey;
            $eventService->update(
                $eventId,
                (int) $user['id'],
                $user['roles'],
                $input
            );
            header('Location: events.php?updated=1', true, 303);
            exit;
        }
        if ($action === 'status') {
            $eventService->transitionStatus(
                (int) ($_POST['event_id'] ?? 0),
                (int) $user['id'],
                $user['roles'],
                (string) ($_POST['event_status'] ?? '')
            );
            header('Location: events.php?updated=1', true, 303);
            exit;
        }

        throw new InvalidArgumentException('Choose a supported event action.');
    }

    if (isset($_GET['created'])) {
        $successMessage = 'The event was saved as a draft.';
    } elseif (isset($_GET['updated'])) {
        $successMessage = 'The event was updated.';
    }

    if (isset($_GET['edit'])) {
        $eventToEdit = $eventService->findForManagement(
            (int) $_GET['edit'],
            (int) $user['id'],
            $user['roles']
        );
    }
    $events = $eventService->listForOrganizer($organizerId);
} catch (PDOException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    error_log('WInVote organizer event database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Event management is temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    $errorMessage = $exception->getMessage();
    if (in_array($errorMessage, [
        'Your account does not have permission to access this page.',
        'Organizer access is available after platform approval.',
    ], true)) {
        $workspaceAccessMessage = $errorMessage;
    }
}

$isEditing = is_array($eventToEdit);
$formValues = $eventToEdit ?? [
    'name' => '',
    'event_type' => '',
    'description' => '',
    'voting_mode' => 'free_multiple',
    'result_visibility' => 'after_close',
    'starts_at' => '',
    'ends_at' => '',
    'nomination_opens_at' => '',
    'nomination_closes_at' => '',
    'max_votes_per_voter' => '',
    'price_per_vote' => '0.00',
    'currency' => 'GHS',
];
$dateValue = static function (string $value): string {
    $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    return $date->format('Y-m-d\TH:i');
};
?>
<?php organizer_page_header('Manage events', 'events', $user); ?>
  <main class="content-section">
    <div class="container">
      <div class="section-heading">
        <div><h1>Manage your events</h1><p>Create drafts, submit events for review, or pause and close events you own.</p></div>
        <a class="btn btn-secondary" href="../events.php">View public events</a>
      </div>
      <?php if ($errorMessage !== null): ?>
        <?php if ($workspaceAccessMessage !== null): ?>
          <section class="panel workspace-access-panel" role="alert">
            <h2>Organizer access required</h2>
            <?php if ($workspaceAccessMessage === 'Organizer access is available after platform approval.'): ?>
              <p>Your organizer application is still awaiting approval. Once approved, sign in again to create events, categories, and nominees.</p>
            <?php else: ?>
              <p>This workspace is available to approved organizer accounts. Super-admin access alone does not grant organizer event ownership, so this account cannot create events here.</p>
            <?php endif; ?>
            <div class="filter-row">
              <?php if (is_array($user) && in_array('super_admin', $user['roles'] ?? [], true)): ?>
                <a class="btn btn-primary" href="../admin/organizers.php">Review organizer approvals</a>
              <?php endif; ?>
              <a class="btn btn-secondary" href="../auth.php?action=account">Account details</a>
              <a class="btn btn-secondary" href="../auth.php?action=login">Sign in with an approved organizer account</a>
            </div>
          </section>
        <?php else: ?>
          <div class="notice warning" role="alert"><?= organizer_escape($errorMessage) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($successMessage !== null): ?>
        <div class="notice" role="status"><?= organizer_escape($successMessage) ?></div>
      <?php endif; ?>
      <?php if ($errorMessage === null): ?>
        <div class="detail-layout">
          <section class="panel">
            <h2><?= $isEditing ? 'Edit event details' : 'Create an event' ?></h2>
            <p>New events start as drafts. Add at least one category and nominee before submitting for platform approval.</p>
            <form method="post" action="events.php" enctype="multipart/form-data">
              <input type="hidden" name="_token" value="<?= organizer_escape(csrf_token()) ?>">
              <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
              <?php if ($isEditing): ?>
                <input type="hidden" name="event_id" value="<?= (int) $eventToEdit['id'] ?>">
              <?php endif; ?>
              <div class="field"><label for="name">Event name</label><input class="input" id="name" name="name" required maxlength="180" value="<?= organizer_escape((string) $formValues['name']) ?>"></div>
              <div class="field"><label for="event_type">Event type</label><select class="select" style="width:100%" id="event_type" name="event_type" required>
                <?php foreach (['University awards', 'Election', 'Competition', 'Community awards', 'Corporate awards', 'Other'] as $eventType): ?>
                  <option value="<?= organizer_escape($eventType) ?>" <?= $formValues['event_type'] === $eventType ? 'selected' : '' ?>><?= organizer_escape($eventType) ?></option>
                <?php endforeach; ?>
              </select></div>
              <div class="field"><label for="description">Description</label><textarea class="textarea" id="description" name="description" rows="4" required maxlength="20000"><?= organizer_escape((string) $formValues['description']) ?></textarea></div>
              <div class="field"><label for="cover_image">Event image (optional)</label><input class="input" type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/webp"><span class="field-hint">JPEG, PNG or WebP · maximum 2 MB. Leave empty to keep the current image.</span>
                <?php if (!empty($formValues['cover_image_key'])): ?><img class="upload-preview" src="../image.php?key=<?= rawurlencode((string) $formValues['cover_image_key']) ?>" alt="Current event image"><?php endif; ?>
              </div>
              <div class="form-grid">
                <div class="field"><label for="starts_at">Voting starts (UTC)</label><input class="input" type="datetime-local" id="starts_at" name="starts_at" required value="<?= $formValues['starts_at'] !== '' ? organizer_escape($dateValue((string) $formValues['starts_at'])) : '' ?>"></div>
                <div class="field"><label for="ends_at">Voting ends (UTC)</label><input class="input" type="datetime-local" id="ends_at" name="ends_at" required value="<?= $formValues['ends_at'] !== '' ? organizer_escape($dateValue((string) $formValues['ends_at'])) : '' ?>"></div>
              </div>
              <div class="form-grid">
                <div class="field"><label for="nomination_opens_at">Nominations open (optional, UTC)</label><input class="input" type="datetime-local" id="nomination_opens_at" name="nomination_opens_at" value="<?= !empty($formValues['nomination_opens_at']) ? organizer_escape($dateValue((string) $formValues['nomination_opens_at'])) : '' ?>"></div>
                <div class="field"><label for="nomination_closes_at">Nominations close (optional, UTC)</label><input class="input" type="datetime-local" id="nomination_closes_at" name="nomination_closes_at" value="<?= !empty($formValues['nomination_closes_at']) ? organizer_escape($dateValue((string) $formValues['nomination_closes_at'])) : '' ?>"></div>
              </div>
              <div class="form-grid">
                <div class="field"><label for="voting_mode">Voting mode</label><select class="select" style="width:100%" id="voting_mode" name="voting_mode">
                  <option value="free_multiple" <?= $formValues['voting_mode'] === 'free_multiple' ? 'selected' : '' ?>>Free multiple voting</option>
                  <option value="paid_multiple" <?= $formValues['voting_mode'] === 'paid_multiple' ? 'selected' : '' ?>>Paid multiple voting</option>
                  <option value="restricted_single" <?= $formValues['voting_mode'] === 'restricted_single' ? 'selected' : '' ?>>Restricted one-vote election</option>
                </select></div>
                <div class="field"><label for="result_visibility">Results visibility</label><select class="select" style="width:100%" id="result_visibility" name="result_visibility">
                  <?php foreach (['hidden' => 'Hidden', 'live' => 'Live standings', 'after_close' => 'After voting closes', 'organizer_only' => 'Organizer only'] as $value => $label): ?>
                    <option value="<?= organizer_escape($value) ?>" <?= $formValues['result_visibility'] === $value ? 'selected' : '' ?>><?= organizer_escape($label) ?></option>
                  <?php endforeach; ?>
                </select></div>
              </div>
              <div class="form-grid">
                <div class="field"><label for="max_votes_per_voter">Maximum votes per voter (optional)</label><input class="input" type="number" min="1" max="10000" id="max_votes_per_voter" name="max_votes_per_voter" value="<?= organizer_escape((string) $formValues['max_votes_per_voter']) ?>"><span class="field-hint">Restricted elections always allow exactly one vote.</span></div>
                <div class="field"><label for="price_per_vote">Price per vote</label><input class="input" inputmode="decimal" id="price_per_vote" name="price_per_vote" value="<?= organizer_escape((string) $formValues['price_per_vote']) ?>" required><span class="field-hint">Paid voting only; currency is GHS for this setup.</span></div>
              </div>
              <input type="hidden" name="currency" value="GHS">
              <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save event details' : 'Create event draft' ?></button>
              <?php if ($isEditing): ?><a class="btn btn-secondary" href="events.php">Cancel</a><?php endif; ?>
            </form>
          </section>
          <aside class="panel"><h2>Event lifecycle</h2><ul class="detail-list">
            <li><span>New event</span><strong>Draft</strong></li>
            <li><span>Organizer action</span><strong>Submit for review</strong></li>
            <li><span>Publish permission</span><strong>Platform administrator</strong></li>
            <li><span>Live event</span><strong>Pause or close</strong></li>
          </ul><p class="field-hint" style="margin-top:12px">Voting dates are entered and stored in UTC. Paid checkout is simulated only on the local development server; no real payments are collected.</p></aside>
        </div>
        <section class="panel" style="margin-top:18px">
          <div class="section-heading"><div><h2>Your events</h2><p>Only events owned by your approved organizer profile are listed.</p></div></div>
          <?php if ($events === []): ?>
            <div class="empty-state"><div class="empty-icon">▦</div><strong>No events yet</strong><p>Create your first event draft above.</p></div>
          <?php else: ?>
            <div class="table-wrap"><table class="data-table">
              <thead><tr><th>Event</th><th>Type</th><th>Ballot setup</th><th>Voting mode</th><th>Voting period (UTC)</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach ($events as $event): ?>
                <tr>
                  <td><?php if (!empty($event['cover_image_key'])): ?><img class="table-image" src="../image.php?key=<?= rawurlencode((string) $event['cover_image_key']) ?>" alt=""><?php endif; ?><span class="table-primary"><?= organizer_escape($event['name']) ?></span><span class="table-sub"><?= organizer_escape($event['slug']) ?></span></td>
                  <td><?= organizer_escape($event['event_type']) ?></td>
                  <td><?= (int) $event['category_count'] ?> categories<span class="table-sub"><?= (int) $event['nominee_count'] ?> nominees assigned</span></td>
                  <td><?= organizer_escape(str_replace('_', ' ', $event['voting_mode'])) ?></td>
                  <td><?= organizer_escape($event['starts_at']) ?><span class="table-sub">to <?= organizer_escape($event['ends_at']) ?></span></td>
                  <td><span class="pill"><?= organizer_escape(str_replace('_', ' ', $event['event_status'])) ?></span></td>
                  <td>
                    <?php
                      $canManageSetup = in_array($event['event_status'], ['draft', 'rejected', 'pending_review'], true)
                          || (in_array($event['event_status'], ['published', 'paused'], true)
                              && new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC'))
                                  > new DateTimeImmutable('now', new DateTimeZone('UTC')));
                    ?>
                    <?php if (in_array($event['event_status'], ['draft', 'rejected', 'pending_review'], true)
                        || (in_array($event['event_status'], ['published', 'paused'], true) && $canManageSetup)): ?>
                      <a class="table-action" href="events.php?edit=<?= (int) $event['id'] ?>">Edit</a>
                    <?php endif; ?>
                    <a class="table-action" href="analytics.php?event_id=<?= (int) $event['id'] ?>">Insights</a>
                    <?php if ($canManageSetup): ?>
                      <a class="table-action" href="categories.php?event_id=<?= (int) $event['id'] ?>">Categories</a>
                      <a class="table-action" href="contestants.php?event_id=<?= (int) $event['id'] ?>">Nominees</a>
                    <?php endif; ?>
                    <?php if (in_array($event['event_status'], ['draft', 'rejected'], true)): ?>
                      <form method="post" action="events.php" style="display:inline">
                        <input type="hidden" name="_token" value="<?= organizer_escape(csrf_token()) ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                        <input type="hidden" name="event_status" value="pending_review">
                        <button class="table-action" type="submit">Submit for review</button>
                      </form>
                    <?php elseif ($event['event_status'] === 'published'): ?>
                      <?php foreach (['paused' => 'Pause', 'closed' => 'Close'] as $status => $label): ?>
                        <form method="post" action="events.php" style="display:inline">
                          <input type="hidden" name="_token" value="<?= organizer_escape(csrf_token()) ?>">
                          <input type="hidden" name="action" value="status">
                          <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                          <input type="hidden" name="event_status" value="<?= organizer_escape($status) ?>">
                          <button class="table-action" type="submit"><?= organizer_escape($label) ?></button>
                        </form>
                      <?php endforeach; ?>
                    <?php elseif ($event['event_status'] === 'paused'): ?>
                      <form method="post" action="events.php" style="display:inline">
                        <input type="hidden" name="_token" value="<?= organizer_escape(csrf_token()) ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                        <input type="hidden" name="event_status" value="closed">
                        <button class="table-action" type="submit">Close</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </div>
  </main>
<?php organizer_page_footer(); ?>
