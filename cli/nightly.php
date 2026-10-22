<?php
declare(strict_types=1);
/**
 * Nightly job (hPanel cron, 01:30): refresh yesterday's totals, check what is missing or late,
 * email the summary to settings.alert_email, back up the database and prune old dumps.
 */
require __DIR__ . '/_boot.php';

use App\Models\Setting;
use App\Services\Backup;
use App\Services\Progress;
use App\Services\Totals;

$yesterday = now()->modify('-1 day');
$day = $yesterday->format('Y-m-d');

Totals::refreshDay($day);
Totals::refreshDay($yesterday->modify('-1 day')->format('Y-m-d')); // yesterday's counters finish the day before
echo "Totals refreshed for {$day}\n";

// ---- missing / late check ------------------------------------------------------------------
$progress = Progress::forDay($yesterday, now());
$lines = [];
foreach ($progress['logs'] as $l) {
    if ($l['kind'] === 'genset') {
        continue;
    }
    if ($l['state'] !== 'complete') {
        $lines[] = sprintf('%s: %s (%d of %d)', $l['title'], $l['summary'], $l['done'], $l['total']);
    }
}
$late = (int) App\Core\DB::val('SELECT COUNT(*) FROM readings WHERE is_late = 1 AND reading_at >= ? AND reading_at < DATE_ADD(?, INTERVAL 1 DAY)', [$day . ' 00:00:00', $day . ' 00:00:00']);
$warned = (int) App\Core\DB::val('SELECT COUNT(*) FROM readings WHERE warning_codes IS NOT NULL AND reading_at >= ? AND reading_at < DATE_ADD(?, INTERVAL 1 DAY)', [$day . ' 00:00:00', $day . ' 00:00:00']);

$body = "Herahakatha — " . $yesterday->format('l j F Y') . "\n\n";
$body .= $lines ? "Incomplete:\n - " . implode("\n - ", $lines) . "\n" : "All logs complete.\n";
$body .= "\nLate readings: {$late}\nSaved with a warning: {$warned}\n";
echo $body;

$to = Setting::get('alert_email');
if ($to && ($lines || $late || $warned)) {
    $ok = @mail($to, 'Herahakatha: ' . ($lines ? count($lines) . ' log(s) incomplete' : 'daily summary') . ' — ' . $day, $body, 'Content-Type: text/plain; charset=UTF-8');
    echo $ok ? "Alert emailed to {$to}\n" : "Could not send email (mail() unavailable)\n";
}

// ---- backup ----------------------------------------------------------------------------------
$file = Backup::run();
echo 'Backup: ' . basename($file) . ', pruned ' . Backup::prune(30) . " old\n";
