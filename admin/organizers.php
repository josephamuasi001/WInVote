<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

$errorMessage = null;
$successMessage = null;
$organizers = [];
$allOrganizers = [];
$user = null;

try {
    [, $user, $service] = admin_page_context();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        $service->reviewOrganizer(
            admin_post_integer('organizer_id'),
            (int) $user['id'],
            admin_post_string('decision')
        );
        header('Location: organizers.php?reviewed=1', true, 303);
        exit;
    }

    if (isset($_GET['reviewed'])) {
        $successMessage = 'The organizer review was saved and added to the audit log.';
    }
    $organizers = $service->pendingOrganizers();
    $allOrganizers = $service->allOrganizers();
} catch (PDOException $exception) {
    error_log('WInVote organizer administration database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Organizer review is temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    $errorMessage = $exception->getMessage();
}

admin_page_header('Organizer approvals', 'organizers', $user);
?>
<?php if ($errorMessage !== null): ?>
  <div class="notice warning" role="alert"><?= admin_escape($errorMessage) ?></div>
<?php endif; ?>
<?php if ($successMessage !== null): ?>
  <div class="notice" role="status"><?= admin_escape($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage === null): ?>
  <section class="panel">
    <div class="section-heading"><div><h2>Pending applications</h2><p>Approve or reject new organizer registrations.</p></div></div>
    <?php if ($organizers === []): ?>
      <div class="empty-state"><strong>No organizer applications are awaiting review.</strong><p>New organizer applications will appear here.</p></div>
    <?php else: ?>
    <?php foreach ($organizers as $organizer): ?>
    <article class="panel" style="margin-bottom:14px">
      <div class="section-heading">
        <div><h2><?= admin_escape($organizer['organization_name']) ?></h2><p><?= admin_escape($organizer['full_name']) ?> · <?= admin_escape($organizer['email']) ?></p></div>
        <span class="pill pill-upcoming">Pending</span>
      </div>
      <ul class="detail-list">
        <li><span>Organization type</span><strong><?= admin_escape($organizer['organization_type'] ?? 'Not provided') ?></strong></li>
        <li><span>Support email</span><strong><?= admin_escape($organizer['support_email'] ?? 'Not provided') ?></strong></li>
        <li><span>Support phone</span><strong><?= admin_escape($organizer['support_phone'] ?? 'Not provided') ?></strong></li>
        <li><span>Applied</span><strong><?= admin_escape($organizer['created_at']) ?> UTC</strong></li>
      </ul>
      <form method="post" class="hero-actions">
        <input type="hidden" name="_token" value="<?= admin_escape(csrf_token()) ?>">
        <input type="hidden" name="organizer_id" value="<?= (int) $organizer['organizer_id'] ?>">
        <button class="btn btn-primary" type="submit" name="decision" value="approved">Approve organizer</button>
        <button class="btn btn-secondary" type="submit" name="decision" value="rejected">Reject application</button>
      </form>
    </article>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>
  <section class="panel" style="margin-top:18px">
    <div class="section-heading"><div><h2>All organizers</h2><p>Most recent 100 organizer profiles from the database.</p></div></div>
    <?php if ($allOrganizers === []): ?>
      <div class="empty-state"><strong>No organizer profiles found.</strong></div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Organization</th><th>Owner</th><th>Email</th><th>Account</th><th>Approval</th><th>Events</th><th>Created</th></tr></thead>
      <tbody><?php foreach ($allOrganizers as $organizer): ?>
        <tr>
          <td><?= admin_escape($organizer['organization_name']) ?><span class="table-sub"><?= admin_escape($organizer['organization_type'] ?? 'Organization') ?></span></td>
          <td><?= admin_escape($organizer['full_name']) ?></td>
          <td><?= admin_escape($organizer['email']) ?></td>
          <td><?= admin_escape($organizer['account_status']) ?></td>
          <td><?= admin_escape($organizer['approval_status']) ?></td>
          <td><?= (int) $organizer['event_count'] ?></td>
          <td><?= admin_escape($organizer['created_at']) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php admin_page_footer(); ?>
