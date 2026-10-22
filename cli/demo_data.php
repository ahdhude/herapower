<?php
declare(strict_types=1);
/**
 * Local demo data: three demo users (+ admin if none) and ~2 weeks of plausible readings.
 *   php cli/demo_data.php
 * Passwords are generated and written to storage/demo-credentials.txt (git-ignored). Never run in production.
 */
require __DIR__ . '/_boot.php';

use App\Core\Config;
use App\Core\DB;
use App\Models\Meter;
use App\Services\Slots;
use App\Services\Totals;

if (Config::isProduction()) {
    fwrite(STDERR, "Refusing to create demo data when APP_ENV is production.\n");
    exit(1);
}
if ((int) DB::val('SELECT COUNT(*) FROM readings') > 0) {
    fwrite(STDERR, "Readings already exist. Run `php cli/setup_db.php --fresh` first if you want a clean demo.\n");
    exit(1);
}

mt_srand(20261015);
$now = now();
$today = $now->setTime(0, 0);

// ---- users ---------------------------------------------------------------------------------
$creds = [];
$want = [
    ['admin',       'Admin (demo)',        'admin'],
    ['supervisor1', 'Ibrahim Rasheed',     'supervisor'],
    ['operator1',   'Ahmed Naseem',        'operator'],
    ['viewer1',     'GM Office',           'viewer'],
];
foreach ($want as [$u, $name, $role]) {
    if (DB::val('SELECT id FROM users WHERE username = ?', [$u])) {
        continue;
    }
    $pw = bin2hex(random_bytes(6));
    DB::exec('INSERT INTO users (site_id, name, username, password_hash, role) VALUES (1, ?, ?, ?, ?)', [$name, $u, password_hash($pw, PASSWORD_DEFAULT), $role]);
    $creds[] = "{$role}\t{$u}\t{$pw}";
}
if ($creds) {
    @file_put_contents(APP_ROOT . '/storage/demo-credentials.txt', "role\tusername\tpassword\n" . implode("\n", $creds) . "\n");
    echo 'Wrote ' . count($creds) . " demo logins to storage/demo-credentials.txt\n";
}
$op = (int) DB::val("SELECT id FROM users WHERE username = 'operator1'");

// ---- helpers -------------------------------------------------------------------------------
function diurnal(int $h): float
{
    // low overnight, morning bump, evening peak around 19:00 (resort load shape)
    return 0.55 + 0.25 * exp(-(($h - 8) ** 2) / 8) + 0.45 * exp(-(($h - 19) ** 2) / 10);
}
function jitter(float $pct): float
{
    return 1 + (mt_rand(-1000, 1000) / 1000) * $pct;
}
function insertRows(array $rows): void
{
    foreach (array_chunk($rows, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,0,NULL,NULL,\'app\')'));
        DB::exec("INSERT INTO readings (meter_id, reading_at, value, entered_by, entered_at, is_late, warning_codes, note, source) VALUES {$ph}", array_merge(...array_map('array_values', $chunk)));
    }
}

$feeders = array_merge(Meter::inGroup(1), Meter::inGroup(2));
$base = [];
foreach ($feeders as $m) {
    if ((int) $m['is_incomer']) {
        continue;
    }
    $base[(int) $m['id']] = match (true) {
        str_contains($m['code'], 'KITCHEN') => 48, str_contains($m['code'], 'LAUNDRY') => 22, str_contains($m['code'], 'WATER') => 30,
        str_contains($m['code'], 'MDB') => 36, str_contains($m['code'], 'STAFF') => 28, default => mt_rand(4, 18),
    };
}
$nowHour = (int) $now->format('H');
$solar = Meter::ofKind('solar')[0];
$solarSlots = Slots::forMeter($solar, $today);

$rows = [];
$days = 14;
for ($d = $days; $d >= 0; $d--) {
    $day = $today->modify("-{$d} days");
    $isToday = $d === 0;
    $factor = jitter(0.06);
    // feeders (hourly)
    for ($h = 0; $h < 24; $h++) {
        if ($isToday && $h > $nowHour - 2) {   // leave the last two hours open on purpose
            break;
        }
        $at = $day->setTime($h, 0);
        $sum = 0.0;
        foreach ($base as $mid => $b) {
            $v = round($b * diurnal($h) * $factor * jitter(0.08), 1);
            if (in_array($mid, [21, 27], true) && $h < 6) {
                $v = 0.0; // heat-ex pump and dryers are off at night: zero is a real reading
            }
            $sum += $v;
            $rows[] = [$mid, $at->format('Y-m-d H:i:s'), (string) $v, $op, $at->modify('+' . mt_rand(1, 14) . ' minutes')->format('Y-m-d H:i:s')];
        }
        $rows[] = [6, $at->format('Y-m-d H:i:s'), (string) round($sum * 1.13, 1), $op, $at->modify('+' . mt_rand(1, 14) . ' minutes')->format('Y-m-d H:i:s')];
    }
    // solar (half-hourly)
    foreach ($solarSlots as $s) {
        $at = $day->setTime((int) $s->format('H'), (int) $s->format('i'));
        if ($isToday && $at > $now->modify('-60 minutes')) {
            continue;
        }
        $x = ((int) $s->format('H') + (int) $s->format('i') / 60 - 12) / 6;
        $v = max(0, round(470 * max(0, 1 - $x * $x) * jitter(0.12) * (0.7 + 0.3 * $factor), 1));
        $rows[] = [(int) $solar['id'], $at->format('Y-m-d H:i:s'), (string) $v, $op, $at->modify('+3 minutes')->format('Y-m-d H:i:s')];
    }
}
insertRows($rows);
echo 'Inserted ' . count($rows) . " interval readings\n";

// daily counters: today's morning round is deliberately left undone
// [counter now, use per day in the meter's own unit (kWh for gens/inverters, m3, L)]
$counters = [60 => [120450, 640]] + [80 => [34147000, 7000], 81 => [20310000, 4500], 82 => [18800000, 2000], 83 => [15000000, 0], 90 => [8800400, 3830]];
foreach (range(51, 59) as $i => $id) {
    $counters[$id] = [1200000 + $i * 90000, 220 + $i * 12];
}
$rows = [];
$running = [];
for ($d = $days; $d >= 1; $d--) {
    $day = $today->modify("-{$d} days");
    foreach ($counters as $mid => [$start, $perDay]) {
        $meter = Meter::find($mid);
        $mult = (float) $meter['multiplier'];
        // a counter only ever goes up: add one day's use (display units) to the running value
        $running[$mid] = ($running[$mid] ?? $start) + ($d === $days ? 0 : $perDay * jitter(0.1) / $mult);
        $rows[] = [$mid, $day->format('Y-m-d') . ' 00:00:00', (string) round($running[$mid], 0), $op, $day->setTime(8, mt_rand(0, 25))->format('Y-m-d H:i:s')];
    }
}
insertRows($rows);
echo 'Inserted ' . count($rows) . " daily-meter readings\n";

// genset log: generators 1 and 2 on the last log day and so far on the current one
$cols = ['volts', 'hz', 'amp_r', 'amp_y', 'amp_b', 'pf', 'kw', 'oil_bar', 'water_c', 'kwh', 'run_hours', 'battery_v', 'rpm', 'solar_kw'];
$logToday = Slots::gensetLogDayOf($now);
foreach ([80 => 680, 81 => 420] as $mid => $kw) {
    foreach ([$logToday->modify('-1 day'), $logToday] as $ld) {
        foreach (Slots::gensetSlots($ld) as $i => $slot) {
            if ($slot > $now->modify('-60 minutes')) {
                break;
            }
            $load = round($kw * jitter(0.08), 1);
            $vals = [
                round(415 * jitter(0.008), 1), round(50 * jitter(0.004), 2), round($load * 1.4 * jitter(0.03), 1), round($load * 1.4 * jitter(0.03), 1), round($load * 1.4 * jitter(0.03), 1),
                round(0.86 * jitter(0.03), 3), $load, round(4.6 * jitter(0.05), 2), round(78 * jitter(0.04), 1), round(2300000 + $i * $load, 1), round(18000 + $i, 1),
                round(26.4 * jitter(0.01), 2), 1500, round(max(0, 120 * jitter(0.2)), 1),
            ];
            DB::exec(
                'INSERT INTO genset_logs (meter_id, log_at, ' . implode(',', $cols) . ', visual_ok, entered_by, entered_at) VALUES (?, ?, ' . implode(',', array_fill(0, count($cols), '?')) . ', 1, ?, ?)',
                array_merge([$mid, $slot->format('Y-m-d H:i:s')], $vals, [$op, $slot->modify('+6 minutes')->format('Y-m-d H:i:s')])
            );
        }
    }
}
echo "Inserted genset logs\n";

// totals for every day
for ($d = $days; $d >= 0; $d--) {
    Totals::refreshDay($today->modify("-{$d} days")->format('Y-m-d'));
}
echo "Refreshed daily totals. Done.\n";
