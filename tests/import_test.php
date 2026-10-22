<?php
declare(strict_types=1);
/**
 * php -d extension=zip tests/import_test.php
 * Importer checks. Workbooks are built on the fly to reproduce the quirks of the real logsheets; database work is
 * rolled back. Needs the zip extension (skips itself otherwise).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Services\Import\CellCleaner;
use App\Services\Import\Importer;
use App\Services\Import\MeterIndex;
use App\Services\Import\Reader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!Reader::ready()) {
    echo "Skipped: needs the PHP zip extension and composer packages (try: php -d extension=zip tests/import_test.php)\n";
    exit(0);
}

$pass = $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "CellCleaner\n";
foreach (['11..21' => '11.21', '7.72.' => '7.72', '23.8/4' => '23.84', '2\\5.84' => '25.84', '24..24' => '24.24', '13.19+' => '13.19', '1,5' => '1.5', '1,234' => '1234', '.5x' => '0.5'] as $raw => $want) {
    $c = CellCleaner::clean($raw);
    check("'{$raw}' is bad and suggests {$want}", $c['status'] === 'bad' && $c['suggestion'] === $want, json_encode($c));
}
foreach (['8.4710.51', '3.5.3', '2.076.74', '.', '#DIV/0!', 'abc'] as $raw) {
    $c = CellCleaner::clean($raw);
    check("'{$raw}' is bad with no guess", $c['status'] === 'bad' && $c['suggestion'] === null, json_encode($c));
}
check('-5 suggests 5', CellCleaner::clean('-5')['suggestion'] === '5');
check('empty cell', CellCleaner::clean(null)['status'] === 'empty' && CellCleaner::clean('  ')['status'] === 'empty');
check('float 12.5 is a number', CellCleaner::clean(12.5)['value'] === '12.5');
check('text "12.50" is a clean number', CellCleaner::clean(' 12.50 ')['status'] === 'number');
check('zero is a number', CellCleaner::clean(0)['value'] === '0');
check('large counter keeps digits', CellCleaner::clean(34165000)['value'] === '34165000');

echo "MeterIndex\n";
$idx = MeterIndex::all();
check('HV -> HV Network', ($idx->find('HV', ['feeder'])['code'] ?? '') === 'HV-NETWORK');
check('"network" -> HV Network', ($idx->find('network', ['feeder'])['code'] ?? '') === 'HV-NETWORK');
check('LAUNDRYDRYERS (no space)', ($idx->find('LAUNDRYDRYERS', ['feeder'])['code'] ?? '') === 'LAUNDRY-DRYERS');
check('HR / MOSQUE', ($idx->find('HR / MOSQUE', ['feeder'])['code'] ?? '') === 'HR-MOSQUE');
check('INCINERATOR WORK SHOP', ($idx->find('INCINERATOR WORK SHOP', ['feeder'])['code'] ?? '') === 'INCINERATOR-WS');
check('a number is not a header', $idx->find(219.4, ['feeder']) === null);
check('one typo is matched by similarity', ($idx->fuzzy('KATHEB VILLA', ['feeder'])['code'] ?? '') === 'KATHEEB-VILLA');
check('Line 1 / Generator 2 / Inverter 4 codes resolve', $idx->find('WATER-L1') && $idx->find('GEN-2') && $idx->find('INV-4'));

// ---------------------------------------------------------------- synthetic workbooks ----------
$tmp = dirname(__DIR__) . '/storage/imports';
$save = static function (Spreadsheet $ss, string $name) use ($tmp): string {
    $p = "{$tmp}/test-{$name}.xlsx";
    (new Xlsx($ss))->save($p);
    return $p;
};
$serial = static fn (string $d) => \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime($d));

// Feeder workbook, February 2030 (28 days)
$feederNames = ['HV Network', 'JSB-9001', 'JSB-8001', 'JSB-9002', 'JSB-8002', 'EX. STAFF BLK'];
$dayRows = static function (float $base, array $overrides = []) {
    $rows = [];
    for ($h = 0; $h < 24; $h++) {
        $row = [$h + 1, $h / 24];
        foreach ([0, 1, 2, 3, 4, 5] as $c) {
            $row[] = $overrides["{$h}:{$c}"] ?? round($base + $c * 10 + $h, 1);
        }
        $rows[] = $row;
    }
    return $rows;
};
$wb = new Spreadsheet();
$s1 = $wb->getActiveSheet()->setTitle('03.02.2030');
$hdr = ['#', 'Time', 'HV Network', 'JSB-9001', 17.48, 'JSB-9002', 'JSB-8002', 'EX. STAFF BLK']; // JSB-8001 mistyped as a number
$s1->fromArray([['FEEDER READING'], ['DATE :', null, $serial('2030-02-09')], $hdr], null, 'A1');
$s1->fromArray($dayRows(100, ['5:0' => '11..21', '7:3' => '23.8/4', '2:1' => '']), null, 'A4');

$s2 = $wb->createSheet()->setTitle('04.02.2030');   // columns shifted left, no # / Time columns, header on row 2
$s2->fromArray([['FEEDER READING'], ['HV Network', 'JSB-9001', 'JSB-8001', 'JSB-9002', 'JSB-8002', 'EX. STAFF BLK']], null, 'A1');
$rows = [];
for ($h = 0; $h < 24; $h++) {
    $rows[] = [200 + $h, 210 + $h, 220 + $h, 230 + $h, 240 + $h, 250 + $h];
}
$s2->fromArray($rows, null, 'A3');

$s3 = $wb->createSheet()->setTitle('29.02.2030');   // 2030 is not a leap year
$s3->fromArray([['x'], ['y'], $hdr], null, 'A1');
$s4 = $wb->createSheet()->setTitle('03.02-2030');   // second sheet for the 3rd, with fewer readings
$s4->fromArray([['FEEDER READING'], ['DATE :'], $hdr], null, 'A1');
$s4->fromArray([[1, 0, 1, 1, 1, 1, 1, 1]], null, 'A4');
$wb->createSheet()->setTitle('Sheet1');
$fp = $save($wb, 'feeder');

$res = Reader::parse($fp, '2030-02', null, 'Feeder Reading 1.xlsx');
check('detected as feeders', $res->kind === 'feeders');
$dates = array_values(array_unique(array_map(static fn ($r) => substr($r[1], 0, 10), $res->rows)));
sort($dates);
check('only the 3rd and 4th are imported (date = month + sheet-name day)', $dates === ['2030-02-03', '2030-02-04'], json_encode($dates));
$used = array_column(array_filter($res->sheets, static fn ($s) => $s['status'] === 'used'), 'name');
check('the richer of two sheets for one day is used', $used === ['03.02.2030', '04.02.2030'], json_encode($used));
$why = array_column(array_filter($res->sheets, static fn ($s) => $s['status'] === 'skipped'), 'reason', 'name');
check('day 29 is rejected for February', str_contains($why['29.02.2030'] ?? '', 'does not exist'), json_encode($why));
check('duplicate day sheet is skipped', str_contains($why['03.02-2030'] ?? '', 'already supplies'), json_encode($why));
check('Sheet1 is skipped as not daily', str_contains($why['Sheet1'] ?? '', 'Not a daily sheet'));
$byMeter = [];
foreach ($res->rows as $r) {
    $byMeter[$r[0]][$r[1]] = $r[2];
}
check('numeric header became JSB-8001 (meter 8) by its neighbours', isset($byMeter[8]['2030-02-03 03:00:00']) && $byMeter[8]['2030-02-03 03:00:00'] === '123', json_encode($byMeter[8] ?? null));
check('typo header is reported as a note', (bool) array_filter($res->issues, static fn ($i) => $i['kind'] === 'info' && str_contains($i['message'], '17.48')));
check('shifted columns land on the right meters', ($byMeter[6]['2030-02-04 00:00:00'] ?? '') === '200' && ($byMeter[11]['2030-02-04 23:00:00'] ?? '') === '273', json_encode([$byMeter[6]['2030-02-04 00:00:00'] ?? null, $byMeter[11]['2030-02-04 23:00:00'] ?? null]));
$cellIssues = array_values(array_filter($res->issues, static fn ($i) => $i['kind'] === 'cell'));
$sug = array_column($cellIssues, 'suggested', 'raw');
check('11..21 and 23.8/4 go to review, not into the data', count($cellIssues) === 2 && !isset($byMeter[6]['2030-02-03 05:00:00']) && ($sug['11..21'] ?? '') === '11.21' && ($sug['23.8/4'] ?? '') === '23.84', json_encode($cellIssues));
check('blank cells are simply skipped', !isset($byMeter[7]['2030-02-03 02:00:00']));
check('24 hours x 6 feeders per day minus 3 blanks/bad = 285', count($res->rows) === 24 * 6 * 2 - 3, (string) count($res->rows));

// Solar, March 2030 (31 days)
$wb = new Spreadsheet();
$sh = $wb->getActiveSheet()->setTitle('mar page 1');
$sh = $wb->createSheet()->setTitle('MARCH Final');
$sh->setCellValue('A1', 'DAILY SOLAR LOG SHEET ( MAR 2030 )');
$sh->setCellValue('A3', $serial('2030-03-01'))->setCellValue('C3', $serial('2030-03-02'))->setCellValue('E3', $serial('2030-03-03'));
$curve = [0, 3, 40, 120, 230, 330, 410, 430, 4.3, 439, 450, 440, 420, 380, 300, 240, 160, 90, 40, 12, 5, 2, 1, 0, 0];
for ($i = 0; $i < 25; $i++) {
    $sh->setCellValue('A' . (4 + $i), 0.25 + $i / 48)->setCellValue('B' . (4 + $i), $curve[$i]);
    $sh->setCellValue('C' . (4 + $i), 0.25 + $i / 48)->setCellValue('D' . (4 + $i), $curve[$i] + 1);
}
$sh->setCellValue('C12', 10);                      // stray number in a time cell
$sh->setCellValue('F5', 77);                       // a value under day 3
$sh->setCellValue('A29', 'Sub Total');
$wb->createSheet()->setTitle('Sheet1');
$sp = $save($wb, 'solar');
$res = Reader::parse($sp, '2030-03', null, 'solar log mar 2030.xlsx');
check('solar detected, only the Final sheet read', $res->kind === 'solar' && count(array_filter($res->sheets, static fn ($s) => $s['status'] === 'used')) === 1);
$d1 = array_filter($res->rows, static fn ($r) => str_starts_with($r[1], '2030-03-01'));
check('day 1 has 25 half-hour readings from 06:00 to 18:00', count($d1) === 25 && array_values($d1)[0][1] === '2030-03-01 06:00:00' && end($d1)[1] === '2030-03-01 18:00:00', (string) count($d1));
check('day from column pair, slot from row (day 3, 06:30 = 77)', (bool) array_filter($res->rows, static fn ($r) => $r[1] === '2030-03-03 06:30:00' && $r[2] === '77'));
check('stray number in a time cell is noted, the kW beside it still used', (bool) array_filter($res->issues, static fn ($i) => $i['kind'] === 'info' && $i['cell'] === 'C12') && (bool) array_filter($res->rows, static fn ($r) => $r[1] === '2030-03-02 10:30:00'));
$sus = array_values(array_filter($res->issues, static fn ($i) => $i['kind'] === 'suspect' && str_starts_with((string) $i['reading_at'], '2030-03-01')));
check('4.3 between 430 and 439 is flagged with 430 suggested', count($sus) === 1 && $sus[0]['suggested'] === '430', json_encode($sus));
check('flagged value is imported with the SPIKE code', (bool) array_filter($res->rows, static fn ($r) => $r[1] === '2030-03-01 10:00:00' && $r[3] === 'SPIKE'));

// Water & Energy, April 2030 (30 days), 3 days filled
$wb = new Spreadsheet();
$w = $wb->getActiveSheet()->setTitle('Water');
$w->fromArray([[null, null, $serial('2030-04-01'), null, $serial('2030-04-02'), null, $serial('2030-04-03'), null]], null, 'A7');
$w->fromArray([['Line 1', 'Main', 1000, 100, 1100, 90, 1190, null], ['Line 2', null, null, 0, null, 0, null, 0]], null, 'A11');
$e = $wb->createSheet()->setTitle('Electricity');
$e->fromArray([[null, null, $serial('2030-04-01'), null, $serial('2030-04-02'), null, $serial('2030-04-03'), null]], null, 'A7');
$e->fromArray([['Generator 1', null, 17282000, 0, 17282000, 0, 17282000, null], ['Generator 2', null, 14099000, 4300, '14142 000', 4200, 14184000, null]], null, 'A11');
$st = $wb->createSheet()->setTitle('Statistics');
$st->fromArray([['DAY', 'DAILY WATER - M3', 'OTHER DIESEL - LITERS.', 'DAILY ELECTRICITY-KWH', 'POWER HOUSE DIESEL - LITERS', 'KWH / LTR']], null, 'A8');
$st->fromArray([[1, 100, null, 4300, 3500], [2, 90, null, 4200, 2600], [3, null, null, null, null]], null, 'A9');
$wp = $save($wb, 'we');
$res = Reader::parse($wp, '2030-04', null, '01.Water & Energy APRIL 2030.xlsx');
check('water & energy detected', $res->kind === 'water_energy');
$by = [];
foreach ($res->rows as $r) {
    $by[$r[0]][substr($r[1], 0, 10)] = $r[2];
}
check('water line 1 readings only (consumption recomputed)', ($by[60] ?? []) === ['2030-04-01' => '1000', '2030-04-02' => '1100', '2030-04-03' => '1190'], json_encode($by[60] ?? null));
check('empty Line 2 adds nothing', !isset($by[61]));
check('generators land on GEN-1/GEN-2', isset($by[80]['2030-04-01']) && isset($by[81]['2030-04-01']) && $by[81]['2030-04-03'] === '14184000');
$gi = array_values(array_filter($res->issues, static fn ($i) => $i['kind'] === 'cell'));
check('"14142 000" in a counter goes to review with the space dropped as the suggestion', count($gi) === 1 && $gi[0]['suggested'] === '14142000', json_encode($gi));
$base = (float) Reader::dieselBase('2030-04-01');   // the total carries on from whatever diesel reading exists before the month
check('diesel litres become a running total that continues from the last reading (+0, +3500, +2600)', isset($by[90]) && count($by[90]) === 3
    && (float) $by[90]['2030-04-01'] === $base && (float) $by[90]['2030-04-02'] === $base + 3500 && (float) $by[90]['2030-04-03'] === $base + 6100, json_encode($by[90] ?? null) . " base={$base}");
check('the diesel conversion is explained in a note', (bool) array_filter($res->notes, static fn ($n) => str_contains($n, 'running total')));

// ---------------------------------------------------------------- database ----------
echo "Importer (rolled back)\n";
$pdo = DB::pdo();
$pdo->beginTransaction();
try {
    DB::exec("INSERT INTO users (site_id, name, username, password_hash, role) VALUES (1,'T Admin','t_imp_admin',?, 'admin')", [password_hash('x', PASSWORD_DEFAULT)]);
    $user = DB::one("SELECT id, site_id, name, username, role FROM users WHERE username='t_imp_admin'");

    $res = Reader::parse($sp, '2030-03', null, 'solar log mar 2030.xlsx');
    $plan = Importer::plan($res, 'keep');
    check('plan on an empty month: everything is new', $plan['new'] === count($res->rows) && $plan['same'] === 0 && $plan['conflict'] === 0);
    $b = Importer::commit($res, 'keep', $user);
    $row = DB::one('SELECT * FROM import_batches WHERE id = ?', [$b]);
    check('batch records what was added', (int) $row['rows_added'] === count($res->rows));
    check('readings carry source=import and the batch id', (int) DB::val("SELECT COUNT(*) FROM readings WHERE source='import' AND import_batch_id = ?", [$b]) === count($res->rows));
    $tot = DB::one("SELECT energy, slots_filled FROM daily_totals WHERE meter_id = 40 AND day = '2030-03-01'");
    check('daily totals were refreshed', $tot && (int) $tot['slots_filled'] === 25 && (float) $tot['energy'] > 0);
    check('the flagged value carries SPIKE in the database', DB::val("SELECT warning_codes FROM readings WHERE meter_id = 40 AND reading_at = '2030-03-01 10:00:00'") === 'SPIKE');

    $again = Importer::plan($res, 'keep');
    check('importing the same file again adds nothing', $again['new'] === 0 && $again['same'] === count($res->rows));
    $b2 = Importer::commit($res, 'keep', $user);
    check('and does not raise the review items twice', (int) DB::val('SELECT issues FROM import_batches WHERE id = ?', [$b2]) === (int) count(array_filter($res->issues, static fn ($i) => $i['kind'] === 'info')));

    // a different value for an existing slot is kept
    $res2 = clone $res;
    $res2->rows = [[40, '2030-03-01 12:00:00', '999', null, null, null]];
    $res2->issues = [];
    $p = Importer::plan($res2, 'keep');
    check('a differing value is left alone by default', $p['conflict'] === 1 && $p['new'] === 0);
    $p = Importer::plan($res2, 'replace-imported');
    check('replace-imported would replace an untouched imported value', $p['replace'] === 1);
    DB::exec("UPDATE readings SET updated_at = NOW() WHERE meter_id = 40 AND reading_at = '2030-03-01 12:00:00'");
    $p = Importer::plan($res2, 'replace-imported');
    check('...but never one that someone edited afterwards', $p['conflict'] === 1 && $p['replace'] === 0);

    // review: the 4.3 suggestion
    $iss = DB::one("SELECT * FROM import_issues WHERE batch_id = ? AND kind = 'suspect'", [$b]);
    $r = Importer::resolve((int) $iss['id'], 'fix', '11..2', $user);
    check('a non-number correction is refused', !$r['ok']);
    $r = Importer::resolve((int) $iss['id'], 'accept', null, $user);
    $v = DB::one("SELECT value, warning_codes FROM readings WHERE meter_id = 40 AND reading_at = '2030-03-01 10:00:00'");
    check('accepting applies the suggestion (430), clears the flag, writes an audit row', $r['ok'] && (float) $v['value'] === 430.0 && $v['warning_codes'] === null
        && (int) DB::val('SELECT COUNT(*) FROM reading_audit ra JOIN readings r ON r.id = ra.reading_id WHERE r.meter_id = 40 AND r.reading_at = ?', ['2030-03-01 10:00:00']) === 1);
    $r = Importer::resolve((int) $iss['id'], 'accept', null, $user);
    check('an item can only be resolved once', !$r['ok']);

    // review: a cell that was not a number becomes a reading
    $fres = Reader::parse($fp, '2030-02', null, 'Feeder Reading 1.xlsx');
    $fb = Importer::commit($fres, 'keep', $user);
    $cell = DB::one("SELECT * FROM import_issues WHERE batch_id = ? AND raw_value = '11..21'", [$fb]);
    check('the bad cell was not imported', DB::val("SELECT COUNT(*) FROM readings WHERE meter_id = 6 AND reading_at = '2030-02-03 05:00:00'") == 0);
    $r = Importer::resolve((int) $cell['id'], 'accept', null, $user);
    check('accepting its suggestion creates the reading 11.21', $r['ok'] && (float) DB::val("SELECT value FROM readings WHERE meter_id = 6 AND reading_at = '2030-02-03 05:00:00'") === 11.21);
    $other = DB::one("SELECT * FROM import_issues WHERE batch_id = ? AND raw_value = '23.8/4'", [$fb]);
    DB::exec("INSERT INTO readings (meter_id, reading_at, value, entered_by, source) VALUES (9, '2030-02-03 07:00:00', 5, ?, 'app')", [$user['id']]);
    $r = Importer::resolve((int) $other['id'], 'accept', null, $user);
    check('if someone entered that slot meanwhile the item is refused, not overwritten', !$r['ok'] && (float) DB::val("SELECT value FROM readings WHERE meter_id = 9 AND reading_at = '2030-02-03 07:00:00'") === 5.0);

    // undo
    $n = (int) DB::val('SELECT COUNT(*) FROM readings WHERE import_batch_id = ?', [$fb]);
    $u = Importer::undo($fb);
    check('undo removes the batch\'s readings', $u['deleted'] === $n && (int) DB::val('SELECT COUNT(*) FROM readings WHERE import_batch_id = ?', [$fb]) === 0);
    check('undo leaves the app-entered reading alone', (int) DB::val("SELECT COUNT(*) FROM readings WHERE meter_id = 9 AND reading_at = '2030-02-03 07:00:00' AND source = 'app'") === 1);
    check('undo marks the batch and clears its open items', DB::val('SELECT undone_at FROM import_batches WHERE id = ?', [$fb]) !== null && (int) DB::val("SELECT COUNT(*) FROM import_issues WHERE batch_id = ? AND status = 'open'", [$fb]) === 0);
    check('ranges() formats missing days', Importer::ranges([1, 2, 3, 7, 12, 13]) === '1-3, 7, 12-13');
} finally {
    $pdo->rollBack();
    foreach (glob($tmp . '/test-*.xlsx') ?: [] as $f) {
        @unlink($f);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
