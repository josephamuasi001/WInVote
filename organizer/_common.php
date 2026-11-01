<?php
declare(strict_types=1);

function organizer_page_header(
    string $title,
    string $activePage,
    ?array $user,
    bool $refreshEveryMinute = false
): void
{
    $navigation = [
        'events' => ['Your events', 'events.php', '▦'],
        'categories' => ['Categories', 'categories.php', '▤'],
        'contestants' => ['Nominees', 'contestants.php', '♙'],
        'nominations' => ['Nominations', 'nominations.php', '✳'],
        'analytics' => ['Event insights', 'analytics.php', '▥'],
    ];
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <?php if ($refreshEveryMinute): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
      <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — WInVote organizer</title>
      <link rel="stylesheet" href="../assets/css/app.css">
    </head>
    <body>
      <div class="dashboard-shell">
        <aside class="sidebar" id="organizer-sidebar">
          <a class="brand" href="events.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
          <div class="side-label">Organizer workspace</div>
          <nav class="sidebar-nav">
            <?php foreach ($navigation as $key => [$label, $href, $icon]): ?>
              <a class="side-link<?= $activePage === $key ? ' active' : '' ?>" href="<?= htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $activePage === $key ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            <?php endforeach; ?>
          </nav>
          <div class="sidebar-bottom">
            <a class="side-link" href="../events.php"><span class="side-icon" aria-hidden="true">↗</span>View public site</a>
          </div>
        </aside>
        <div class="dash-main">
          <header class="dash-topbar">
            <div class="dash-topbar-left"><button class="mobile-menu" type="button" aria-label="Toggle organizer menu" aria-controls="organizer-sidebar" aria-expanded="false" data-sidebar-toggle="organizer-sidebar">☰</button><span>Organizer workspace</span></div>
            <?php if ($user !== null): ?>
              <?= render_account_menu($user, csrf_token(), '../', ($user['organizer_status'] ?? null) === 'approved' ? 'Approved organizer' : 'Organizer') ?>
            <?php endif; ?>
          </header>
    <?php
}

function organizer_page_footer(): void
{
    ?>
        </div>
      </div>
      <script src="../assets/js/dashboard-navigation.js"></script>
    </body>
    </html>
    <?php
}
