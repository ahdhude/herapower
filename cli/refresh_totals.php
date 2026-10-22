<?php
declare(strict_types=1);
/** php cli/refresh_totals.php [from Y-m-d] [to Y-m-d] — rebuild daily_totals (defaults to yesterday). */
require __DIR__ . '/_boot.php';

use App\Services\Totals;

$from = $argv[1] ?? now()->modify('-1 day')->format('Y-m-d');
$to = $argv[2] ?? $from;
$d = new DateTimeImmutable($from, site_tz());
$end = new DateTimeImmutable($to, site_tz());
$n = 0;
for (; $d <= $end; $d = $d->modify('+1 day')) {
    Totals::refreshDay($d->format('Y-m-d'));
    $n++;
}
echo "Refreshed {$n} day(s) from {$from} to {$to}.\n";
