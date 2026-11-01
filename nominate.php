<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function nomination_public_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errorMessage = null;
$successMessage = null;
$openEvents = [];
$categories = [];
$uploadedImageKey = null;
$selectedEventValue = $_POST['event_id'] ?? $_GET['event'] ?? 0;
$selectedEventId = (is_string($selectedEventValue) || is_int($selectedEventValue))
    && ctype_digit((string) $selectedEventValue)
    ? (int) $selectedEventValue
    : 0;

try {
    $database = database_connection();
    $service = new NominationService($database, new AccessControl($database));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        $uploadedImageKey = ImageStorage::storeUpload($_FILES['profile_image'] ?? null);
        $input = $_POST;
        $input['profile_image_key'] = $uploadedImageKey;
        $service->submit(
            isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null,
            $input
        );
        $uploadedImageKey = null;
        $successMessage = 'Your nomination was submitted for organizer review.';
    }

    $openEvents = $service->openEvents();
    if ($selectedEventId === 0 && $openEvents !== []) {
        $selectedEventId = (int) $openEvents[0]['id'];
    }
    foreach ($openEvents as $event) {
        foreach ($service->activeCategories((int) $event['id']) as $category) {
            $categories[] = $category;
        }
    }
    $categoryEventIds = array_values(array_unique(array_map(
        static fn (array $category): int => (int) $category['event_id'],
        $categories
    )));
    if (!in_array($selectedEventId, $categoryEventIds, true)) {
        $selectedEventId = $categoryEventIds[0] ?? 0;
    }
} catch (PDOException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    error_log('WInVote public nomination database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Nominations are temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    if ($uploadedImageKey !== null) {
        ImageStorage::delete($uploadedImageKey);
    }
    $errorMessage = $exception->getMessage();
}

$previous = static fn (string $key): string => nomination_public_escape((string) ($_POST[$key] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Submit a nomination — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <header class="site-header"><div class="container nav-inner">
    <a class="brand" href="index.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
    <nav class="public-nav" style="display:flex"><a href="events.php">Explore events</a><a href="results.php">Results</a><a href="support.html">Support</a></nav>
  </div></header>
  <main><section class="page-hero"><div class="container"><div class="breadcrumb">HOME / NOMINATIONS</div><h1>Know someone who deserves the spotlight?</h1><p>Share a nomination for an event that is currently accepting applications. An organizer will review it before any contestant profile is created.</p></div></section>
    <section class="content-section"><div class="container detail-layout">
      <section class="form-card">
        <h2>Nomination application</h2>
        <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= nomination_public_escape($errorMessage) ?></div><?php endif; ?>
        <?php if ($successMessage !== null): ?><div class="notice" role="status"><?= nomination_public_escape($successMessage) ?></div><?php endif; ?>
        <?php if ($openEvents === [] || $categories === []): ?>
          <div class="empty-state"><div class="empty-icon">✳</div><strong><?= $openEvents === [] ? 'No open nominations' : 'No nomination categories available' ?></strong><p>Check back when an event opens its nomination period and eligible categories.</p></div>
        <?php else: ?>
          <form method="post" action="nominate.php" enctype="multipart/form-data">
            <input type="hidden" name="_token" value="<?= nomination_public_escape(csrf_token()) ?>">
            <div class="field"><label for="event_id">Event</label><select class="select" style="width:100%" id="event_id" name="event_id" required>
              <?php foreach ($openEvents as $event): ?>
                <option value="<?= (int) $event['id'] ?>" <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>><?= nomination_public_escape($event['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
            <div class="field"><label for="category_id">Category</label><select class="select" style="width:100%" id="category_id" name="category_id" required>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" data-event-id="<?= (int) $category['event_id'] ?>" <?= (int) $category['event_id'] === $selectedEventId ? 'selected' : '' ?>><?= nomination_public_escape($category['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
            <div class="field"><label for="applicant_name">Your name</label><input class="input" id="applicant_name" name="applicant_name" required maxlength="160" value="<?= $previous('applicant_name') ?>"></div>
            <div class="field"><label for="applicant_email">Your email</label><input class="input" type="email" id="applicant_email" name="applicant_email" required maxlength="254" value="<?= $previous('applicant_email') ?>"></div>
            <div class="field"><label for="nominee_name">Nominee's full name</label><input class="input" id="nominee_name" name="nominee_name" required maxlength="160" value="<?= $previous('nominee_name') ?>"></div>
            <div class="field"><label for="nominee_email">Nominee's email (optional)</label><input class="input" type="email" id="nominee_email" name="nominee_email" maxlength="254" value="<?= $previous('nominee_email') ?>"></div>
            <div class="field"><label for="biography">Why are you nominating them?</label><textarea class="textarea" id="biography" name="biography" rows="5" maxlength="20000"><?= $previous('biography') ?></textarea></div>
            <div class="field"><label for="profile_image">Nominee photo (optional)</label><input class="input" type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp"><span class="field-hint">Only upload an image you have permission to share. JPEG, PNG or WebP · maximum 2 MB.</span></div>
            <div class="notice warning">Supporting document uploads are not enabled yet. Do not send sensitive documents through this form.</div>
            <button class="btn btn-primary" type="submit">Submit nomination</button>
          </form>
        <?php endif; ?>
      </section>
      <aside class="panel"><h2>What happens next?</h2><ul class="detail-list">
        <li><span>1</span><strong>Organizer reviews your details</strong></li>
        <li><span>2</span><strong>They may request more information</strong></li>
        <li><span>3</span><strong>Approved nominees join the event</strong></li>
      </ul><p class="field-hint" style="margin-top:12px">A submitted application is not automatically approved or added to voting.</p></aside>
    </div></section>
  </main>
  <script>
    const nominationEvent = document.querySelector('#event_id');
    const nominationCategory = document.querySelector('#category_id');
    if (nominationEvent && nominationCategory) {
      const filterCategories = () => {
        [...nominationCategory.options].forEach((option) => {
          option.hidden = option.dataset.eventId !== nominationEvent.value;
        });
        if (nominationCategory.selectedOptions[0]?.hidden) {
          nominationCategory.selectedIndex = [...nominationCategory.options].findIndex((option) => !option.hidden);
        }
      };
      nominationEvent.addEventListener('change', () => {
        filterCategories();
      });
      filterCategories();
    }
  </script>
</body>
</html>
