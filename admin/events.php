<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/_common.php';

$errorMessage = null;
$successMessage = null;
$events = [];
$allEvents = [];
$user = null;

try {
    [, $user, $service] = admin_page_context();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        $service->reviewEvent(
            admin_post_integer('event_id'),
            (int) $user['id'],
            admin_post_string('decision')
        );
        header('Location: events.php?reviewed=1', true, 303);
        exit;
    }

    if (isset($_GET['reviewed'])) {
        $successMessage = 'The event review was saved and added to the audit log.';
    }
    $events = $service->pendingEvents();
    $allEvents = $service->allEvents();
} catch (PDOException $exception) {
    error_log('WInVote event administration database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Event review is temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    $errorMessage = $exception->getMessage();
}

admin_page_header('Event review', 'events', $user);
?>
<?php if ($errorMessage !== null): ?>
  <div class="notice warning" role="alert"><?= admin_escape($errorMessage) ?></div>
<?php endif; ?>
<?php if ($successMessage !== null): ?>
  <div class="notice" role="status"><?= admin_escape($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage === null): ?>
  <section class="panel">
    <div class="section-heading"><div><h2>Pending submissions</h2><p>Events submitted by approved organizers.</p></div></div>
    <?php if ($events === []): ?>
      <div class="empty-state"><strong>No event submissions are awaiting review.</strong><p>Events submitted by approved organizers will appear here.</p></div>
    <?php else: ?>
    <?php foreach ($events as $event): ?>
    <article class="panel" style="margin-bottom:14px">
      <div class="section-heading">
        <div><h2><?= admin_escape($event['name']) ?></h2><p><?= admin_escape($event['organization_name']) ?> · <?= admin_escape($event['full_name']) ?> · <?= admin_escape($event['email']) ?></p></div>
        <span class="pill pill-upcoming">Pending review</span>
      </div>
      <p><?= nl2br(admin_escape($event['description'])) ?></p>
      <ul class="detail-list">
        <li><span>Type</span><strong><?= admin_escape($event['event_type']) ?></strong></li>
        <li><span>Voting mode</span><strong><?= admin_escape(str_replace('_', ' ', $event['voting_mode'])) ?></strong></li>
        <li><span>Active categories</span><strong><?= (int) $event['category_count'] ?></strong></li>
        <li><span>Assigned active nominees</span><strong><?= (int) $event['nominee_count'] ?></strong></li>
        <li><span>Vote price</span><strong><?= admin_escape($event['currency']) ?> <?= admin_escape((string) $event['price_per_vote']) ?></strong></li>
        <li><span>Voting period</span><strong><?= admin_escape($event['starts_at']) ?> – <?= admin_escape($event['ends_at']) ?> UTC</strong></li>
      </ul>
      <?php if ((int) $event['nominee_count'] < 1): ?>
        <div class="notice warning">This event needs at least one active category with an assigned active nominee before it can be published.</div>
      <?php endif; ?>
      <?php if ($event['voting_mode'] === 'paid_multiple'): ?>
        <div class="notice warning">Paid events can be published only on this local server with the development simulator enabled. The simulator does not collect money.</div>
      <?php endif; ?>
      <form method="post" class="hero-actions">
        <input type="hidden" name="_token" value="<?= admin_escape(csrf_token()) ?>">
        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
        <button class="btn btn-primary" type="submit" name="decision" value="approved" <?= ((int) $event['nominee_count'] < 1 || ($event['voting_mode'] === 'paid_multiple' && !PaymentSimulator::isAvailableForRequest())) ? 'disabled' : '' ?>>Approve and publish</button>
        <button class="btn btn-secondary" type="submit" name="decision" value="rejected">Reject submission</button>
      </form>
    </article>
    <?php endforeach; ?>
    <?php endif; ?>
  </section>
  <section class="panel" style="margin-top:18px">
    <div class="section-heading"><div><h2>All events</h2><p>Most recent 100 event records with current ballot and credited-vote totals.</p></div></div>
    <?php if ($allEvents === []): ?>
      <div class="empty-state"><strong>No events found.</strong></div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Event</th><th>Organizer</th><th>Status</th><th>Ballot setup</th><th>Voting period (UTC)</th><th>Votes</th></tr></thead>
      <tbody><?php foreach ($allEvents as $event): ?>
        <tr>
          <td><?= admin_escape($event['name']) ?><span class="table-sub"><?= admin_escape($event['event_type']) ?> · <?= admin_escape(str_replace('_', ' ', $event['voting_mode'])) ?></span></td>
          <td><?= admin_escape($event['organization_name']) ?><span class="table-sub"><?= admin_escape($event['organizer_name']) ?></span></td>
          <td><?= admin_escape(str_replace('_', ' ', $event['event_status'])) ?></td>
          <td><?= (int) $event['category_count'] ?> categories<span class="table-sub"><?= (int) $event['nominee_count'] ?> nominees</span></td>
          <td><?= admin_escape($event['starts_at']) ?><span class="table-sub">to <?= admin_escape($event['ends_at']) ?></span></td>
          <td><?= (int) $event['credited_votes'] ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php admin_page_footer(); ?>
