<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function public_events_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$events = [];
$errorMessage = null;

try {
    $database = database_connection();
    $events = (new EventService($database, new AccessControl($database)))->listPublicEvents();
} catch (PDOException $exception) {
    error_log('WInVote public events database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Events are temporarily unavailable. Please try again later.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Explore events — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <header class="site-header"><div class="container nav-inner">
    <a class="brand" href="index.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
    <button class="mobile-menu" aria-label="Toggle navigation" data-action="public-menu">☰</button>
    <nav class="public-nav">
      <a href="events.php" aria-current="page">Explore events</a>
      <a href="results.php">Results</a>
      <a href="nominate.php">Nominations</a>
      <a href="about.html">About</a>
    </nav>
    <div class="nav-actions"><a class="btn btn-secondary btn-sm" href="auth.php?action=login">Log in</a><a class="btn btn-primary btn-sm" href="auth.php?action=register">Create account</a></div>
  </div></header>
  <main>
    <section class="page-hero"><div class="container"><div class="breadcrumb">HOME / EVENTS</div><h1>Find your next moment.</h1><p>Explore approved events, support nominees, and see results when organizers make them public.</p></div></section>
    <section class="content-section"><div class="container">
      <div class="filter-row">
        <label class="search-wrap"><span class="search-icon">⌕</span><input class="input" id="event-search" placeholder="Search events, organizers..." aria-label="Search events"></label>
        <select class="select" id="category-filter" aria-label="Filter by event type">
          <option value="">All event types</option>
          <?php
            $eventTypes = array_values(array_unique(array_map(
                static fn(array $event): string => (string) $event['event_type'],
                $events
            )));
            sort($eventTypes, SORT_NATURAL | SORT_FLAG_CASE);
          ?>
          <?php foreach ($eventTypes as $eventType): ?><option value="<?= public_events_escape($eventType) ?>"><?= public_events_escape($eventType) ?></option><?php endforeach; ?>
        </select>
        <select class="select" id="status-filter" aria-label="Filter by event status"><option value="">All statuses</option><option>Live</option><option>Upcoming</option><option>Completed</option></select>
      </div>
      <div class="tabs" role="tablist"><button class="tab active" data-status-tab="All">All events</button><button class="tab" data-status-tab="Live">Voting live</button><button class="tab" data-status-tab="Upcoming">Upcoming</button><button class="tab" data-status-tab="Completed">Completed</button></div>
      <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= public_events_escape($errorMessage) ?></div><?php endif; ?>
      <?php if ($errorMessage === null && $events === []): ?>
        <section class="panel empty-state"><div class="empty-icon">▦</div><strong>No approved events yet</strong><p>Events appear here after an organizer submits them and an administrator approves them.</p></section>
      <?php elseif ($events !== []): ?>
        <div class="event-grid" id="event-list">
          <?php foreach ($events as $event): ?>
            <?php
              $statusClass = match ($event['public_status']) {
                  'Live' => 'pill-live',
                  'Upcoming' => 'pill-upcoming',
                  default => 'pill-closed',
              };
              $eventDate = match ($event['public_status']) {
                  'Live' => 'Voting closes ' . gmdate('j M Y', strtotime((string) $event['ends_at'] . ' UTC')),
                  'Upcoming' => 'Starts ' . gmdate('j M Y', strtotime((string) $event['starts_at'] . ' UTC')),
                  default => 'Voting ended ' . gmdate('j M Y', strtotime((string) $event['ends_at'] . ' UTC')),
              };
            ?>
            <article class="event-card" data-status="<?= public_events_escape($event['public_status']) ?>" data-category="<?= public_events_escape($event['event_type']) ?>">
              <?php if (!empty($event['cover_image_key'])): ?>
                <div class="database-event-image">
                  <img src="image.php?key=<?= rawurlencode((string) $event['cover_image_key']) ?>" alt="">
                  <span class="event-kicker"><?= public_events_escape($event['event_type']) ?></span>
                  <h3><?= public_events_escape($event['name']) ?></h3>
                </div>
              <?php else: ?>
                <div class="event-art art-blue"><span class="event-kicker"><?= public_events_escape($event['event_type']) ?></span><h3><?= public_events_escape($event['name']) ?></h3></div>
              <?php endif; ?>
              <div class="event-card-body">
                <div class="event-meta"><span>◷ <?= public_events_escape($eventDate) ?></span><span>♙ <?= number_format((int) $event['nominee_count']) ?> nominees</span></div>
                <p class="event-description"><?= public_events_escape($event['description']) ?></p>
                <div class="event-card-bottom">
                  <span class="organizer-mini"><?= public_events_escape($event['organization_name']) ?>
                    <small><?= $event['public_vote_count'] === null ? 'Results not public' : number_format((int) $event['public_vote_count']) . ' public votes' ?></small>
                  </span>
                  <span class="pill <?= $statusClass ?>"><?= public_events_escape($event['public_status']) ?></span>
                </div>
                <div class="filter-row event-card-actions">
                  <a class="btn btn-secondary btn-sm" href="event.php?event_id=<?= (int) $event['id'] ?>">Details</a>
                  <?php if ($event['public_status'] === 'Live'): ?><a class="btn btn-primary btn-sm" href="vote.php?event_id=<?= (int) $event['id'] ?>">Vote now</a><?php endif; ?>
                  <?php if ((int) $event['has_public_results'] === 1): ?><a class="btn btn-secondary btn-sm" href="results.php?event_id=<?= (int) $event['id'] ?>">View results</a><?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <div class="empty-state" id="event-empty" hidden><div class="empty-icon">⌕</div><strong>No matching events</strong><p>Try a different search or filter.</p><button class="btn btn-secondary btn-sm" data-action="clear-filters">Clear filters</button></div>
      <?php endif; ?>
    </div></section>
  </main>
  <footer class="site-footer"><div class="container"><div class="footer-bottom"><span>© 2026 WInVote · Every voice deserves a fair way to be heard.</span><a href="support.html">Help & support</a></div></div></footer>
  <script>
    document.querySelector("[data-action='public-menu']")?.addEventListener("click", () => {
      document.querySelector(".public-nav")?.classList.toggle("open");
    });
    const escapeHtml = value => String(value).replace(/[&<>"']/g, character =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]
    );
    fetch("auth.php?action=status", {
      credentials: "same-origin",
      headers: { Accept: "application/json" }
    })
      .then(response => response.ok ? response.json() : Promise.reject(new Error(`Account status request failed (${response.status}).`)))
      .then(status => {
        if (!status.authenticated || !status.user) return;
        const user = status.user;
        const roles = Array.isArray(user.roles) ? user.roles : [];
        const isApprovedOrganizer = roles.includes("organizer") && user.organizer_status === "approved";
        const role = roles.includes("super_admin")
          ? "Super admin"
          : roles.includes("organizer")
            ? (isApprovedOrganizer ? "Approved organizer" : "Organizer · approval pending")
            : "WInVote account";
        const initial = Array.from(user.full_name || "W").slice(0, 1).join("").toUpperCase();
        const actions = document.querySelector(".nav-actions");
        if (!actions) return;
        actions.innerHTML = `<details class="account-menu"><summary class="account-trigger"><span class="user-avatar" aria-hidden="true">${escapeHtml(initial)}</span><span class="account-trigger-name">${escapeHtml(user.full_name)}</span><span aria-hidden="true">⌄</span></summary><div class="account-dropdown"><strong>${escapeHtml(user.full_name)}</strong><span class="account-email">${escapeHtml(user.email)}</span><span class="account-role">${role}</span>${isApprovedOrganizer ? '<a href="organizer/events.php">Manage events</a>' : ''}<a href="auth.php?action=account">Account details</a><form method="post" action="auth.php?action=logout"><input type="hidden" name="_token" value="${escapeHtml(status.csrf_token || "")}"><button type="submit">Sign out</button></form></div></details>`;
      })
      .catch(error => console.error("WInVote account navigation could not be updated:", error));
  </script>
</body>
</html>
