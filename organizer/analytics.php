<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

function organizer_analytics_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!isset($_SESSION['user'])) {
    header('Location: ../auth.php?action=login', true, 303);
    exit;
}

$errorMessage = null;
$events = [];
$report = null;
$user = $_SESSION['user'] ?? null;

try {
    $database = database_connection();
    $access = new AccessControl($database);
    $user = $access->requireRole('organizer');
    $organizerId = $access->requireApprovedOrganizer((int) $user['id']);
    $eventService = new EventService($database, $access);
    $events = $eventService->listForOrganizer($organizerId);

    if (array_key_exists('event_id', $_GET)) {
        $eventId = filter_var($_GET['event_id'], FILTER_VALIDATE_INT);
        if ($eventId === false || $eventId < 1) {
            throw new InvalidArgumentException('Choose a valid event.');
        }
        $report = (new OrganizerAnalyticsService($database, $access))
            ->eventInsights((int) $eventId, (int) $user['id'], $user['roles']);
    }
} catch (PDOException $exception) {
    error_log('WInVote organizer analytics database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Event insights are temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    if (http_response_code() < 400) {
        http_response_code($exception instanceof InvalidArgumentException ? 400 : 404);
    }
    $errorMessage = $exception->getMessage();
}

$groupedStandings = [];
if (is_array($report)) {
    foreach ($report['standings'] as $row) {
        $categoryId = (int) $row['category_id'];
        $groupedStandings[$categoryId]['name'] = (string) $row['category_name'];
        $groupedStandings[$categoryId]['image_key'] = $row['category_image_key'];
        $groupedStandings[$categoryId]['contestants'][] = $row;
    }
}
$maxDailyVotes = is_array($report)
    ? max(1, ...array_column($report['trend'], 'vote_count'))
    : 1;
$canManageNominees = is_array($report)
    && (in_array($report['event']['event_status'], ['draft', 'rejected'], true)
        || (in_array($report['event']['event_status'], ['published', 'paused'], true)
            && new DateTimeImmutable($report['event']['starts_at'], new DateTimeZone('UTC'))
                > new DateTimeImmutable('now', new DateTimeZone('UTC'))));
?>
<?php if (is_array($report)): ?>
    <?php organizer_page_header(
        (string) $report['event']['name'] . ' insights',
        'analytics',
        $user,
        true
    ); ?>
    <main class="content-section"><div class="container">
      <div class="section-heading">
        <div><span class="eyebrow">ORGANIZER EVENT REPORT</span><h1><?= organizer_analytics_escape((string) $report['event']['name']) ?></h1>
          <p><span class="pill"><?= organizer_analytics_escape(str_replace('_', ' ', (string) $report['event']['event_status'])) ?></span>
            · Voting: <?= organizer_analytics_escape((string) $report['event']['starts_at']) ?> to <?= organizer_analytics_escape((string) $report['event']['ends_at']) ?> UTC</p></div>
        <div class="filter-row">
          <a class="btn btn-secondary" href="analytics.php">All event insights</a>
          <?php if ($canManageNominees): ?><a class="btn btn-primary" href="contestants.php?event_id=<?= (int) $report['event']['id'] ?>">Manage nominees</a><?php endif; ?>
        </div>
      </div>
      <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= organizer_analytics_escape($errorMessage) ?></div><?php endif; ?>
      <div class="notice" role="status">Private organizer report. Results refresh every minute and are not affected by public results visibility.</div>
      <?php if ($report['event']['voting_mode'] === 'paid_multiple'): ?>
        <div class="notice warning" role="note">Payment totals are successful local simulations only—not real funds received, withdrawable balances, or net revenue.</div>
      <?php endif; ?>
      <section class="insight-stat-grid" aria-label="Event totals">
        <article class="panel insight-stat"><span>Credited votes</span><strong><?= number_format((int) $report['totals']['total_votes']) ?></strong><small>Reversed votes excluded</small></article>
        <article class="panel insight-stat"><span>Unique voters</span><strong><?= number_format((int) $report['totals']['unique_voters']) ?></strong><small>Distinct voter accounts</small></article>
        <article class="panel insight-stat"><span>Vote records</span><strong><?= number_format((int) $report['totals']['ballot_records']) ?></strong><small>Credited records</small></article>
        <article class="panel insight-stat"><span>Simulated gross</span><strong><?= organizer_analytics_escape((string) $report['event']['currency']) ?> <?= number_format((float) $report['payments']['simulated_gross'], 2) ?></strong><small><?= (int) $report['payments']['successful_transactions'] ?> successful simulated payments</small></article>
      </section>

      <section class="panel insight-panel" aria-labelledby="insight-trend-title">
        <div class="section-heading"><div><h2 id="insight-trend-title">Voting activity</h2><p>Credited votes per day over the last 14 days (UTC).</p></div></div>
        <div class="insight-trend" role="img" aria-label="Daily credited votes: <?= organizer_analytics_escape(implode(', ', array_map(static fn(array $day): string => $day['label'] . ': ' . $day['vote_count'], $report['trend']))) ?>">
          <?php foreach ($report['trend'] as $day): ?>
            <?php $height = (int) round((int) $day['vote_count'] / $maxDailyVotes * 100); ?>
            <div class="insight-trend-column" title="<?= organizer_analytics_escape($day['label'] . ': ' . $day['vote_count'] . ' votes') ?>">
              <span class="insight-trend-count"><?= (int) $day['vote_count'] ?></span>
              <span class="insight-trend-bar" style="height:<?= $height ?>%"></span>
              <span class="insight-trend-label"><?= organizer_analytics_escape($day['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="insight-panel" aria-labelledby="insight-standings-title">
        <div class="section-heading"><div><h2 id="insight-standings-title">Live standings</h2><p>Percentages are calculated within each category from credited votes.</p></div></div>
        <?php if ($groupedStandings === []): ?>
          <div class="panel empty-state"><strong>No nominees to rank yet</strong><p>Add nominees before voting starts, or review public nominations.</p><a class="text-link" href="nominations.php">Review nominations →</a></div>
        <?php else: ?>
          <?php foreach ($groupedStandings as $group): ?>
            <?php $categoryTotal = array_sum(array_map(static fn(array $row): int => (int) $row['vote_count'], $group['contestants'])); ?>
            <article class="panel insight-category">
              <?php if (!empty($group['image_key'])): ?><img class="result-category-image" src="../image.php?key=<?= rawurlencode((string) $group['image_key']) ?>" alt=""><?php endif; ?>
              <h3><?= organizer_analytics_escape($group['name']) ?></h3>
              <?php foreach ($group['contestants'] as $rank => $row): ?>
                <?php
                  $voteCount = (int) $row['vote_count'];
                  $percentage = $categoryTotal === 0 ? 0 : round($voteCount / $categoryTotal * 100, 1);
                ?>
                <div class="insight-standing">
                  <span class="rank"><?= $rank + 1 ?></span>
                  <?php if (!empty($row['profile_image_key'])): ?><img class="result-person-image" src="../image.php?key=<?= rawurlencode((string) $row['profile_image_key']) ?>" alt=""><?php endif; ?>
                  <strong><?= organizer_analytics_escape((string) $row['display_name']) ?></strong>
                  <span><?= number_format($voteCount) ?> votes</span>
                  <div class="bar" role="progressbar" aria-label="<?= organizer_analytics_escape((string) $row['display_name']) ?> vote share" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percentage ?>"><span style="width:<?= number_format($percentage, 1, '.', '') ?>%"></span></div>
                  <strong><?= number_format($percentage, 1) ?>%</strong>
                </div>
              <?php endforeach; ?>
              <p class="field-hint">Category total: <?= number_format($categoryTotal) ?> credited votes.</p>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <div class="insight-summary-grid">
        <section class="panel"><h2>Nomination activity</h2>
          <ul class="detail-list">
            <li><span>Pending review</span><strong><?= (int) ($report['nominations']['pending'] ?? 0) ?></strong></li>
            <li><span>Needs information</span><strong><?= (int) ($report['nominations']['needs_information'] ?? 0) ?></strong></li>
            <li><span>Approved</span><strong><?= (int) ($report['nominations']['approved'] ?? 0) ?></strong></li>
            <li><span>Rejected</span><strong><?= (int) ($report['nominations']['rejected'] ?? 0) ?></strong></li>
          </ul>
          <a class="text-link" href="nominations.php">Review event nominations →</a>
        </section>
        <section class="panel"><h2>Event controls</h2>
          <p>Manage nominees until voting starts. Once voting is underway, nominee changes are locked to protect the standings.</p>
          <div class="filter-row"><?php if ($canManageNominees): ?><a class="btn btn-secondary btn-sm" href="contestants.php?event_id=<?= (int) $report['event']['id'] ?>">Manage nominees</a><?php endif; ?><a class="btn btn-secondary btn-sm" href="events.php">Manage event status</a></div>
          <?php if ((int) $report['payments']['pending_transactions'] > 0): ?><p class="field-hint"><?= (int) $report['payments']['pending_transactions'] ?> simulated payment<?= (int) $report['payments']['pending_transactions'] === 1 ? '' : 's' ?> still pending.</p><?php endif; ?>
        </section>
      </div>
    </div></main>
    <?php organizer_page_footer(); ?>
<?php else: ?>
  <?php organizer_page_header('Event insights', 'analytics', $user); ?>
    <main class="content-section"><div class="container">
      <div class="section-heading"><div><span class="eyebrow">ORGANIZER WORKSPACE</span><h1>Event insights</h1><p>Choose an event to view live standings, vote trends, nomination activity, and simulated payment totals.</p></div></div>
      <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= organizer_analytics_escape($errorMessage) ?></div><?php endif; ?>
      <?php if ($events === []): ?>
        <section class="panel empty-state"><strong>No events yet</strong><p>Create an event draft to start managing nominees and tracking results.</p><a class="btn btn-primary" href="events.php">Create an event</a></section>
      <?php else: ?>
        <div class="event-grid">
          <?php foreach ($events as $event): ?>
            <?php
              $canManageEventNominees = in_array($event['event_status'], ['draft', 'rejected'], true)
                  || (in_array($event['event_status'], ['published', 'paused'], true)
                      && new DateTimeImmutable($event['starts_at'], new DateTimeZone('UTC'))
                          > new DateTimeImmutable('now', new DateTimeZone('UTC')));
            ?>
            <article class="panel insight-event-card">
              <div><span class="pill"><?= organizer_analytics_escape(str_replace('_', ' ', $event['event_status'])) ?></span><h2><?= organizer_analytics_escape($event['name']) ?></h2><p><?= organizer_analytics_escape(str_replace('_', ' ', $event['voting_mode'])) ?></p></div>
              <div class="filter-row"><a class="btn btn-primary btn-sm" href="analytics.php?event_id=<?= (int) $event['id'] ?>">View insights</a><?php if ($canManageEventNominees): ?><a class="btn btn-secondary btn-sm" href="contestants.php?event_id=<?= (int) $event['id'] ?>">Manage nominees</a><?php endif; ?></div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div></main>
  <?php organizer_page_footer(); ?>
<?php endif; ?>
