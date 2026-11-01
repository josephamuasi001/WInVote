<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $environment = app_environment();
    $host = app_config('DB_HOST');
    $port = app_config('DB_PORT');
    $database = app_config('DB_DATABASE');
    $username = app_config('DB_USERNAME');
    $configuredPassword = getenv('DB_PASSWORD');
    $password = $configuredPassword === false && $environment !== 'production'
        ? ''
        : app_config('DB_PASSWORD');

    if ($environment === 'production' && $username === 'root') {
        throw new RuntimeException('The production database account must not be root.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $database
    );
    $connection = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $connection->exec("SET time_zone = '+00:00'");

    return $connection;
}
