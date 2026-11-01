<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

$errorMessage = null;
$overview = [];
$activity = [];
$user = null;

try {
    [, $user, $service] = admin_page_context();
    $overview = $service->overview();
    $activity = $service->recentActivity();
} catch (PDOException $exception) {
    error_log('WInVote platform admin database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Platform administration is temporarily unavailable. Please try again later.';
} catch (RuntimeException $exception) {
    $errorMessage = $exception->getMessage();
}

admin_page_header('Platform overview', 'dashboard', $user);
?>
<?php if ($errorMessage !== null): ?>
  <div class="notice warning" role="alert"><?= admin_escape($errorMessage) ?></div>
<?php elseif ($overview !== []): ?>
  <div class="stat-grid">
    <a class="stat-card" href="organizers.php"><div class="stat-card-top">Pending organizers</div><strong class="stat-value"><?= (int) $overview['pending_organizers'] ?></strong><div class="stat-change">Review applications</div></a>
    <a class="stat-card" href="events.php"><div class="stat-card-top">Events awaiting review</div><strong class="stat-value"><?= (int) $overview['pending_events'] ?></strong><div class="stat-change">Review submissions</div></a>
    <a class="stat-card" href="users.php"><div class="stat-card-top">Active accounts</div><strong class="stat-value"><?= (int) $overview['active_users'] ?></strong><div class="stat-change"><?= (int) $overview['total_users'] ?> total users</div></a>
    <a class="stat-card" href="events.php"><div class="stat-card-top">Published events</div><strong class="stat-value"><?= (int) $overview['published_events'] ?></strong><div class="stat-change"><?= (int) $overview['total_events'] ?> total events</div></a>
    <a class="stat-card" href="organizers.php"><div class="stat-card-top">Approved organizers</div><strong class="stat-value"><?= (int) $overview['approved_organizers'] ?></strong><div class="stat-change"><?= (int) $overview['total_organizers'] ?> total organizers</div></a>
    <div class="stat-card"><div class="stat-card-top">Active nominees</div><strong class="stat-value"><?= (int) $overview['active_nominees'] ?></strong></div>
    <div class="stat-card"><div class="stat-card-top">Credited votes</div><strong class="stat-value"><?= (int) $overview['credited_votes'] ?></strong></div>
  </div>
  <section class="panel">
    <div class="section-heading"><div><h2>Recent platform activity</h2><p>Latest recorded administration actions.</p></div></div>
    <?php if ($activity === []): ?>
      <div class="empty-state"><strong>No activity recorded yet</strong></div>
    <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Action</th><th>Resource</th><th>Administrator</th><th>Time (UTC)</th><th>Outcome</th></tr></thead>
        <tbody><?php foreach ($activity as $item): ?>
          <tr>
            <td><?= admin_escape(str_replace('.', ' · ', $item['action'])) ?></td>
            <td><?= admin_escape($item['resource_type']) ?><?= $item['resource_id'] === null ? '' : ' #' . (int) $item['resource_id'] ?></td>
            <td><?= admin_escape($item['actor_name'] ?? 'System') ?></td>
            <td><?= admin_escape($item['created_at']) ?></td>
            <td><?= admin_escape($item['outcome']) ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </section>
  <p class="field-hint">Paid-voting totals are simulated in local development only; no real payments are collected.</p>
<?php endif; ?>
<?php admin_page_footer(); ?>
