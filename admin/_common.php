<?php
declare(strict_types=1);

function admin_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_page_header(string $title, string $activePage, ?array $user): void
{
    $navigation = [
        'dashboard' => ['Overview', 'index.php'],
        'organizers' => ['Organizers', 'organizers.php'],
        'events' => ['Events', 'events.php'],
        'users' => ['Users', 'users.php'],
    ];
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= admin_escape($title) ?> — WInVote administration</title>
      <link rel="stylesheet" href="../assets/css/app.css">
    </head>
    <body>
      <div class="dashboard-shell">
      <aside class="sidebar" id="admin-sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">W</span><span>WIn<em>Vote</em></span></a>
        <div class="side-label">Platform</div>
        <nav class="sidebar-nav">
          <?php foreach ($navigation as $key => [$label, $href]): ?>
            <a class="side-link<?= $activePage === $key ? ' active' : '' ?>" href="<?= admin_escape($href) ?>" <?= $activePage === $key ? 'aria-current="page"' : '' ?>><span class="side-icon" aria-hidden="true"><?= $key === 'dashboard' ? '⌂' : ($key === 'organizers' ? '♙' : ($key === 'users' ? '◉' : '▦')) ?></span><?= admin_escape($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom"><a class="side-link" href="../index.php"><span class="side-icon" aria-hidden="true">↗</span>View public site</a></div>
      </aside>
      <div class="dash-main">
      <header class="dash-topbar">
        <div class="dash-topbar-left"><button class="mobile-menu" type="button" aria-label="Toggle admin menu" aria-controls="admin-sidebar" aria-expanded="false" data-sidebar-toggle="admin-sidebar">☰</button><span>Platform administration</span></div>
        <?php if ($user !== null): ?>
          <?= render_account_menu($user, csrf_token(), '../', 'Super admin') ?>
        <?php else: ?>
          <a class="btn btn-primary btn-sm" href="../auth.php?action=login">Sign in</a>
        <?php endif; ?>
      </header>
      <main class="content-section"><div class="container">
        <div class="section-heading">
          <div><span class="eyebrow">Protected platform administration</span><h1><?= admin_escape($title) ?></h1><?php if ($user !== null): ?><p>Signed in as <?= admin_escape((string) $user['full_name']) ?> · Super admin</p><?php endif; ?></div>
        </div>
    <?php
}

function admin_page_footer(): void
{
    ?>
      </div></main></div></div>
      <script src="../assets/js/dashboard-navigation.js"></script>
    </body>
    </html>
    <?php
}

function admin_page_context(): array
{
    $database = database_connection();
    $access = new AccessControl($database);
    $user = $access->requireRole('super_admin');

    return [$database, $user, new AdminService($database)];
}

function admin_post_integer(string $name): int
{
    $value = $_POST[$name] ?? null;
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        throw new InvalidArgumentException('Choose a valid item to review.');
    }

    return (int) $value;
}

function admin_post_string(string $name): string
{
    $value = $_POST[$name] ?? null;
    if (!is_string($value)) {
        throw new InvalidArgumentException('The submitted action is invalid.');
    }

    return $value;
}
