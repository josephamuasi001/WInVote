<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

function contestant_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!isset($_SESSION['user'])) {
    header('Location: ../auth.php?action=login', true, 303);
    exit;
}

$errorMessage = null;
$successMessage = null;
$contestants = [];
$events = [];
$categories = [];
$contestantToEdit = null;
$user = $_SESSION['user'] ?? null;
$uploadedImageKey = null;
$requestedEventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);

try {
    $database = database_connection();
    $access = new AccessControl($database);
    $user = $access->requireRole('organizer');
    $organizerId = $access->requireApprovedOrganizer((int) $user['id']);
    $service = new ContestantService($database, $access);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $uploadedImageKey = ImageStorage::storeUpload($_FILES['profile_image'] ?? null);
            $input = $_POST;
            $input['profile_image_key'] = $uploadedImageKey;
            $service->create(
                (int) ($_POST['event_id'] ?? 0),
                (int) $user['id'],
                $user['roles'],
                $input
            );
        } elseif ($action === 'update') {
            $contestantId = (int) ($_POST['contestant_id'] ?? 0);
            $uploadedImageKey = ImageStorage::storeUpload($_FILES['profile_image'] ?? null);
            $input = $_POST;
            $input['profile_image_key'] = $uploadedImageKey;
            $service->update(
                $contestantId,
                (int) $user['id'],
                $user['roles'],
                $input
            );
        } elseif ($action === 'deactivate') {
            $service->deactivate(
                (int) ($_POST['contestant_id'] ?? 0),
                (int) $user['id'],
                $user['roles']
            );
        } else {
            throw new InvalidArgumentException('Choose a supported contestant action.');
        }

        header('Location: contestants.php?saved=1', true, 303);
        exit;
    }

    if (isset($_GET['saved'])) {
        $successMessage = 'The contestant change was saved.';
    }
    if (isset($_GET['edit'])) {
        $contestantToEdit = $service->findForManagement(
            (int) $_GET['edit'],
            (int) $user['id'],
            $user['roles']
        );
    }

    $contestants = $service->listForOrganizer($organizerId);
    $eventQuery = $database->prepare(
        "SELECT id, name, event_status, starts_at
         FROM events
         WHERE organizer_id = :organizer_id
           AND (
             event_status IN ('draft', 'rejected', 'pending_review')
             OR (event_status IN ('published', 'paused') AND starts_at > UTC_TIMESTAMP())
           )
         ORDER BY created_at DESC, id DESC"
    );
    $eventQuery->execute(['organizer_id' => $organizerId]);
    $events = $eventQuery->fetchAll();

    if ($events !== []) {
        $categoryQuery = $database->prepare(
            "SELECT category.id, category.event_id, category.name, event.name AS event_name
             FROM categories AS category
             JOIN events AS event ON event.id = category.event_id
             WHERE event.organizer_id = :organizer_id
               AND (
                 event.event_status IN ('draft', 'rejected', 'pending_review')
                 OR (event.event_status IN ('published', 'paused') AND event.starts_at > UTC_TIMESTAMP())
               )
               AND category.category_status = 'active'
             ORDER BY event.created_at DESC, category.name"
        );
        $categoryQuery->execute(['organizer_id' => $organizerId]);
        $categories = $categoryQuery->fetchAll();
    }
} catch (PDOException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    error_log('WInVote organizer contestant database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Contestant management is temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    $errorMessage = $exception->getMessage();
}

$isEditing = is_array($contestantToEdit);
$eventIdValue = $isEditing ? (int) $contestantToEdit['event_id'] : (int) ($events[0]['id'] ?? 0);
?>
<?php organizer_page_header('Manage contestants', 'contestants', $user); ?>
  <main class="content-section"><div class="container">
    <div class="section-heading"><div><h1>Manage nominees</h1><p>Add, edit, or deactivate nominees on editable events. Approved events remain editable until voting starts.</p></div><a class="btn btn-secondary" href="events.php">Your events</a></div>
    <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= contestant_escape($errorMessage) ?></div><?php endif; ?>
    <?php if ($successMessage !== null): ?><div class="notice" role="status"><?= contestant_escape($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage === null): ?>
      <div class="detail-layout">
        <section class="panel">
          <h2><?= $isEditing ? 'Edit contestant' : 'Add a contestant' ?></h2>
          <?php if ($events === [] || $categories === []): ?>
            <p>Create an event and an active category before adding nominees. For approved events, nominees can be managed until voting starts.</p>
            <a class="btn btn-primary" href="categories.php">Manage categories</a>
          <?php else: ?>
            <form method="post" action="contestants.php" enctype="multipart/form-data">
              <input type="hidden" name="_token" value="<?= contestant_escape(csrf_token()) ?>">
              <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
              <?php if ($isEditing): ?>
                <input type="hidden" name="contestant_id" value="<?= (int) $contestantToEdit['id'] ?>">
                <input type="hidden" name="event_id" value="<?= $eventIdValue ?>">
                <div class="field"><label>Event</label><input class="input" value="<?= contestant_escape((string) $contestantToEdit['event_name']) ?>" disabled></div>
              <?php else: ?>
                <div class="field"><label for="event_id">Event</label><select class="select" style="width:100%" id="event_id" name="event_id" required>
                  <?php foreach ($events as $event): ?>
                        <option value="<?= (int) $event['id'] ?>" <?= $requestedEventId !== false && $requestedEventId !== null && (int) $event['id'] === $requestedEventId ? 'selected' : '' ?>><?= contestant_escape($event['name']) ?> · <?= contestant_escape(str_replace('_', ' ', $event['event_status'])) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <?php endif; ?>
              <div class="field"><label for="category_id">Category</label><select class="select" style="width:100%" id="category_id" name="category_id" required>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>" data-event-id="<?= (int) $category['event_id'] ?>" <?= $isEditing && (int) $contestantToEdit['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= contestant_escape($category['event_name']) ?> · <?= contestant_escape($category['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
              <div class="field"><label for="display_name">Contestant name</label><input class="input" id="display_name" name="display_name" required maxlength="160" value="<?= contestant_escape((string) ($contestantToEdit['display_name'] ?? '')) ?>"></div>
              <div class="field"><label for="biography">Biography</label><textarea class="textarea" id="biography" name="biography" rows="4" maxlength="20000"><?= contestant_escape((string) ($contestantToEdit['biography'] ?? '')) ?></textarea></div>
              <div class="field"><label for="profile_image">Nominee photo (optional)</label><input class="input" type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp"><span class="field-hint">JPEG, PNG or WebP · maximum 2 MB. Leave empty to keep the current photo.</span>
                <?php if (!empty($contestantToEdit['profile_image_key'])): ?><img class="upload-preview" src="../image.php?key=<?= rawurlencode((string) $contestantToEdit['profile_image_key']) ?>" alt="Current nominee photo"><?php endif; ?>
              </div>
              <p class="field-hint">Category assignments are retained when changed so existing results and voting history are not detached.</p>
              <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save contestant' : 'Add contestant' ?></button>
              <?php if ($isEditing): ?><a class="btn btn-secondary" href="contestants.php">Cancel</a><?php endif; ?>
            </form>
          <?php endif; ?>
        </section>
        <aside class="panel"><h2>Profile images</h2><p>Approved organizers can upload JPEG, PNG, or WebP nominee photos up to 2 MB. Photos from nominations are private until an organizer approves them.</p></aside>
      </div>
      <section class="panel" style="margin-top:18px">
        <div class="section-heading"><div><h2>All contestants</h2><p>Contestant records stay attached to their event and voting history.</p></div></div>
        <?php if ($contestants === []): ?>
          <div class="empty-state"><div class="empty-icon">♙</div><strong>No contestants yet</strong><p>Add a contestant to an event category.</p></div>
        <?php else: ?>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>Contestant</th><th>Event</th><th>Categories</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($contestants as $contestant): ?>
              <tr>
                <td><?php if (!empty($contestant['profile_image_key'])): ?><img class="table-image" src="../image.php?key=<?= rawurlencode((string) $contestant['profile_image_key']) ?>" alt=""><?php endif; ?><span class="table-primary"><?= contestant_escape($contestant['display_name']) ?></span><span class="table-sub"><?= contestant_escape((string) ($contestant['biography'] ?? '')) ?></span></td>
                <td><?= contestant_escape($contestant['event_name']) ?></td>
                <td><?= contestant_escape((string) ($contestant['category_names'] ?? 'Unassigned')) ?></td>
                <td><span class="pill"><?= contestant_escape($contestant['contestant_status']) ?></span></td>
                <td>
                  <?php
                    $canManageNominee = in_array($contestant['event_status'] ?? '', ['draft', 'rejected'], true)
                        || (in_array($contestant['event_status'] ?? '', ['published', 'paused'], true)
                            && !empty($contestant['starts_at'])
                            && new DateTimeImmutable($contestant['starts_at'], new DateTimeZone('UTC'))
                                > new DateTimeImmutable('now', new DateTimeZone('UTC')));
                  ?>
                  <?php if ($contestant['contestant_status'] === 'active' && $canManageNominee): ?>
                    <a class="table-action" href="contestants.php?edit=<?= (int) $contestant['id'] ?>">Edit</a>
                    <form method="post" action="contestants.php" style="display:inline">
                      <input type="hidden" name="_token" value="<?= contestant_escape(csrf_token()) ?>">
                      <input type="hidden" name="action" value="deactivate">
                      <input type="hidden" name="contestant_id" value="<?= (int) $contestant['id'] ?>">
                      <button class="table-action" type="submit">Deactivate</button>
                    </form>
                  <?php else: ?>
                    <span class="field-hint">No actions available</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div></main>
  <script>
    const eventSelect = document.querySelector('#event_id');
    const categorySelect = document.querySelector('#category_id');
    if (eventSelect && categorySelect) {
      const filterCategories = () => {
        const eventId = eventSelect.value;
        [...categorySelect.options].forEach((option) => {
          option.hidden = option.dataset.eventId !== eventId;
        });
        if (categorySelect.selectedOptions[0]?.hidden) {
          categorySelect.selectedIndex = [...categorySelect.options].findIndex((option) => !option.hidden);
        }
      };
      eventSelect.addEventListener('change', filterCategories);
      filterCategories();
    }
  </script>
<?php organizer_page_footer(); ?>
