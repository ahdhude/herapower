<?php
declare(strict_types=1);
/**
 * Create the database (if missing) and load schema.sql + seed.sql.
 *   php cli/setup_db.php            -> refuses to touch a database that already has tables
 *   php cli/setup_db.php --fresh    -> drops every table first (local development only)
 */
require __DIR__ . '/_boot.php';

use App\Core\Config;

$fresh = in_array('--fresh', $argv, true);
$name  = (string) Config::get('DB_NAME');

if ($fresh && Config::isProduction()) {
    fwrite(STDERR, "Refusing --fresh when APP_ENV is production.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('DB_HOST'), (int) Config::get('DB_PORT')),
    (string) Config::get('DB_USER'),
    (string) Config::get('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$name}`");
$pdo->exec("SET time_zone = '" . Config::get('DB_TZ_OFFSET', '+05:00') . "'");

$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($existing && !$fresh) {
    fwrite(STDERR, "Database `{$name}` already has " . count($existing) . " tables. Use --fresh to rebuild it (this deletes data).\n");
    exit(1);
}
if ($existing) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($existing as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

foreach (['schema.sql', 'seed.sql'] as $file) {
    $pdo->exec((string) file_get_contents(APP_ROOT . '/database/' . $file));
    echo "Loaded {$file}\n";
}

// Apply migrations on top (the same runner `php cli/migrate.php` uses, so they are recorded).
foreach (App\Services\Migrator::run() as $m) {
    echo "Applied {$m}\n";
}

echo "Database `{$name}` is ready.\n";
