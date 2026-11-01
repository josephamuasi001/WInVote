<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$database = database_connection();
$columns = [
    'events' => 'cover_image_key',
    'categories' => 'image_key',
    'nominations' => 'profile_image_key',
];

foreach ($columns as $table => $column) {
    $check = $database->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $check->execute(['table_name' => $table, 'column_name' => $column]);
    if ((int) $check->fetchColumn() > 0) {
        fwrite(STDOUT, "{$table}.{$column} already exists.\n");
        continue;
    }

    $database->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(40) NULL");
    fwrite(STDOUT, "Added {$table}.{$column}.\n");
}
