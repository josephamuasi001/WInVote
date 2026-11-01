<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function results_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
$events = [];
$event = null;
$results = [];
$errorMessage = null;

try {
    $service = new VoteService(database_connection());
    if (!array_key_exists('event_id', $_GET)) {
        $events = $service->listPublicResults();
    } elseif ($eventId !== false && $eventId !== null && $eventId > 0) {
        $resultData = $service->publicResults((int) $eventId);
        $event = $resultData['event'];
        $results = $resultData['results'];
    } else {
        throw new InvalidArgumentException('Choose a valid event to view results.');
    }
} catch (PDOException $exception) {
    error_log('WInVote results database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Results are temporarily unavailable. Please try again later.';
} catch (DomainException | InvalidArgumentException $exception) {
    http_response_code($exception instanceof DomainException ? 404 : 400);
    $errorMessage = $exception->getMessage();
}

$groupedResults = [];
foreach ($results as $row) {
    $categoryId = (int) $row['category_id'];
    $groupedResults[$categoryId]['name'] = $row['category_name'];
    $groupedResults[$categoryId]['image_key'] = $row['category_image_key'];
    $groupedResults[$categoryId]['contestants'][] = $row;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= is_array($event) ? results_escape($event['name']) . ' results' : 'Event results' ?> — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <header class="site-header"><div class="container nav-inner">
    <a class="brand" href="index.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
    <nav class="public-nav" style="display:flex"><a href="events.php">Explore events</a><a href="vote.php">Vote</a><a href="nominate.php">Nominations</a></nav>
  </div></header>
  <main class="content-section"><div class="container">
    <div class="section-heading"><div><span class="eyebrow">WInVote · RESULTS</span><h1><?= is_array($event) ? results_escape($event['name']) : 'Published event results' ?></h1>
      <p><?= is_array($event) ? 'Server-calculated standings · ' . results_escape(str_replace('_', ' ', $event['result_visibility'])) : 'Only results made public by event visibility rules are shown.' ?></p></div>
      <?php if (is_array($event)): ?><a class="btn btn-secondary" href="results.php">All results</a><?php endif; ?>
    </div>
    <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= results_escape($errorMessage) ?></div><?php endif; ?>
    <?php if (is_array($event)): ?>
      <?php if ($groupedResults === []): ?><section class="panel"><p>No published contestant results are available for this event.</p></section><?php endif; ?>
      <?php foreach ($groupedResults as $group): ?>
        <?php $totalVotes = array_sum(array_map(static fn(array $row): int => (int) $row['vote_count'], $group['contestants'])); ?>
        <section class="panel" style="margin-bottom:18px"><?php if (!empty($group['image_key'])): ?><img class="result-category-image" src="image.php?key=<?= rawurlencode((string) $group['image_key']) ?>" alt=""><?php endif; ?><h2><?= results_escape($group['name']) ?></h2>
          <?php foreach ($group['contestants'] as $index => $row): ?>
            <?php $count = (int) $row['vote_count']; $percentage = $totalVotes === 0 ? 0 : (int) round($count / $totalVotes * 100); ?>
            <div class="result-row"><span class="rank"><?= $index + 1 ?></span><?php if (!empty($row['profile_image_key'])): ?><img class="result-person-image" src="image.php?key=<?= rawurlencode((string) $row['profile_image_key']) ?>" alt=""><?php endif; ?><strong><?= results_escape($row['display_name']) ?></strong><span><?= number_format($count) ?></span><div class="bar"><span style="width:<?= $percentage ?>%"></span></div><span><?= $percentage ?>%</span></div>
          <?php endforeach; ?>
          <p class="field-hint">Showing credited votes only. Reversed votes are excluded.</p>
        </section>
      <?php endforeach; ?>
    <?php elseif ($errorMessage === null && $events === []): ?>
      <section class="panel"><h2>No public results yet</h2><p>Results will appear after an event enables live visibility or its voting period closes.</p></section>
    <?php elseif ($errorMessage === null): ?>
      <div class="event-grid"><?php foreach ($events as $resultEvent): ?>
        <article class="event-card"><?php if (!empty($resultEvent['cover_image_key'])): ?><img class="result-event-image" src="image.php?key=<?= rawurlencode((string) $resultEvent['cover_image_key']) ?>" alt=""><?php else: ?><div class="event-art art-blue"><span class="event-kicker">PUBLIC RESULTS</span><h3><?= results_escape($resultEvent['name']) ?></h3></div><?php endif; ?>
          <div class="event-card-body"><div class="event-meta"><span><?= results_escape(str_replace('_', ' ', $resultEvent['result_visibility'])) ?></span></div>
            <div class="event-card-bottom"><span class="organizer-mini"><?= results_escape(str_replace('_', ' ', $resultEvent['event_status'])) ?><small>Voting ends <?= results_escape($resultEvent['ends_at']) ?> UTC</small></span><a class="btn btn-primary btn-sm" href="results.php?event_id=<?= (int) $resultEvent['id'] ?>">View results</a></div>
          </div></article>
      <?php endforeach; ?></div>
    <?php endif; ?>
  </div></main>
</body>
</html>
