<?php
declare(strict_types=1);
/**
 * php tests/run.php
 * Plain-PHP checks of the rules in spec 2.3 / 2.4. DB-backed cases run inside a transaction that is rolled back.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Services\ReadingValidator;
use App\Services\Slots;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}
function codes(array $res, string $key = 'errors'): array
{
    return array_column($res[$key], 'code');
}

echo "NOT_NUMBER (pure)\n";
foreach (['11..21', '23.8/4', '7.72.', '8.4710.51', '2\\5.84', '', '  ', 'abc', '1,5', '1e3'] as $bad) {
    check("rejects '" . $bad . "'", ReadingValidator::parseNumber($bad) === null);
}
foreach (['354' => '354', ' 12.5 ' => '12.5', '0' => '0', '3.54' => '3.54', '0.0' => '0.0'] as $in => $out) {
    check("accepts '" . $in . "'", ReadingValidator::parseNumber((string) $in) === $out);
}
check('median odd', ReadingValidator::median([5, 1, 3]) === 3.0);
check('median even', ReadingValidator::median([1, 2, 3, 4]) === 2.5);

echo "Slots (pure)\n";
$feeder = ['interval_min' => 60, 'window_start' => '00:00:00', 'window_end' => '23:00:00'];
$solar  = ['interval_min' => 30, 'window_start' => '06:00:00', 'window_end' => '18:00:00'];
$daily  = ['interval_min' => null];
$day = new DateTimeImmutable('2026-10-15', site_tz());
check('feeder has 24 slots', count(Slots::forMeter($feeder, $day)) === 24 && Slots::expected($feeder) === 24);
check('solar has 25 slots', count(Slots::forMeter($solar, $day)) === 25 && Slots::expected($solar) === 25);
check('daily has 1 slot', count(Slots::forMeter($daily, $day)) === 1);
check('14:00 is a feeder slot', Slots::isSlot($feeder, $day->setTime(14, 0)));
check('14:10 is not a feeder slot', !Slots::isSlot($feeder, $day->setTime(14, 10)));
check('06:30 is a solar slot', Slots::isSlot($solar, $day->setTime(6, 30)));
check('05:30 is outside the solar window', !Slots::isSlot($solar, $day->setTime(5, 30)));
check('18:30 is outside the solar window', !Slots::isSlot($solar, $day->setTime(18, 30)));
check('daily slot only at 00:00', Slots::isSlot($daily, $day) && !Slots::isSlot($daily, $day->setTime(8, 0)));
check('due slot at 14:25 is 14:00', Slots::due($feeder, $day->setTime(14, 25))?->format('H:i') === '14:00');
check('solar not due at 05:00', Slots::due($solar, $day->setTime(5, 0)) === null);
check('genset 07:00 belongs to previous log day', Slots::gensetLogDayOf($day->setTime(7, 0))->format('Y-m-d') === '2026-10-14');
check('genset 08:00 starts the log day', Slots::gensetLogDayOf($day->setTime(8, 0))->format('Y-m-d') === '2026-10-15');
$gs = Slots::gensetSlots($day);
check('genset day runs 08:00 -> 07:00', count($gs) === 24 && $gs[0]->format('Y-m-d H:i') === '2026-10-15 08:00' && $gs[23]->format('Y-m-d H:i') === '2026-10-16 07:00');

echo "Database rules (rolled back)\n";
$pdo = DB::pdo();
$pdo->beginTransaction();
try {
    $hash = password_hash('x', PASSWORD_DEFAULT);
    DB::exec("INSERT INTO users (site_id, name, username, password_hash, role) VALUES (1,'T Sup','t_sup',?, 'supervisor')", [$hash]);
    $sup = DB::one("SELECT id, site_id, name, username, role FROM users WHERE username='t_sup'");
    DB::exec("INSERT INTO users (site_id, name, username, password_hash, role) VALUES (1,'T Op','t_op',?, 'operator')", [$hash]);
    $op = DB::one("SELECT id, site_id, name, username, role FROM users WHERE username='t_op'");
    DB::exec("INSERT INTO users (site_id, name, username, password_hash, role) VALUES (1,'T View','t_view',?, 'viewer')", [$hash]);
    $viewer = DB::one("SELECT id, site_id, name, username, role FROM users WHERE username='t_view'");

    $today = now()->setTime(0, 0);
    $d = '2026-09-10';
    $save = static fn (array $in, array $u) => Reading::save($in, $u);

    // hard rules
    foreach (['11..21' => 'NOT_NUMBER', '' => 'NOT_NUMBER', '-5' => 'NEGATIVE'] as $raw => $code) {
        $r = $save(['meter_id' => 7, 'reading_at' => "$d 10:00", 'value' => (string) $raw], $sup);
        check("feeder '{$raw}' blocked with {$code}", $r['status'] === 'error' && in_array($code, codes($r), true), json_encode($r['errors']));
    }
    $r = $save(['meter_id' => 7, 'reading_at' => "$d 10:10", 'value' => '10'], $sup);
    check('14:10-style time blocked: NOT_A_SLOT', in_array('NOT_A_SLOT', codes($r), true));
    $r = $save(['meter_id' => 7, 'reading_at' => $today->modify('+1 day')->format('Y-m-d') . ' 10:00', 'value' => '10'], $sup);
    check('tomorrow blocked: FUTURE', in_array('FUTURE', codes($r), true));
    $r = $save(['meter_id' => 7, 'reading_at' => $today->modify('-3 days')->format('Y-m-d') . ' 10:00', 'value' => '10'], $op);
    check('operator 3 days back blocked: OUT_OF_WINDOW', in_array('OUT_OF_WINDOW', codes($r), true));
    $r = $save(['meter_id' => 7, 'reading_at' => "$d 10:00", 'value' => '10'], $viewer);
    check('viewer cannot enter', $r['status'] === 'error' && in_array('FORBIDDEN', codes($r), true));
    $r = $save(['meter_id' => 61, 'reading_at' => "$d 00:00", 'value' => '10'], $sup);
    check('inactive meter refused', in_array('METER_INACTIVE', codes($r), true));

    // zero is valid
    $r = $save(['meter_id' => 21, 'reading_at' => "$d 02:00", 'value' => '0'], $sup);
    check('zero is a valid reading', $r['status'] === 'saved', json_encode($r));

    // idempotency
    $uuid = '11111111-2222-4333-8444-555555555555';
    $a = $save(['meter_id' => 7, 'reading_at' => "$d 11:00", 'value' => '120', 'client_uuid' => $uuid], $sup);
    $b = $save(['meter_id' => 7, 'reading_at' => "$d 11:00", 'value' => '120', 'client_uuid' => $uuid], $sup);
    check('same client_uuid is idempotent', $a['status'] === 'saved' && $b['status'] === 'saved' && $a['reading']['id'] === $b['reading']['id']
        && (int) DB::val('SELECT COUNT(*) FROM readings WHERE meter_id = 7 AND reading_at = ?', ["$d 11:00:00"]) === 1);

    // edit -> audit
    $r = $save(['meter_id' => 7, 'reading_at' => "$d 11:00", 'value' => '125', 'reason' => 'misread'], $sup);
    $audit = DB::one('SELECT * FROM reading_audit WHERE reading_id = ?', [$a['reading']['id']]);
    check('edit updates the value and writes an audit row', $r['status'] === 'saved' && $audit && (float) $audit['old_value'] === 120.0 && (float) $audit['new_value'] === 125.0);
    $r = $save(['meter_id' => 7, 'reading_at' => "$d 11:00", 'value' => '125'], $sup);
    check('same value again changes nothing', $r['status'] === 'saved' && (int) DB::val('SELECT COUNT(*) FROM reading_audit WHERE reading_id = ?', [$a['reading']['id']]) === 1);
    $r = $save(['meter_id' => 7, 'reading_at' => "$d 11:00", 'value' => '130'], $sup);
    check('editing an old reading needs a reason', $r['status'] === 'error' && in_array('REASON_REQUIRED', codes($r), true));
    $r = $save(['meter_id' => 7, 'reading_at' => "$d 11:00", 'value' => '130', 'reason' => 'typo'], $sup);
    check('...and saves with one', $r['status'] === 'saved' && $r['reading']['value'] === '130');

    // operator edits inside the window need no reason
    $slotToday = $today->format('Y-m-d') . ' 00:00';
    $o1 = $save(['meter_id' => 12, 'reading_at' => $slotToday, 'value' => '40'], $op);
    $o2 = $save(['meter_id' => 12, 'reading_at' => $slotToday, 'value' => '42'], $op);
    check('operator can correct a reading from today without a reason', $o1['status'] === 'saved' && $o2['status'] === 'saved' && $o2['reading']['value'] === '42', json_encode($o2));

    // SPIKE: 7 days of ~400 at 12:00 then 3.54
    for ($i = 1; $i <= 7; $i++) {
        $dd = (new DateTimeImmutable($d, site_tz()))->modify("-{$i} days")->format('Y-m-d');
        $save(['meter_id' => 8, 'reading_at' => "$dd 12:00", 'value' => (string) (395 + $i)], $sup);
    }
    $r = $save(['meter_id' => 8, 'reading_at' => "$d 12:00", 'value' => '3.54'], $sup);
    check('3.54 among ~400s warns SPIKE and is not saved', $r['status'] === 'warning' && in_array('SPIKE', codes($r, 'warnings'), true) && !Reading::find(8, "$d 12:00:00"));
    $r = $save(['meter_id' => 8, 'reading_at' => "$d 12:00", 'value' => '3.54', 'force' => true], $sup);
    check('Save anyway stores it with the warning code', $r['status'] === 'saved' && $r['reading']['warning_codes'] === 'SPIKE');
    $r = $save(['meter_id' => 8, 'reading_at' => "$d 13:00", 'value' => '402'], $sup);
    check('a normal value does not warn', $r['status'] === 'saved' && $r['reading']['warning_codes'] === null);

    // MIN_MAX
    DB::exec('UPDATE meters SET max_value = 500 WHERE id = 9');
    $r = $save(['meter_id' => 9, 'reading_at' => "$d 09:00", 'value' => '900'], $sup);
    check('value above max warns MIN_MAX', $r['status'] === 'warning' && in_array('MIN_MAX', codes($r, 'warnings'), true));

    // LATE flag: reading for a slot long ago saved now is late
    $r = $save(['meter_id' => 10, 'reading_at' => "$d 09:00", 'value' => '55'], $sup);
    check('late reading is flagged', $r['status'] === 'saved' && $r['reading']['is_late'] === true);

    // Cumulative: generator multiplier 0.1, 34,165,000 - 34,147,000 = 18,000 -> 1,800 kWh
    $r1 = $save(['meter_id' => 80, 'reading_at' => '2020-09-01 00:00', 'value' => '14147000'], $sup);
    $r2 = $save(['meter_id' => 80, 'reading_at' => '2020-09-02 00:00', 'value' => '14165000'], $sup);
    $cons = DB::val("SELECT consumption FROM daily_totals WHERE meter_id = 80 AND day = '2020-09-01'");
    check('generator 1 Sep = 1,800 kWh', $r1['status'] === 'saved' && $r2['status'] === 'saved' && abs((float) $cons - 1800.0) < 0.001, (string) $cons);
    $r = $save(['meter_id' => 80, 'reading_at' => '2020-09-03 00:00', 'value' => '14100000'], $sup);
    check('counter going backwards is blocked, overridable by a supervisor', $r['status'] === 'error' && in_array('COUNTER_BACKWARDS', codes($r), true) && $r['errors'][0]['overridable'] === true);
    $r = $save(['meter_id' => 80, 'reading_at' => '2020-09-03 00:00', 'value' => '14100000'], $op);
    check('...but not by an operator', $r['status'] === 'error');
    $r = $save(['meter_id' => 80, 'reading_at' => '2020-09-03 00:00', 'value' => '14100000', 'override' => true, 'reason' => 'meter replaced'], $sup);
    check('supervisor override saves', $r['status'] === 'saved' && str_contains((string) $r['reading']['warning_codes'], 'OVERRIDE'));
    $r = $save(['meter_id' => 80, 'reading_at' => '2020-09-01 12:00', 'value' => '1'], $sup);
    check('daily meter off 00:00 is NOT_A_SLOT', in_array('NOT_A_SLOT', codes($r), true));

    // Interval totals: solar 25 slots x 0.5 h
    foreach (Slots::forMeter(Meter::find(40), new DateTimeImmutable($d, site_tz())) as $s) {
        $save(['meter_id' => 40, 'reading_at' => $s->format('Y-m-d H:i'), 'value' => '100'], $sup);
    }
    $t = DB::one("SELECT * FROM daily_totals WHERE meter_id = 40 AND day = ?", [$d]);
    check('solar: 25 x 100 kW x 0.5 h = 1,250 kWh', $t && abs((float) $t['energy'] - 1250.0) < 0.001 && (int) $t['slots_filled'] === 25 && (int) $t['slots_expected'] === 25);
    $t = DB::one("SELECT * FROM daily_totals WHERE meter_id = 7 AND day = ?", [$d]);
    check('feeder: hourly kW x 1 h', $t && abs((float) $t['energy'] - (float) $t['total']) < 0.001);

    // Daily meters: "all inverters" are summed (the old sheet only summed 1-4)
    foreach ([51 => 1000, 52 => 1000, 55 => 1000, 59 => 1000] as $id => $base) {
        $save(['meter_id' => $id, 'reading_at' => '2020-09-01 00:00', 'value' => (string) $base], $sup);
        $save(['meter_id' => $id, 'reading_at' => '2020-09-02 00:00', 'value' => (string) ($base + 200)], $sup);
    }
    $solarKwh = (float) DB::val("SELECT SUM(d.consumption) FROM daily_totals d JOIN meters m ON m.id = d.meter_id WHERE m.kind='inverter' AND d.day='2020-09-01'");
    check('solar daily total includes inverters 5-9 (4 inverters x 200 = 800)', abs($solarKwh - 800.0) < 0.001, (string) $solarKwh);
} finally {
    $pdo->rollBack();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
