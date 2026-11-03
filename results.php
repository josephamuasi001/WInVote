<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function results_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function results_nominee_colors(array $contestants): array
{
    $palette = [
        '#E6B800', '#3563E9', '#D94F70', '#168B83', '#9B59B6', '#E67E22',
        '#2878A8', '#C44536', '#6574CD', '#4D8B31', '#B04A9B', '#6B7280',
        '#008C95', '#CB6D35', '#3C6E71', '#8A5A44',
    ];
    $sortedIds = array_map(
        'intval',
        array_column($contestants, 'contestant_id')
    );
    sort($sortedIds, SORT_NUMERIC);
    $colors = [];
    $assignedColors = [];
    foreach ($sortedIds as $contestantId) {
        foreach ($contestants as $contestant) {
            $storedColor = (string) ($contestant['nominee_color'] ?? '');
            if ((int) $contestant['contestant_id'] === $contestantId
                && preg_match('/^#[0-9A-Fa-f]{6}$/D', $storedColor) === 1
                && !in_array(strtoupper($storedColor), $assignedColors, true)) {
                $colors[$contestantId] = strtoupper($storedColor);
                $assignedColors[] = strtoupper($storedColor);
                break;
            }
        }
    }

    $colorIndex = 0;
    foreach ($sortedIds as $contestantId) {
        if (isset($colors[$contestantId])) {
            continue;
        }
        $name = '';
        foreach ($contestants as $contestant) {
            if ((int) $contestant['contestant_id'] === $contestantId) {
                $name = (string) $contestant['display_name'];
                break;
            }
        }
        if (preg_match('/^joseph(?:\s|$)/u', mb_strtolower(trim($name), 'UTF-8')) === 1
            && !in_array('#E6B800', $assignedColors, true)) {
            $colors[$contestantId] = '#E6B800';
            $assignedColors[] = '#E6B800';
            continue;
        }
        do {
            $color = $colorIndex < count($palette)
                ? $palette[$colorIndex]
                : 'hsl(' . (($colorIndex * 137) % 360) . ' 68% 45%)';
            $colorIndex++;
        } while (in_array($color, $assignedColors, true) || $color === '#E6B800');
        $colors[$contestantId] = $color;
        $assignedColors[] = $color;
    }

    return $colors;
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
    $groupedResults[$categoryId]['category_color'] = $row['category_color'];
    $groupedResults[$categoryId]['category_status'] = $row['category_status'];
    $groupedResults[$categoryId]['contestants'][] = $row;
}
foreach ($groupedResults as &$group) {
    $nomineeColors = results_nominee_colors($group['contestants']);
    foreach ($group['contestants'] as &$contestant) {
        $contestant['nominee_color'] = $nomineeColors[(int) $contestant['contestant_id']];
        $contestant['nominee_color_name'] = match ($contestant['nominee_color']) {
            '#E6B800' => 'Yellow',
            '#3563E9' => 'Blue',
            '#D94F70' => 'Rose',
            '#168B83' => 'Teal',
            '#9B59B6' => 'Purple',
            '#E67E22' => 'Orange',
            '#2878A8' => 'Sky blue',
            '#C44536' => 'Red',
            '#6574CD' => 'Indigo',
            '#4D8B31' => 'Green',
            '#B04A9B' => 'Magenta',
            '#6B7280' => 'Gray',
            '#008C95' => 'Cyan',
            '#CB6D35' => 'Copper',
            '#3C6E71' => 'Slate teal',
            '#8A5A44' => 'Brown',
            default => $row['nominee_color'],
        };
    }
    unset($contestant);
}
unset($group);
$results = [];
foreach ($groupedResults as $group) {
    array_push($results, ...$group['contestants']);
}
$currentTimestamp = time();
$eventStartTimestamp = is_array($event) ? strtotime((string) $event['starts_at'] . ' UTC') : false;
$eventEndTimestamp = is_array($event) ? strtotime((string) $event['ends_at'] . ' UTC') : false;
$isLiveResults = is_array($event)
    && $event['event_status'] === 'published'
    && $event['result_visibility'] === 'live'
    && $eventStartTimestamp !== false
    && $eventEndTimestamp !== false
    && $currentTimestamp >= $eventStartTimestamp
    && $currentTimestamp < $eventEndTimestamp;

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($errorMessage !== null) {
        echo json_encode(['error' => $errorMessage], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    } else {
        echo json_encode(
            ['results' => $results, 'is_live' => $isLiveResults],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= is_array($event) ? results_escape($event['name']) . ' results' : 'Event results' ?> — WInVote</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <?php if (is_array($event) && $groupedResults !== []): ?><script defer src="assets/js/results.js"></script><?php endif; ?>
</head>
<body>
  <?= render_public_header(is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : null, csrf_token(), 'results.php') ?>
  <main class="content-section"><div class="container"<?= is_array($event) ? ' data-results-event="' . (int) $event['id'] . '" data-live-results="' . ($isLiveResults ? 'true' : 'false') . '"' : '' ?>>
    <div class="section-heading"><div><span class="eyebrow">WInVote · RESULTS</span><h1><?= is_array($event) ? results_escape($event['name']) : 'Published event results' ?></h1>
      <p><?= is_array($event) ? 'Server-calculated standings · ' . results_escape(str_replace('_', ' ', $event['result_visibility'])) : 'Only results made public by event visibility rules are shown.' ?></p></div>
      <?php if (is_array($event)): ?><a class="btn btn-secondary" href="results.php">All results</a><?php endif; ?>
    </div>
    <?php if ($errorMessage !== null): ?><div class="notice warning" role="alert"><?= results_escape($errorMessage) ?></div><?php endif; ?>
    <?php if (is_array($event)): ?>
      <?php if ($groupedResults === []): ?><section class="panel"><p>No published contestant results are available for this event.</p></section><?php endif; ?>
      <?php if ($groupedResults !== []): ?>
        <section class="results-view-controls" aria-label="Results display">
          <span>View results as</span>
          <div class="results-view-toggle" role="group" aria-label="Choose results display">
            <button type="button" class="btn btn-sm" data-results-view="numbers" aria-pressed="true">Numbers</button>
            <button type="button" class="btn btn-sm" data-results-view="curve" aria-pressed="false">Curve graph</button>
          </div>
          <?php if ($isLiveResults): ?><span class="results-live-indicator"><span aria-hidden="true"></span>Live · updates every 5 seconds</span><?php endif; ?>
        </section>
      <?php endif; ?>
      <?php foreach ($groupedResults as $group): ?>
        <?php $totalVotes = array_sum(array_map(static fn(array $row): int => (int) $row['vote_count'], $group['contestants'])); ?>
        <section class="panel results-category-panel" data-results-category="<?= (int) $group['contestants'][0]['category_id'] ?>" style="--category-color:<?= results_escape($group['category_color']) ?>"><?php if (!empty($group['image_key'])): ?><img class="result-category-image" src="image.php?key=<?= rawurlencode((string) $group['image_key']) ?>" alt=""><?php endif; ?><h2><?= results_escape($group['name']) ?></h2><?php if ($group['category_status'] !== 'active'): ?><span class="category-status-badge">Archived category · historical votes</span><?php endif; ?>
          <ul class="results-legend" aria-label="Nominee color key">
            <?php foreach ($group['contestants'] as $row): ?>
              <li class="results-legend-item" style="--nominee-color:<?= results_escape($row['nominee_color']) ?>">
                <span class="results-legend-swatch" aria-hidden="true"></span>
                <span><?= results_escape($row['display_name']) ?> — <?= results_escape($row['nominee_color_name']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <div class="results-curve-view" hidden data-results-curve aria-label="<?= results_escape($group['name']) ?> nominee vote curve"></div>
          <div class="results-numeric-view" data-results-numbers>
          <?php foreach ($group['contestants'] as $index => $row): ?>
            <?php $count = (int) $row['vote_count']; $percentage = $totalVotes === 0 ? 0 : (int) round($count / $totalVotes * 100); ?>
            <div class="result-row" data-result-nominee="<?= (int) $row['contestant_id'] ?>" data-result-count="<?= $count ?>" data-result-color="<?= results_escape($row['nominee_color']) ?>" data-result-image="<?= empty($row['profile_image_key']) ? '' : results_escape('image.php?key=' . rawurlencode((string) $row['profile_image_key'])) ?>" style="--nominee-color:<?= results_escape($row['nominee_color']) ?>"><span class="rank"><?= $index + 1 ?></span><?php if (!empty($row['profile_image_key'])): ?><img class="result-person-image" src="image.php?key=<?= rawurlencode((string) $row['profile_image_key']) ?>" alt=""><?php endif; ?><strong data-result-name><?= results_escape($row['display_name']) ?><?= $row['contestant_status'] !== 'active' ? ' · inactive' : '' ?></strong><span data-result-votes><?= number_format($count) ?></span><div class="bar"><span data-result-bar style="width:<?= $percentage ?>%"></span></div><span data-result-percentage><?= $percentage ?>%</span></div>
          <?php endforeach; ?>
          <p class="field-hint">Showing credited votes only. Reversed votes are excluded.</p>
          </div>
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
