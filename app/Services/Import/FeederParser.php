<?php
declare(strict_types=1);

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Feeder Reading 1/2.xlsx": one sheet per day, header row of feeder names, 24 hourly rows.
 * Date = chosen month + the first two digits of the sheet name. The date cell inside the sheet is ignored.
 * Columns are matched by header text, so shifted or re-ordered columns still land on the right meter.
 */
final class FeederParser
{
    public function __construct(private MeterIndex $idx)
    {
    }

    public function parse(Spreadsheet $ss, Result $res): void
    {
        $dim = $res->daysInMonth();
        $monthNo = (int) substr($res->month, 5, 2);
        $byDate = [];
        $monthMismatch = 0;
        $dailySheets = 0;

        foreach ($ss->getAllSheets() as $sheet) {
            $name = $sheet->getTitle();
            if (!preg_match('/^\s*(\d{2})/', $name, $mm)) {
                $res->sheet($name, 'skipped', 'Not a daily sheet (name does not start with a day number)');
                continue;
            }
            $day = (int) $mm[1];
            if ($day < 1 || $day > $dim) {
                $res->sheet($name, 'skipped', "Day {$day} does not exist in " . date('F Y', strtotime($res->month . '-01')));
                continue;
            }
            $dailySheets++;
            if (preg_match('/^\s*\d{2}\s*[.\-\/]\s*(\d{2})/', $name, $m2) && (int) $m2[1] !== $monthNo) {
                $monthMismatch++;
            }
            $date = sprintf('%s-%02d', $res->month, $day);
            $parsed = $this->sheet($sheet, $date);
            if ($parsed === null) {
                $res->sheet($name, 'skipped', 'No header row with feeder names found');
                continue;
            }
            $byDate[$date][] = [$name, $parsed];
        }

        foreach ($byDate as $date => $cands) {
            usort($cands, static fn ($a, $b) => $b[1]['cells'] <=> $a[1]['cells']);
            [$name, $best] = $cands[0];
            foreach ($best['rows'] as $r) {
                $res->row($r[0], $r[1], $r[2], null, $name, $r[3]);
            }
            foreach ($best['issues'] as $i) {
                $res->issue($i['kind'], $name, $i['cell'], $i['raw'], $i['meter_id'], $i['reading_at'], $i['suggested'], $i['message']);
            }
            $res->sheet($name, 'used', $best['note'], $best['cells']);
            foreach (array_slice($cands, 1) as [$other]) {
                $res->sheet($other, 'skipped', "Another sheet already supplies {$date}; the one with more readings was used");
            }
        }

        if ($dailySheets > 0 && $monthMismatch > $dailySheets / 2) {
            $res->note("Most sheet names carry a different month than {$res->month}. Dates were taken from the month you chose plus the day number in each sheet name.");
        }
    }

    /** @return array{rows:array,issues:array,cells:int,note:string}|null */
    private function sheet(Worksheet $sheet, string $date): ?array
    {
        $lastColIdx = min(40, Coordinate::columnIndexFromString($sheet->getHighestColumn()));
        $lastCol = Coordinate::stringFromColumnIndex($lastColIdx);
        $top = $sheet->rangeToArray("A1:{$lastCol}8", null, true, false);

        $hdrIdx = null;
        foreach ($top as $ri => $row) {
            $hits = 0;
            foreach ($row as $c) {
                if ($this->idx->find($c, ['feeder'])) {
                    $hits++;
                }
            }
            if ($hits >= 3) {
                $hdrIdx = $ri;
                break;
            }
        }
        if ($hdrIdx === null) {
            return null;
        }
        $hdr = $top[$hdrIdx];
        $hdrRow = $hdrIdx + 1;

        // --- map columns to meters -------------------------------------------------------
        $map = [];      // col index => meter
        $notes = [];
        $issues = [];
        foreach ($hdr as $ci => $h) {
            if ($m = $this->idx->find($h, ['feeder'])) {
                $map[$ci] = $m;
            }
        }
        $groupVotes = [];
        foreach ($map as $m) {
            $groupVotes[(int) $m['group_id']] = ($groupVotes[(int) $m['group_id']] ?? 0) + 1;
        }
        arsort($groupVotes);
        $group = (int) array_key_first($groupVotes);
        $expected = array_values(array_filter($this->idx->inGroup($group), static fn ($m) => $m['kind'] === 'feeder'));
        $used = array_map(static fn ($m) => (int) $m['id'], $map);

        $timeCol = null;
        foreach ($hdr as $ci => $h) {
            if (is_string($h) && MeterIndex::norm($h) === 'TIME') {
                $timeCol = $ci;
            }
        }

        // one typo in a header: a close spelling, or the only meter that fits between its neighbours
        foreach ($hdr as $ci => $h) {
            if (isset($map[$ci]) || $h === null || $h === '' || $ci === $timeCol || (is_string($h) && in_array(MeterIndex::norm($h), ['', 'TIME'], true))) {
                continue;
            }
            $label = is_scalar($h) ? (string) $h : '';
            $m = $this->idx->fuzzy($h, ['feeder']);
            $how = 'a close spelling';
            if ($m === null || in_array((int) $m['id'], $used, true) || (int) $m['group_id'] !== $group) {
                $m = $this->between($map, $ci, $expected, $used);
                $how = 'the only feeder that fits between its neighbours';
            }
            if ($m !== null) {
                $map[$ci] = $m;
                $used[] = (int) $m['id'];
                $addr = Coordinate::stringFromColumnIndex($ci + 1) . $hdrRow;
                $issues[] = ['kind' => 'info', 'cell' => $addr, 'raw' => $label, 'meter_id' => (int) $m['id'], 'reading_at' => null, 'suggested' => null,
                    'message' => "Header “{$label}” was read as {$m['name']} ({$how}). Check the column if the values look wrong."];
            }
        }

        // --- hour of each row -----------------------------------------------------------
        $dataStart = $hdrRow + 1;
        $block = $sheet->rangeToArray("A{$dataStart}:{$lastCol}" . ($dataStart + 23), null, true, false);
        $hours = range(0, 23);
        if ($timeCol !== null) {
            $fromCol = [];
            foreach ($block as $row) {
                $v = $row[$timeCol] ?? null;
                $fromCol[] = (is_int($v) || is_float($v)) && $v >= 0 && $v < 1 ? (int) round($v * 24) % 24 : null;
            }
            if (!in_array(null, $fromCol, true) && count(array_unique($fromCol)) === 24) {
                $hours = $fromCol;
            }
        }

        // --- cells ----------------------------------------------------------------------
        $rows = [];
        $cells = 0;
        foreach ($block as $ri => $row) {
            $hour = $hours[$ri] ?? null;
            if ($hour === null) {
                continue;
            }
            $at = sprintf('%s %02d:00:00', $date, $hour);
            foreach ($map as $ci => $meter) {
                $c = CellCleaner::clean($row[$ci] ?? null);
                if ($c['status'] === CellCleaner::EMPTY) {
                    continue;
                }
                if ($c['status'] === CellCleaner::NUMBER) {
                    $rows[] = [(int) $meter['id'], $at, $c['value'], Coordinate::stringFromColumnIndex($ci + 1) . ($dataStart + $ri)];
                    $cells++;
                    continue;
                }
                $issues[] = [
                    'kind' => 'cell', 'cell' => Coordinate::stringFromColumnIndex($ci + 1) . ($dataStart + $ri), 'raw' => $c['raw'],
                    'meter_id' => (int) $meter['id'], 'reading_at' => $at, 'suggested' => $c['suggestion'],
                    'message' => $c['reason'] . ' (' . $meter['name'] . ', ' . sprintf('%02d:00', $hour) . ')',
                ];
            }
        }

        $note = count($map) . ' feeders';
        return ['rows' => $rows, 'issues' => $issues, 'cells' => $cells, 'note' => $note];
    }

    /** The one expected feeder that sits between the mapped columns on either side of $ci and is not used yet. */
    private function between(array $map, int $ci, array $expected, array $used): ?array
    {
        $pos = static function (array $meter) use ($expected) {
            foreach ($expected as $i => $e) {
                if ((int) $e['id'] === (int) $meter['id']) {
                    return $i;
                }
            }
            return null;
        };
        $left = null;
        $right = null;
        for ($i = $ci - 1; $i >= max(0, $ci - 2); $i--) {
            if (isset($map[$i])) {
                $left = $pos($map[$i]);
                break;
            }
        }
        for ($i = $ci + 1; $i <= $ci + 2; $i++) {
            if (isset($map[$i])) {
                $right = $pos($map[$i]);
                break;
            }
        }
        $cand = null;
        if ($left !== null && isset($expected[$left + 1])) {
            $cand = $expected[$left + 1];
            if ($right !== null && $right !== $left + 2) {
                return null;
            }
        } elseif ($left === null && $right !== null && isset($expected[$right - 1])) {
            $cand = $expected[$right - 1];
        }
        return ($cand !== null && !in_array((int) $cand['id'], $used, true)) ? $cand : null;
    }
}
