<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

function nomination_organizer_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!isset($_SESSION['user'])) {
    header('Location: ../auth.php?action=login', true, 303);
    exit;
}

$errorMessage = null;
$successMessage = null;
$nominations = [];
$user = $_SESSION['user'] ?? null;

try {
    $database = database_connection();
    $access = new AccessControl($database);
    $user = $access->requireRole('organizer');
    $organizerId = $access->requireApprovedOrganizer((int) $user['id']);
    $service = new NominationService($database, $access);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        $service->review(
            (int) ($_POST['nomination_id'] ?? 0),
            (int) $user['id'],
            $user['roles'],
            (string) ($_POST['decision'] ?? ''),
            $_POST['review_notes'] ?? ''
        );
        header('Location: nominations.php?reviewed=1', true, 303);
        exit;
    }

    if (isset($_GET['reviewed'])) {
        $successMessage = 'The nomination review was saved.';
    }
    $nominations = $service->listForOrganizer($organizerId);
} catch (PDOException $exception) {
    error_log('WInVote organizer nomination database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Nomination review is temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    $errorMessage = $exception->getMessage();
}
?>
<?php organizer_page_header('Review nominations', 'nominations', $user); ?>
  <main class="content-section"><div class="container">
    <div class="section-heading"><div><h1>Review nominations</h1><p>Only review applications submitted to events owned by your approved organizer profile.</p></div><a class="btn btn-secondary" href="events.php">Your events</a></div>
    <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= nomination_organizer_escape($errorMessage) ?></div><?php endif; ?>
    <?php if ($successMessage !== null): ?><div class="notice" role="status"><?= nomination_organizer_escape($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage === null && $nominations === []): ?>
      <div class="panel empty-state"><div class="empty-icon">✳</div><strong>No nominations yet</strong><p>Applications for your events will appear here.</p><a class="text-link" href="../nominate.php">View the public nomination page →</a></div>
    <?php elseif ($errorMessage === null): ?>
      <?php foreach ($nominations as $nomination): ?>
        <article class="panel" style="margin-bottom:14px">
          <div class="section-heading"><div><h2><?= nomination_organizer_escape($nomination['nominee_name']) ?></h2><p><?= nomination_organizer_escape($nomination['event_name']) ?> · <?= nomination_organizer_escape($nomination['category_name']) ?></p></div><span class="pill"><?= nomination_organizer_escape(str_replace('_', ' ', $nomination['nomination_status'])) ?></span></div>
          <?php if (!empty($nomination['profile_image_key'])): ?><img class="upload-preview" src="../image.php?key=<?= rawurlencode((string) $nomination['profile_image_key']) ?>" alt="Nominee photo submitted for review"><?php endif; ?>
          <div class="detail-layout">
            <div><p><strong>Nominated by:</strong> <?= nomination_organizer_escape($nomination['applicant_name']) ?> · <?= nomination_organizer_escape($nomination['applicant_email']) ?></p><p><strong>Submitted:</strong> <?= nomination_organizer_escape($nomination['created_at']) ?></p>
              <p><strong>Nominee email:</strong> <?= nomination_organizer_escape((string) ($nomination['nominee_email'] ?? 'Not provided')) ?></p>
              <p><?= nl2br(nomination_organizer_escape((string) ($nomination['biography'] ?? 'No nomination details provided.'))) ?></p>
              <?php if (!empty($nomination['review_notes'])): ?><div class="notice"><?= nl2br(nomination_organizer_escape($nomination['review_notes'])) ?></div><?php endif; ?>
            </div>
            <div>
              <?php if (in_array($nomination['nomination_status'], ['pending', 'needs_information'], true)): ?>
                <form method="post" action="nominations.php">
                  <input type="hidden" name="_token" value="<?= nomination_organizer_escape(csrf_token()) ?>">
                  <input type="hidden" name="nomination_id" value="<?= (int) $nomination['id'] ?>">
                  <div class="field"><label for="review_notes_<?= (int) $nomination['id'] ?>">Review note (required when requesting information)</label><textarea class="textarea" id="review_notes_<?= (int) $nomination['id'] ?>" name="review_notes" rows="2" maxlength="5000"></textarea></div>
                  <div class="filter-row">
                    <button class="btn btn-primary btn-sm" name="decision" value="approved" type="submit">Approve & add contestant</button>
                    <button class="btn btn-secondary btn-sm" name="decision" value="needs_information" type="submit">Request information</button>
                    <button class="btn btn-quiet btn-sm" name="decision" value="rejected" type="submit">Reject</button>
                  </div>
                </form>
              <?php else: ?>
                <p class="field-hint">This application has already been reviewed.</p>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div></main>
<?php organizer_page_footer(); ?>
