<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function home_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$events = [];
$errorMessage = null;
try {
    $database = database_connection();
    $events = (new EventService($database, new AccessControl($database)))->listPublicEvents();
} catch (PDOException $exception) {
    error_log('WInVote homepage database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Event information is temporarily unavailable. Please try again later.';
}

$liveEvents = array_values(array_filter(
    $events,
    static fn (array $event): bool => $event['public_status'] === 'Live'
));
$upcomingEvents = array_values(array_filter(
    $events,
    static fn (array $event): bool => $event['public_status'] === 'Upcoming'
));
$featuredEvent = $liveEvents[0] ?? $upcomingEvents[0] ?? $events[0] ?? null;
$nomineeCount = array_sum(array_map(
    static fn (array $event): int => (int) $event['nominee_count'],
    $events
));
$publicVoteCount = array_sum(array_map(
    static fn (array $event): int => $event['public_vote_count'] === null
        ? 0
        : (int) $event['public_vote_count'],
    $events
));
$publicVoteEventCount = count(array_filter(
    $events,
    static fn (array $event): bool => $event['public_vote_count'] !== null
));
$user = $_SESSION['user'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WInVote — Every voice deserves a fair way to be heard</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <header class="site-header"><div class="container nav-inner">
    <a class="brand" href="index.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
    <button class="mobile-menu" type="button" aria-label="Toggle navigation" data-action="public-menu">☰</button>
    <nav class="public-nav">
      <a href="events.php">Explore events</a>
      <a href="results.php">Results</a>
      <a href="nominate.php">Nominations</a>
      <a href="about.html">About</a>
    </nav>
    <div class="nav-actions">
      <?php if (is_array($user)): ?>
        <?php
          $roles = is_array($user['roles'] ?? null) ? $user['roles'] : [];
          $roleLabel = in_array('super_admin', $roles, true)
              ? 'Super admin'
              : (in_array('organizer', $roles, true) ? 'Organizer' : 'WInVote account');
        ?>
        <?= render_account_menu($user, csrf_token(), '', $roleLabel) ?>
        <?php if (in_array('super_admin', $roles, true)): ?><a class="btn btn-secondary btn-sm" href="admin/index.php">Admin</a><?php endif; ?>
        <?php if (in_array('organizer', $roles, true) && ($user['organizer_status'] ?? null) === 'approved'): ?><a class="btn btn-secondary btn-sm" href="organizer/events.php">Manage events</a><?php endif; ?>
      <?php else: ?>
        <a class="btn btn-secondary btn-sm" href="auth.php?action=login">Log in</a>
        <a class="btn btn-primary btn-sm" href="auth.php?action=register">Create account</a>
      <?php endif; ?>
    </div>
  </div></header>
  <main>
    <section class="hero"><div class="container hero-grid"><div>
      <span class="eyebrow">A better way to celebrate every voice</span>
      <h1>Where every vote<br>finds its <span>moment.</span></h1>
      <p class="hero-copy">Discover published events, support nominees, and follow results when organizers make them public.</p>
      <div class="hero-actions"><a class="btn btn-primary" href="events.php">Explore events <span aria-hidden="true">→</span></a><a class="btn btn-secondary" href="auth.php?action=register">Host an event</a></div>
      <div class="hero-proof"><span>Event listings and counts on this page come from the database.</span></div>
    </div>
      <?php if (is_array($featuredEvent)): ?>
        <div class="hero-preview">
          <div class="preview-top"><span><?= home_escape($featuredEvent['public_status']) ?> event</span><span class="live-dot"><?= home_escape($featuredEvent['public_status']) ?></span></div>
          <a class="preview-event-link" href="event.php?event_id=<?= (int) $featuredEvent['id'] ?>">
            <div class="preview-event"><?php if (!empty($featuredEvent['cover_image_key'])): ?><img src="image.php?key=<?= rawurlencode((string) $featuredEvent['cover_image_key']) ?>" alt=""><?php endif; ?>
              <strong><small><?= home_escape($featuredEvent['event_type']) ?> · <?= home_escape($featuredEvent['organization_name']) ?></small><?= home_escape($featuredEvent['name']) ?></strong>
            </div>
          </a>
          <div class="preview-stats">
            <div class="preview-stat"><strong><?= number_format((int) $featuredEvent['nominee_count']) ?></strong><small>Nominees</small></div>
            <div class="preview-stat"><strong><?= $featuredEvent['public_vote_count'] === null ? '—' : number_format((int) $featuredEvent['public_vote_count']) ?></strong><small><?= $featuredEvent['public_vote_count'] === null ? 'Votes private' : 'Public votes' ?></small></div>
            <div class="preview-stat"><strong><?= home_escape(gmdate('j M', strtotime((string) $featuredEvent['ends_at'] . ' UTC'))) ?></strong><small>Voting ends</small></div>
          </div>
        </div>
      <?php else: ?>
        <div class="hero-preview"><div class="preview-top"><span>Event discovery</span></div><div class="panel"><h2>No public events yet</h2><p>Approved events will appear here when they are published.</p><a class="btn btn-primary btn-sm" href="auth.php?action=register">Organize an event</a></div></div>
      <?php endif; ?>
    </div></section>
    <section class="section"><div class="container">
      <div class="section-heading"><div><h2>Public events</h2><p>Published events and their current status, loaded from your database.</p></div><a class="text-link" href="events.php">View all events →</a></div>
      <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= home_escape($errorMessage) ?></div><?php endif; ?>
      <?php if ($events === [] && $errorMessage === null): ?>
        <section class="panel empty-state"><strong>No public events yet</strong><p>Events appear after they have been reviewed and published.</p></section>
      <?php elseif ($events !== []): ?>
        <div class="event-grid"><?php foreach (array_slice($events, 0, 3) as $event): ?>
          <?php $statusClass = $event['public_status'] === 'Live' ? 'pill-live' : ($event['public_status'] === 'Upcoming' ? 'pill-upcoming' : 'pill-closed'); ?>
          <article class="event-card">
            <a class="database-event-link" href="event.php?event_id=<?= (int) $event['id'] ?>">
              <?php if (!empty($event['cover_image_key'])): ?><div class="database-event-image"><img src="image.php?key=<?= rawurlencode((string) $event['cover_image_key']) ?>" alt=""><span class="event-kicker"><?= home_escape($event['event_type']) ?></span><h3><?= home_escape($event['name']) ?></h3></div><?php else: ?><div class="event-art art-blue"><span class="event-kicker"><?= home_escape($event['event_type']) ?></span><h3><?= home_escape($event['name']) ?></h3></div><?php endif; ?>
            </a>
            <div class="event-card-body"><div class="event-meta"><span><?= home_escape($event['organization_name']) ?></span><span><?= number_format((int) $event['nominee_count']) ?> nominees</span></div>
              <div class="event-card-bottom"><span class="organizer-mini"><?= home_escape($event['name']) ?><small><?= $event['public_vote_count'] === null ? 'Results not public' : number_format((int) $event['public_vote_count']) . ' public votes' ?></small></span><span class="pill <?= $statusClass ?>"><?= home_escape($event['public_status']) ?></span></div>
            </div>
          </article>
        <?php endforeach; ?></div>
      <?php endif; ?>
    </div></section>
    <section class="trust-band"><div class="container trust-row">
      <div class="trust-item"><strong><?= number_format(count($events)) ?></strong><span>Public events</span></div>
      <div class="trust-item"><strong><?= number_format($nomineeCount) ?></strong><span>Active nominees</span></div>
      <div class="trust-item"><strong><?= $publicVoteEventCount === 0 ? '—' : number_format($publicVoteCount) ?></strong><span><?= $publicVoteEventCount === 0 ? 'Public vote totals unavailable' : 'Public votes across visible results' ?></span></div>
      <div class="trust-item"><strong><?= number_format(count($liveEvents)) ?></strong><span>Events voting now</span></div>
    </div></section>
  </main>
  <footer class="site-footer"><div class="container"><div class="footer-bottom"><span>WInVote · Every voice deserves a fair way to be heard.</span><a href="support.html">Help & support</a></div></div></footer>
  <script>
    document.querySelector("[data-action='public-menu']")?.addEventListener("click", () => {
      document.querySelector(".public-nav")?.classList.toggle("open");
    });
  </script>
</body>
</html>
