<?php
declare(strict_types=1);
/**
 * Import history from the command line (no upload limits, good for the first big load).
 *
 *   php cli/import.php <file|folder> [--month=YYYY-MM] [--kind=feeders|solar|water_energy|sql]
 *                      [--policy=keep|replace-imported] [--dry-run] [--user=<username>] [--sql]
 *
 * A folder is walked recursively. Files are recognised by name (Feeder Reading 1/2, solar log, Water & Energy,
 * meter_readings_bulk*.sql); Office temp files, Thumbs.db and copies sitting in a second month folder
 * (2025/June/May repeats 2025/May) are skipped. The old meter_readings_bulk*.sql exports overlap the Excel files, so a
 * folder walk only includes them with --sql (naming one file directly always imports it). The month comes from the folder/file name unless --month is given.
 * Files are processed oldest month first so the running diesel total carries across months.
 */
require __DIR__ . '/_boot.php';

use App\Core\DB;
use App\Services\Import\Importer;
use App\Services\Import\Reader;

$opt = ['month' => null, 'kind' => null, 'policy' => 'keep', 'dry-run' => false, 'user' => null, 'sql' => false];
$paths = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        if (!array_key_exists($m[1], $opt)) {
            fwrite(STDERR, "Unknown option --{$m[1]}\n");
            exit(1);
        }
        $opt[$m[1]] = in_array($m[1], ['dry-run', 'sql'], true) ? true : ($m[2] ?? '');
    } else {
        $paths[] = $a;
    }
}
if (!$paths) {
    fwrite(STDERR, "Usage: php cli/import.php <file|folder> [--month=YYYY-MM] [--kind=...] [--policy=keep|replace-imported] [--dry-run] [--user=name]\n");
    exit(1);
}
if (!isset(Importer::POLICIES[$opt['policy']])) {
    fwrite(STDERR, "Unknown policy '{$opt['policy']}'.\n");
    exit(1);
}
if (!Reader::ready()) {
    fwrite(STDERR, "Excel reading needs the PHP zip extension and `composer install`.\n  Try: php -d extension=zip cli/import.php ...\n");
    exit(1);
}

$user = $opt['user']
    ? DB::one('SELECT id, site_id, name, username, role FROM users WHERE username = ?', [$opt['user']])
    : DB::one("SELECT id, site_id, name, username, role FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
if (!$user) {
    fwrite(STDERR, "No admin user found. Create one with cli/create_admin.php first.\n");
    exit(1);
}

// ---- collect --------------------------------------------------------------------------------
$recognised = '/^(Feeder Reading [12]\.xlsx|solar log.*\.xlsx|.*Water\s*&\s*Energy.*\.xlsx|meter_readings_bulk.*\.sql)$/i';
$files = [];
$skipped = [];
foreach ($paths as $p) {
    if (is_file($p)) {
        $files[] = realpath($p);
        continue;
    }
    if (!is_dir($p)) {
        fwrite(STDERR, "Not found: {$p}\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $full = str_replace('\\', '/', $f->getPathname());
        $name = basename($full);
        if (str_starts_with($name, '~$') || strcasecmp($name, 'Thumbs.db') === 0 || preg_match('/\.(lnk|db)$/i', $name)) {
            continue;
        }
        if (!preg_match($recognised, $name)) {
            if (preg_match('/\.(xlsx|xls|sql)$/i', $name)) {
                $skipped[] = [$full, 'not a recognised logsheet file name'];
            }
            continue;
        }
        if (preg_match('/\.sql$/i', $name) && !$opt['sql']) {
            $skipped[] = [$full, 'old SQL export overlaps the Excel files (add --sql to include it)'];
            continue;
        }
        $hasOwnMonth = (bool) preg_match('/(?<![a-z])(jan|feb|mar|apr|may|jun|jul|aug|agu|sep|oct|nov|dec)[a-z]*/i', $name);
        if (!$hasOwnMonth && Reader::isNestedMonthCopy($full) && !$opt['month']) {
            $skipped[] = [$full, 'sits inside a second month folder (a copy of another month)'];
            continue;
        }
        $files[] = $full;
    }
}

$plan = [];
foreach ($files as $f) {
    $f = str_replace('\\', '/', $f);
    $month = $opt['month'] ?: Reader::guessMonth($f)[0];
    $plan[] = ['file' => $f, 'month' => $month];
}
usort($plan, static fn ($a, $b) => [$a['month'] ?? '9999', $a['file']] <=> [$b['month'] ?? '9999', $b['file']]);

echo count($plan) . ' file(s) to ' . ($opt['dry-run'] ? 'check (dry run, nothing is saved)' : 'import') . ', ' . count($skipped) . " skipped\n";
foreach ($skipped as [$f, $why]) {
    echo '  skip  ' . $f . "\n        " . $why . "\n";
}
echo "\n";

// ---- run ------------------------------------------------------------------------------------
$tot = ['added' => 0, 'same' => 0, 'conflict' => 0, 'issues' => 0, 'failed' => 0];
foreach ($plan as $i => ['file' => $f, 'month' => $month]) {
    $label = ($month ?? '????-??') . '  ' . basename(dirname($f)) . '/' . basename($f);
    if ($month === null && strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'sql') {
        echo "  FAIL  {$label}\n        could not work out the month; pass --month=YYYY-MM\n";
        $tot['failed']++;
        continue;
    }
    try {
        $res = Reader::parse($f, $month ?? date('Y-m'), $opt['kind'] ?: null, basename($f));
        $pl = Importer::plan($res, $opt['policy']);
        $nIssues = count(array_filter($res->issues, static fn ($x) => $x['kind'] !== 'info'));
        $missing = $res->kind === 'sql' ? '' : Importer::ranges($res->missingDays());
        if ($opt['dry-run']) {
            $added = $pl['new'] + $pl['replace'];
        } else {
            $batch = Importer::commit($res, $opt['policy'], $user);
            $b = DB::one('SELECT rows_added, issues FROM import_batches WHERE id = ?', [$batch]);
            $added = (int) $b['rows_added'];
            $nIssues = (int) $b['issues'];   // after skipping items already raised or already filled
        }
        printf(
            "  %-4s  %s  [%s]\n        +%s new, %s same, %s differ (kept), %d to review%s\n",
            $opt['dry-run'] ? 'check' : 'done', $label, $res->kind, number_format($added), number_format($pl['same']), number_format($pl['conflict']), $nIssues,
            $missing !== '' ? ", no data on day(s) {$missing}" : ''
        );
        foreach ($res->sheets as $s) {
            if ($s['status'] === 'skipped' && !str_contains($s['reason'], 'Handwriting') && !str_contains($s['reason'], 'Not the Final')) {
                echo "        sheet “{$s['name']}” skipped: {$s['reason']}\n";
            }
        }
        $tot['added'] += $added;
        $tot['same'] += $pl['same'];
        $tot['conflict'] += $pl['conflict'];
        $tot['issues'] += $nIssues;
    } catch (Throwable $e) {
        echo "  FAIL  {$label}\n        " . $e->getMessage() . "\n";
        $tot['failed']++;
    }
}
printf(
    "\n%s: %s readings %s, %s already present, %s differed (kept), %d item(s) for review, %d failed.\n",
    $opt['dry-run'] ? 'Dry run' : 'Finished', number_format($tot['added']), $opt['dry-run'] ? 'would be added' : 'added', number_format($tot['same']),
    number_format($tot['conflict']), $tot['issues'], $tot['failed']
);
if (!$opt['dry-run'] && $tot['issues']) {
    echo "Review them in the app: Admin → Import history → review.\n";
}
exit($tot['failed'] ? 1 : 0);
