<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

function category_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!isset($_SESSION['user'])) {
    header('Location: ../auth.php?action=login', true, 303);
    exit;
}

$errorMessage = null;
$successMessage = null;
$categories = [];
$events = [];
$categoryToEdit = null;
$user = $_SESSION['user'] ?? null;
$uploadedImageKey = null;
$requestedEventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);

try {
    $database = database_connection();
    $access = new AccessControl($database);
    $user = $access->requireRole('organizer');
    $organizerId = $access->requireApprovedOrganizer((int) $user['id']);
    $service = new CategoryService($database, $access);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $uploadedImageKey = ImageStorage::storeUpload($_FILES['image'] ?? null);
            $input = $_POST;
            $input['image_key'] = $uploadedImageKey;
            $service->create(
                (int) ($_POST['event_id'] ?? 0),
                (int) $user['id'],
                $user['roles'],
                $input
            );
        } elseif ($action === 'update') {
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $uploadedImageKey = ImageStorage::storeUpload($_FILES['image'] ?? null);
            $input = $_POST;
            $input['image_key'] = $uploadedImageKey;
            $service->update(
                $categoryId,
                (int) $user['id'],
                $user['roles'],
                $input
            );
        } elseif ($action === 'deactivate') {
            $service->deactivate(
                (int) ($_POST['category_id'] ?? 0),
                (int) $user['id'],
                $user['roles']
            );
        } else {
            throw new InvalidArgumentException('Choose a supported category action.');
        }

        header('Location: categories.php?saved=1', true, 303);
        exit;
    }

    if (isset($_GET['saved'])) {
        $successMessage = 'The category change was saved.';
    }
    if (isset($_GET['edit'])) {
        $categoryToEdit = $service->findForManagement(
            (int) $_GET['edit'],
            (int) $user['id'],
            $user['roles']
        );
    }

    $categories = $service->listForOrganizer($organizerId);
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
} catch (PDOException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    error_log('WInVote organizer category database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Category management is temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    $errorMessage = $exception->getMessage();
}

$isEditing = is_array($categoryToEdit);
?>
<?php organizer_page_header('Manage categories', 'categories', $user); ?>
  <main class="content-section"><div class="container">
    <div class="section-heading"><div><h1>Manage categories</h1><p>Create categories for your events, including approved events, before voting starts.</p></div><a class="btn btn-secondary" href="events.php">Your events</a></div>
    <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= category_escape($errorMessage) ?></div><?php endif; ?>
    <?php if ($successMessage !== null): ?><div class="notice" role="status"><?= category_escape($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage === null): ?>
      <div class="detail-layout">
        <section class="panel">
          <h2><?= $isEditing ? 'Edit category' : 'Add a category' ?></h2>
          <?php if ($events === []): ?>
            <p>Create an event before adding categories. Approved events remain editable until voting starts.</p>
          <?php else: ?>
            <form method="post" action="categories.php" enctype="multipart/form-data">
              <input type="hidden" name="_token" value="<?= category_escape(csrf_token()) ?>">
              <input type="hidden" name="action" value="<?= $isEditing ? 'update' : 'create' ?>">
              <?php if ($isEditing): ?>
                <input type="hidden" name="category_id" value="<?= (int) $categoryToEdit['id'] ?>">
                <div class="field"><label>Event</label><input class="input" value="<?= category_escape((string) $categoryToEdit['event_name']) ?>" disabled></div>
              <?php else: ?>
                <div class="field"><label for="event_id">Event</label><select class="select" style="width:100%" id="event_id" name="event_id" required>
                  <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>" <?= $requestedEventId !== false && $requestedEventId !== null && (int) $event['id'] === $requestedEventId ? 'selected' : '' ?>><?= category_escape($event['name']) ?> · <?= category_escape(str_replace('_', ' ', $event['event_status'])) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <?php endif; ?>
              <div class="field"><label for="name">Category name</label><input class="input" id="name" name="name" required maxlength="140" value="<?= category_escape((string) ($categoryToEdit['name'] ?? '')) ?>"></div>
              <div class="field"><label for="description">Description (optional)</label><textarea class="textarea" id="description" name="description" rows="3" maxlength="10000"><?= category_escape((string) ($categoryToEdit['description'] ?? '')) ?></textarea></div>
              <div class="field"><label for="image">Category image (optional)</label><input class="input" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"><span class="field-hint">JPEG, PNG or WebP · maximum 2 MB. Leave empty to keep the current image.</span>
                <?php if (!empty($categoryToEdit['image_key'])): ?><img class="upload-preview" src="../image.php?key=<?= rawurlencode((string) $categoryToEdit['image_key']) ?>" alt="Current category image"><?php endif; ?>
              </div>
              <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save category' : 'Add category' ?></button>
              <?php if ($isEditing): ?><a class="btn btn-secondary" href="categories.php">Cancel</a><?php endif; ?>
            </form>
          <?php endif; ?>
        </section>
        <aside class="panel"><h2>Voting rules</h2><p>Event voting mode and limits are configured with the event. Categories organize the nominees and results, but cannot override event-wide rules.</p><p class="field-hint">You can manage categories before voting starts. Existing results and categories are locked once voting begins.</p></aside>
      </div>
      <section class="panel" style="margin-top:18px">
        <div class="section-heading"><div><h2>All categories</h2><p>Categories are retained as inactive instead of being permanently deleted.</p></div></div>
        <?php if ($categories === []): ?>
          <div class="empty-state"><div class="empty-icon">◇</div><strong>No categories yet</strong><p>Add a category to an event draft.</p></div>
        <?php else: ?>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>Category</th><th>Event</th><th>Contestants</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($categories as $category): ?>
              <tr>
                <td><?php if (!empty($category['image_key'])): ?><img class="table-image" src="../image.php?key=<?= rawurlencode((string) $category['image_key']) ?>" alt=""><?php endif; ?><span class="table-primary"><?= category_escape($category['name']) ?></span><span class="table-sub"><?= category_escape((string) ($category['description'] ?? '')) ?></span></td>
                <td><?= category_escape($category['event_name']) ?><span class="table-sub"><?= category_escape(str_replace('_', ' ', $category['event_status'])) ?></span></td>
                <td><?= (int) $category['contestant_count'] ?></td>
                <td><span class="pill"><?= category_escape($category['category_status']) ?></span></td>
                <td>
                  <?php
                    $canManageCategory = in_array($category['event_status'], ['draft', 'rejected'], true)
                        || (in_array($category['event_status'], ['published', 'paused'], true)
                            && new DateTimeImmutable($category['starts_at'], new DateTimeZone('UTC'))
                                > new DateTimeImmutable('now', new DateTimeZone('UTC')));
                  ?>
                  <?php if ($category['category_status'] === 'active' && $canManageCategory): ?>
                    <a class="table-action" href="categories.php?edit=<?= (int) $category['id'] ?>">Edit</a>
                    <form method="post" action="categories.php" style="display:inline">
                      <input type="hidden" name="_token" value="<?= category_escape(csrf_token()) ?>">
                      <input type="hidden" name="action" value="deactivate">
                      <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                      <button class="table-action" type="submit">Deactivate</button>
                    </form>
                  <?php elseif ($category['category_status'] === 'active'): ?>
                    <span class="field-hint">Locked while event is active</span>
                  <?php else: ?>
                    <span class="field-hint">Retained</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div></main>
<?php organizer_page_footer(); ?>
