<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

$errorMessage = null;
$users = [];
$user = null;

try {
    [, $user, $service] = admin_page_context();
    $users = $service->allUsers();
} catch (PDOException $exception) {
    error_log('WInVote user administration database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'User directory is temporarily unavailable. Please try again later.';
} catch (RuntimeException $exception) {
    $errorMessage = $exception->getMessage();
}

admin_page_header('User directory', 'users', $user);
?>
<?php if ($errorMessage !== null): ?>
  <div class="notice warning" role="alert"><?= admin_escape($errorMessage) ?></div>
<?php else: ?>
  <section class="panel">
    <div class="section-heading"><div><h2>All users</h2><p>Most recent 100 user accounts. This directory is read-only; account changes are not available here.</p></div></div>
    <?php if ($users === []): ?>
      <div class="empty-state"><strong>No user accounts found.</strong></div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Organizer</th><th>Status</th><th>Last login (UTC)</th><th>Joined (UTC)</th></tr></thead>
      <tbody><?php foreach ($users as $account): ?>
        <tr>
          <td><?= admin_escape($account['full_name']) ?></td>
          <td><?= admin_escape($account['email']) ?></td>
          <td><?= admin_escape($account['roles'] ?? '—') ?></td>
          <td><?= admin_escape($account['organizer_status'] ?? '—') ?></td>
          <td><?= admin_escape($account['account_status']) ?></td>
          <td><?= admin_escape($account['last_login_at'] ?? '—') ?></td>
          <td><?= admin_escape($account['created_at']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php admin_page_footer(); ?>
