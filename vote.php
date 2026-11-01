<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function vote_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vote_request_id(mixed $value): int
{
    if (!is_string($value) && !is_int($value)) {
        return 0;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT);
    return $validated === false || $validated < 1 ? 0 : $validated;
}

$errorMessage = null;
$successMessage = null;
$user = $_SESSION['user'] ?? null;
$events = [];
$options = [];
$hasOpenEvents = false;
$payment = null;
$paymentSimulatorAvailable = PaymentSimulator::isAvailableForRequest();
$eventId = vote_request_id($_POST['event_id'] ?? $_GET['event_id'] ?? null);
$categoryId = vote_request_id($_POST['category_id'] ?? null);
$contestantId = vote_request_id($_POST['contestant_id'] ?? null);
$paymentReference = $_POST['payment_reference'] ?? $_GET['payment_ref'] ?? '';

try {
    $database = database_connection();
    $service = new VoteService($database);
    $paymentSimulator = new PaymentSimulator($database);
    $events = $service->listOpenEvents($paymentSimulatorAvailable);
    $options = $service->listBallotOptions($paymentSimulatorAvailable);
    $hasOpenEvents = $service->hasOpenEvents($paymentSimulatorAvailable);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['_token'] ?? null);
        if (!is_array($user)) {
            http_response_code(401);
            throw new RuntimeException('Sign in with a voter account before casting a vote.');
        }
        if (!in_array('voter', $user['roles'], true)) {
            http_response_code(403);
            throw new RuntimeException('A voter account is required to cast a vote.');
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'complete_simulated_payment') {
            $paymentReference = $_POST['payment_reference'] ?? '';
            $paymentResult = $_POST['payment_result'] ?? '';
            if (!is_string($paymentReference) || !is_string($paymentResult)) {
                throw new InvalidArgumentException('Choose a valid simulated payment result.');
            }
            $paymentSimulator->complete(
                $paymentReference,
                (int) $user['id'],
                $paymentResult
            );
            header(
                'Location: vote.php?payment_ref=' . rawurlencode($paymentReference),
                true,
                303
            );
            exit;
        }

        $quantityValue = $_POST['vote_quantity'] ?? null;
        if (!is_string($quantityValue) || !ctype_digit($quantityValue)) {
            throw new InvalidArgumentException('Choose a valid vote quantity.');
        }
        $idempotencyKey = $_POST['idempotency_key'] ?? null;
        if (!is_string($idempotencyKey)) {
            throw new InvalidArgumentException('The vote request could not be verified. Reload the page and try again.');
        }
        if ($action === 'start_simulated_payment') {
            $paymentReference = $paymentSimulator->start(
                $eventId,
                $categoryId,
                $contestantId,
                (int) $user['id'],
                (int) $quantityValue,
                $idempotencyKey
            );
            header(
                'Location: vote.php?event_id=' . $eventId
                    . '&payment_ref=' . rawurlencode($paymentReference),
                true,
                303
            );
            exit;
        }
        if ($action !== 'cast_vote') {
            throw new InvalidArgumentException('Choose a valid voting action.');
        }

        $outcome = $service->cast(
            $eventId,
            $categoryId,
            $contestantId,
            (int) $user['id'],
            (int) $quantityValue,
            $idempotencyKey
        );
        $castStatus = $outcome['already_recorded'] ? 'repeat' : '1';
        header('Location: vote.php?event_id=' . $eventId . '&cast=' . $castStatus, true, 303);
        exit;
    }

    if (isset($_GET['cast'])) {
        $successMessage = $_GET['cast'] === 'repeat'
            ? 'This vote was already recorded.'
            : 'Your vote has been recorded.';
    }
    if ($paymentSimulatorAvailable && is_string($paymentReference) && $paymentReference !== '') {
        if (!is_array($user) || !in_array('voter', $user['roles'] ?? [], true)) {
            throw new DomainException('Sign in with the voter account that started this payment.');
        }
        $payment = $paymentSimulator->findForVoter($paymentReference, (int) $user['id']);
        if ($payment === null) {
            throw new DomainException('The simulated payment could not be found.');
        }
        if ($payment['transaction_status'] === 'successful') {
            $successMessage = 'The simulated payment succeeded. Votes were credited once.';
        } elseif ($payment['transaction_status'] === 'failed') {
            $successMessage = 'The simulated payment failed. No votes were credited.';
        }
    }
} catch (PDOException $exception) {
    error_log('WInVote vote database error: ' . $exception->getMessage());
    http_response_code(503);
    $errorMessage = 'Voting is temporarily unavailable. Please try again later.';
} catch (InvalidArgumentException | DomainException | RuntimeException $exception) {
    $errorMessage = $exception->getMessage();
}

$selectedEvent = null;
foreach ($events as $event) {
    if ((int) $event['id'] === $eventId) {
        $selectedEvent = $event;
        break;
    }
}
$isRestricted = is_array($selectedEvent) && $selectedEvent['voting_mode'] === 'restricted_single';
$canVote = is_array($user)
    && is_array($user['roles'] ?? null)
    && in_array('voter', $user['roles'], true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cast your vote — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <script defer src="assets/js/voting.js"></script>
</head>
<body>
  <header class="site-header"><div class="container nav-inner">
    <a class="brand" href="index.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
    <nav class="public-nav" style="display:flex"><a href="events.php">Explore events</a><a href="results.php">Results</a><a href="nominate.php">Nominations</a></nav>
    <div class="nav-actions"><?php if ($canVote): ?><span><?= vote_escape((string) $user['full_name']) ?></span><?php else: ?><a class="btn btn-primary btn-sm" href="auth.php?action=login">Log in</a><?php endif; ?></div>
  </div></header>
  <main class="content-section"><div class="container">
    <div class="section-heading"><div><span class="eyebrow">WInVote · VOTING</span><h1>Make your vote count</h1><p>Choose an open event and cast a vote using its published rules. Times are shown in UTC.</p></div></div>
    <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= vote_escape($errorMessage) ?></div><?php endif; ?>
    <?php if ($successMessage !== null): ?><div class="notice" role="status"><?= vote_escape($successMessage) ?></div><?php endif; ?>
    <?php if ($paymentSimulatorAvailable): ?>
      <div class="notice warning" role="note">Development simulator only: no money is collected and this is not a real payment.</div>
    <?php endif; ?>
    <?php if (!$canVote): ?>
      <section class="panel"><h2>Sign in as a voter</h2><p>You need an active voter account to cast a ballot.</p><a class="btn btn-primary" href="auth.php?action=login">Log in</a> <a class="btn btn-secondary" href="auth.php?action=register">Create voter account</a></section>
    <?php elseif ($events === [] || $options === []): ?>
      <section class="panel">
        <h2><?= $hasOpenEvents ? 'Open events are not ready for voting yet' : 'No ballots are open' ?></h2>
        <?php if ($hasOpenEvents): ?>
          <p>There are published events in their voting windows, but none has an active category with an assigned active nominee. Organizers must finish ballot setup before voters can cast a vote.</p>
        <?php else: ?>
          <p>Published events with active categories and nominees appear here during their voting windows.</p>
        <?php endif; ?>
        <a class="btn btn-secondary" href="events.php">Explore events</a>
      </section>
    <?php else: ?>
      <?php if (is_array($payment)): ?>
        <section class="panel" aria-labelledby="simulated-checkout-title">
          <span class="eyebrow">LOCAL SIMULATION · NO REAL PAYMENT</span>
          <h2 id="simulated-checkout-title">Simulated checkout</h2>
          <p><?= vote_escape((string) $payment['event_name']) ?> · <?= vote_escape((string) $payment['category_name']) ?> · <?= vote_escape((string) $payment['display_name']) ?></p>
          <p><?= (int) $payment['vote_quantity'] ?> vote<?= (int) $payment['vote_quantity'] === 1 ? '' : 's' ?> at <?= vote_escape((string) $payment['currency']) ?> <?= vote_escape((string) $payment['unit_price']) ?> each · Total: <?= vote_escape((string) $payment['currency']) ?> <?= vote_escape((string) $payment['amount']) ?></p>
          <p>Current status: <strong><?= vote_escape((string) $payment['transaction_status']) ?></strong></p>
          <?php if ($payment['transaction_status'] === 'pending'): ?>
            <form method="post" action="vote.php">
              <input type="hidden" name="_token" value="<?= vote_escape(csrf_token()) ?>">
              <input type="hidden" name="payment_reference" value="<?= vote_escape((string) $payment['merchant_reference']) ?>">
              <input type="hidden" name="action" value="complete_simulated_payment">
              <button class="btn btn-primary" type="submit" name="payment_result" value="success">Simulate successful payment</button>
              <button class="btn btn-secondary" type="submit" name="payment_result" value="failure">Simulate failed payment</button>
            </form>
          <?php endif; ?>
        </section>
      <?php endif; ?>
      <section class="detail-layout">
        <form class="panel" method="post" action="vote.php">
          <h2>Your selection</h2>
          <input type="hidden" name="_token" value="<?= vote_escape(csrf_token()) ?>">
          <input type="hidden" name="idempotency_key" value="<?= vote_escape(bin2hex(random_bytes(32))) ?>">
          <div class="field"><label for="event_id">Event</label><select class="select" style="width:100%" id="event_id" name="event_id" required>
            <option value="">Choose an open event</option>
            <?php foreach ($events as $event): ?>
              <option value="<?= (int) $event['id'] ?>" <?= (int) $event['id'] === $eventId ? 'selected' : '' ?>
                data-voting-mode="<?= vote_escape($event['voting_mode']) ?>"
                data-price="<?= vote_escape((string) ($event['price_per_vote'] ?? '0.00')) ?>"
                data-currency="<?= vote_escape((string) ($event['currency'] ?? 'GHS')) ?>"
                data-max-votes="<?= $event['max_votes_per_voter'] === null ? '' : (int) $event['max_votes_per_voter'] ?>">
                <?= vote_escape($event['name']) ?> · <?php if ($event['voting_mode'] === 'restricted_single'): ?>one vote per category<?php elseif ($event['voting_mode'] === 'paid_multiple'): ?><?= vote_escape((string) $event['currency']) ?> <?= vote_escape((string) $event['price_per_vote']) ?> per vote (simulated)<?php else: ?>free voting<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select></div>
          <div class="field"><label for="category_id">Category</label><select class="select" style="width:100%" id="category_id" name="category_id" required>
            <option value="">Choose a category</option>
            <?php foreach ($options as $option): ?>
              <option value="<?= (int) $option['category_id'] ?>"
                data-event-id="<?= (int) $option['event_id'] ?>"
                <?= (int) $option['category_id'] === $categoryId ? 'selected' : '' ?>>
                <?= vote_escape($option['category_name']) ?>
              </option>
            <?php endforeach; ?>
          </select></div>
          <div class="field"><label for="contestant_id">Contestant</label><select class="select" style="width:100%" id="contestant_id" name="contestant_id" required>
            <option value="">Choose a contestant</option>
            <?php foreach ($options as $option): ?>
              <option value="<?= (int) $option['contestant_id'] ?>"
                data-event-id="<?= (int) $option['event_id'] ?>"
                data-category-id="<?= (int) $option['category_id'] ?>"
                <?= (int) $option['contestant_id'] === $contestantId ? 'selected' : '' ?>>
                <?= vote_escape($option['display_name']) ?> · <?= vote_escape($option['category_name']) ?>
              </option>
            <?php endforeach; ?>
          </select></div>
          <div class="field" id="quantity-field" <?= $isRestricted ? 'hidden' : '' ?>>
            <label for="vote_quantity">Number of votes</label>
            <input class="input" type="number" id="vote_quantity" name="vote_quantity" min="1" max="10000" value="1" required>
            <?php if (is_array($selectedEvent) && $selectedEvent['max_votes_per_voter'] !== null): ?>
              <span class="field-hint">Event limit: <?= (int) $selectedEvent['max_votes_per_voter'] ?> total vote<?= (int) $selectedEvent['max_votes_per_voter'] === 1 ? '' : 's' ?> per account.</span>
            <?php else: ?>
              <span class="field-hint">Votes are counted toward the event-wide limit if one is configured.</span>
            <?php endif; ?>
          </div>
          <div class="notice warning" id="paid-voting-note" hidden>Development simulator only. No money is collected.</div>
          <button class="btn btn-primary" id="cast-vote-button" type="submit" name="action" value="cast_vote">Cast vote</button>
          <?php if ($paymentSimulatorAvailable): ?><button class="btn btn-primary" id="simulate-payment-button" type="submit" name="action" value="start_simulated_payment" hidden>Continue to simulated checkout</button><?php endif; ?>
          <p class="field-hint">Your account is automatically eligible for free voting. Duplicate ballots in restricted elections are rejected.</p>
        </form>
        <aside class="panel"><h2>Voting fairly</h2><p>Your vote is saved once to WInVote. Published results are calculated from credited votes and follow each event's visibility rules.</p><?php if ($paymentSimulatorAvailable): ?><p>Paid-vote checkout on this development server is simulated only. No money is collected.</p><?php else: ?><p>Paid voting is disabled until a real payment provider can be securely verified.</p><?php endif; ?><a class="text-link" href="results.php">View available results →</a></aside>
      </section>
    <?php endif; ?>
  </div></main>
</body>
</html>
