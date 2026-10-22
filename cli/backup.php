<?php
declare(strict_types=1);
/** php cli/backup.php — dump the database to storage/backups and drop dumps older than 30 days. */
require __DIR__ . '/_boot.php';

use App\Services\Backup;

$file = Backup::run();
echo 'Wrote ' . basename($file) . ' (' . number_format((int) filesize($file) / 1024, 0) . " KB)\n";
echo 'Removed ' . Backup::prune(30) . " old dump(s)\n";
