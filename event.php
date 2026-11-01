<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function public_event_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
$details = null;
$errorMessage = null;
try {
    if ($eventId === false || $eventId === null || $eventId < 1) {
        http_response_code(400);
        throw new InvalidArgumentException('Choose a valid event.');
    }
    $database = database_connection();
    $details = (new EventService($database, new AccessControl($database)))
        ->publicEventDetails((int) $eventId);
    if ($details === null) {
        http_response_code(404);
        throw new DomainException('This event is not available for public viewing.');
    }
} catch (PDOException $exception) {
    error_log('WInVote public event detail database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Event details are temporarily unavailable. Please try again later.';
} catch (DomainException | InvalidArgumentException $exception) {
    $errorMessage = $exception->getMessage();
}

$event = is_array($details) ? $details['event'] : null;
$categories = is_array($details) ? $details['categories'] : [];
$nominationOpen = is_array($event)
    && $event['event_status'] === 'published'
    && $event['nomination_opens_at'] !== null
    && $event['nomination_closes_at'] !== null
    && strtotime((string) $event['nomination_opens_at'] . ' UTC') <= time()
    && strtotime((string) $event['nomination_closes_at'] . ' UTC') >= time();
$canVote = is_array($event)
    && $event['public_status'] === 'Live'
    && $event['event_status'] === 'published';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= is_array($event) ? public_event_escape($event['name']) : 'Event details' ?> — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <header class="site-header"><div class="container nav-inner">
    <a class="brand" href="index.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
    <button class="mobile-menu" type="button" aria-label="Toggle navigation" data-action="public-menu">☰</button>
    <nav class="public-nav"><a href="events.php">Explore events</a><a href="results.php">Results</a><a href="nominate.php">Nominations</a><a href="about.html">About</a></nav>
    <div class="nav-actions"><a class="btn btn-secondary btn-sm" href="auth.php?action=login">Log in</a><a class="btn btn-primary btn-sm" href="auth.php?action=register">Create account</a></div>
  </div></header>
  <main class="content-section"><div class="container">
    <?php if ($errorMessage !== null): ?>
      <section class="panel" role="alert"><h1>Event unavailable</h1><p><?= public_event_escape($errorMessage) ?></p><a class="btn btn-secondary" href="events.php">Return to Explore events</a></section>
    <?php elseif (is_array($event)): ?>
      <div class="section-heading"><div><span class="eyebrow"><?= public_event_escape($event['event_type']) ?> · <?= public_event_escape($event['public_status']) ?></span><h1><?= public_event_escape($event['name']) ?></h1><p><?= public_event_escape($event['organization_name']) ?></p></div><a class="btn btn-secondary" href="events.php">All events</a></div>
      <section class="panel event-detail-panel">
        <?php if (!empty($event['cover_image_key'])): ?><img class="result-event-image" src="image.php?key=<?= rawurlencode((string) $event['cover_image_key']) ?>" alt=""><?php endif; ?>
        <p><?= nl2br(public_event_escape($event['description'])) ?></p>
        <ul class="detail-list">
          <li><span>Voting status</span><strong><?= public_event_escape($event['public_status']) ?></strong></li>
          <li><span>Voting window (UTC)</span><strong><?= public_event_escape($event['starts_at']) ?> – <?= public_event_escape($event['ends_at']) ?></strong></li>
          <li><span>Voting mode</span><strong><?= public_event_escape(str_replace('_', ' ', $event['voting_mode'])) ?></strong></li>
          <li><span>Results</span><strong><?= (int) $event['has_public_results'] === 1 ? 'Public' : 'Not public yet' ?></strong></li>
        </ul>
        <div class="filter-row">
          <?php if ($canVote): ?><a class="btn btn-primary" href="vote.php?event_id=<?= (int) $event['id'] ?>">Vote in this event</a><?php endif; ?>
          <?php if ($nominationOpen): ?><a class="btn btn-secondary" href="nominate.php?event=<?= (int) $event['id'] ?>">Nominate someone</a><?php endif; ?>
          <?php if ((int) $event['has_public_results'] === 1): ?><a class="btn btn-secondary" href="results.php?event_id=<?= (int) $event['id'] ?>">View results</a><?php endif; ?>
        </div>
      </section>
      <div class="section-heading" style="margin-top:32px"><div><h2>Categories and nominees</h2><p>Active category and nominee records for this event.</p></div></div>
      <?php if ($categories === []): ?><section class="panel empty-state"><strong>No active categories yet</strong><p>Categories and nominees will be shown here when organizers add them.</p></section><?php endif; ?>
      <?php foreach ($categories as $category): ?>
        <section class="panel" style="margin-bottom:16px">
          <?php if (!empty($category['image_key'])): ?><img class="result-category-image" src="image.php?key=<?= rawurlencode((string) $category['image_key']) ?>" alt=""><?php endif; ?>
          <h2><?= public_event_escape($category['name']) ?></h2>
          <?php if (!empty($category['description'])): ?><p><?= nl2br(public_event_escape((string) $category['description'])) ?></p><?php endif; ?>
          <?php if ($category['contestants'] === []): ?><p class="field-hint">No active nominees in this category yet.</p><?php else: ?>
            <div class="contestant-grid"><?php foreach ($category['contestants'] as $contestant): ?>
              <article class="contestant-card">
                <?php if (!empty($contestant['image_key'])): ?><img class="result-person-image" src="image.php?key=<?= rawurlencode((string) $contestant['image_key']) ?>" alt=""><?php endif; ?>
                <div class="contestant-body"><a class="table-primary" href="contestant.php?contestant_id=<?= (int) $contestant['id'] ?>"><?= public_event_escape($contestant['name']) ?></a>
                  <?php if (!empty($contestant['biography'])): ?><p><?= public_event_escape((string) $contestant['biography']) ?></p><?php endif; ?>
                  <?php if ((int) $event['has_public_results'] === 1): ?><small><?= number_format((int) $contestant['vote_count']) ?> public votes</small><?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?></div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div></main>
  <script>
    document.querySelector("[data-action='public-menu']")?.addEventListener("click", () => {
      document.querySelector(".public-nav")?.classList.toggle("open");
    });
  </script>
</body>
</html>
