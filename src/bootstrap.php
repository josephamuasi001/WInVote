<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$environmentFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';
if (is_file($environmentFile)) {
    $lines = file($environmentFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('The environment configuration file could not be read.');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
            throw new RuntimeException('The environment configuration file contains an invalid line.');
        }

        $name = $matches[1];
        $value = trim($matches[2]);
        if (strlen($value) >= 2
            && (($value[0] === '"' && $value[-1] === '"')
                || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/config/database.php';
require_once $projectRoot . '/src/Helpers/Csrf.php';
require_once $projectRoot . '/src/Helpers/AccountMenu.php';
require_once $projectRoot . '/src/Middleware/AccessControl.php';
require_once $projectRoot . '/src/Services/AuthService.php';
require_once $projectRoot . '/src/Services/AdminService.php';
require_once $projectRoot . '/src/Services/CategoryService.php';
require_once $projectRoot . '/src/Services/ContestantService.php';
require_once $projectRoot . '/src/Services/EventService.php';
require_once $projectRoot . '/src/Services/NominationService.php';
require_once $projectRoot . '/src/Services/OrganizerAnalyticsService.php';
require_once $projectRoot . '/src/Services/PaymentSimulator.php';
require_once $projectRoot . '/src/Services/VoteService.php';
require_once $projectRoot . '/src/Services/ImageStorage.php';

$environment = app_environment();
$timezone = getenv('APP_TIMEZONE');
date_default_timezone_set($timezone === false ? 'UTC' : $timezone);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('WINVOTESESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

if (isset($_SESSION['last_activity'])
    && time() - (int) $_SESSION['last_activity'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();
