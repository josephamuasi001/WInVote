<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function public_contestant_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$contestantId = filter_var($_GET['contestant_id'] ?? null, FILTER_VALIDATE_INT);
$contestant = null;
$errorMessage = null;
try {
    if ($contestantId === false || $contestantId === null || $contestantId < 1) {
        http_response_code(400);
        throw new InvalidArgumentException('Choose a valid nominee.');
    }
    $database = database_connection();
    $contestant = (new EventService($database, new AccessControl($database)))
        ->publicContestantDetails((int) $contestantId);
    if ($contestant === null) {
        http_response_code(404);
        throw new DomainException('This nominee is not available for public viewing.');
    }
} catch (PDOException $exception) {
    error_log('WInVote public nominee database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Nominee details are temporarily unavailable. Please try again later.';
} catch (DomainException | InvalidArgumentException $exception) {
    $errorMessage = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= is_array($contestant) ? public_contestant_escape($contestant['display_name']) : 'Nominee profile' ?> — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <?= render_public_header(is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : null, csrf_token(), '') ?>
  <main class="content-section"><div class="container">
    <?php if ($errorMessage !== null): ?>
      <section class="panel" role="alert"><h1>Nominee unavailable</h1><p><?= public_contestant_escape($errorMessage) ?></p><a class="btn btn-secondary" href="events.php">Explore events</a></section>
    <?php elseif (is_array($contestant)): ?>
      <section class="panel contestant-profile">
        <?php if (!empty($contestant['profile_image_key'])): ?><img class="contestant-profile-image" src="image.php?key=<?= rawurlencode((string) $contestant['profile_image_key']) ?>" alt=""><?php else: ?><div class="contestant-profile-portrait portrait"><?= public_contestant_escape(mb_strtoupper(mb_substr((string) $contestant['display_name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div><?php endif; ?>
        <div class="contestant-profile-content">
          <span class="eyebrow"><?= public_contestant_escape($contestant['category_name']) ?> · <?= public_contestant_escape($contestant['event_name']) ?></span>
          <h1><?= public_contestant_escape($contestant['display_name']) ?></h1>
          <p><?= nl2br(public_contestant_escape((string) ($contestant['biography'] ?? ''))) ?></p>
          <?php if ($contestant['public_vote_count'] !== null): ?><p><strong><?= number_format((int) $contestant['public_vote_count']) ?></strong> public votes</p><?php endif; ?>
          <div class="filter-row">
            <a class="btn btn-secondary" href="event.php?event_id=<?= (int) $contestant['event_id'] ?>">View event</a>
            <?php if ($contestant['public_status'] === 'Live' && $contestant['event_status'] === 'published'): ?><a class="btn btn-primary" href="vote.php?event_id=<?= (int) $contestant['event_id'] ?>">Vote in this event</a><?php endif; ?>
            <?php if ((int) $contestant['has_public_results'] === 1): ?><a class="btn btn-secondary" href="results.php?event_id=<?= (int) $contestant['event_id'] ?>">View results</a><?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </div></main>
</body>
</html>
