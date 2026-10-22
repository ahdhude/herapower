<?php
declare(strict_types=1);
/** php cli/migrate.php — apply any new files in database/migrations (safe to run repeatedly). */
require __DIR__ . '/_boot.php';

use App\Services\Migrator;

$applied = Migrator::run();
echo $applied ? 'Applied: ' . implode(', ', $applied) . "\n" : "Nothing to apply — database is up to date.\n";
