<?php
declare(strict_types=1);

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "01.Water & Energy <MONTH>.xlsx": Water / Solar / Electricity sheets hold (meter reading, consumption) column pairs,
 * one pair per day, with a label such as "Line 1", "Inverter 4" or "Generator 2" in column A. Only the meter readings are
 * imported; consumption is recomputed. The Statistics sheet supplies powerhouse diesel litres per day.
 */
final class WaterEnergyParser
{
    /** @param callable(string):float $dieselBase counter value to start the running diesel total from, given the first day (Y-m-d) */
    public function __construct(private MeterIndex $idx, private $dieselBase)
    {
    }

    public function parse(Spreadsheet $ss, Result $res): void
    {
        $found = 0;
        foreach ($ss->getAllSheets() as $sh) {
            $t = strtolower(trim($sh->getTitle()));
            if ($t === 'water' || $t === 'electricity' || $t === 'solar') {
                $cells = $this->counters($sh, $res);
                $res->sheet($sh->getTitle(), 'used', '', $cells);
                $found++;
            } elseif ($t === 'statistics') {
                $this->statistics($sh, $res);
                $found++;
            } else {
                $res->sheet($sh->getTitle(), 'skipped', 'Not a Water & Energy sheet');
            }
        }
        if ($found === 0) {
            $res->note('None of the Water, Solar, Electricity or Statistics sheets were found.');
        }
    }

    /** @return int cells imported */
    private function counters(Worksheet $sh, Result $res): int
    {
        $lastIdx = min(90, Coordinate::columnIndexFromString($sh->getHighestColumn()));
        $lastCol = Coordinate::stringFromColumnIndex($lastIdx);
        $lastRow = min(80, $sh->getHighestRow());
        $grid = $sh->rangeToArray("A1:{$lastCol}{$lastRow}", null, true, false);

        $dateRow = null;
        foreach (array_slice($grid, 0, 14, true) as $ri => $row) {
            for ($c = 2; $c < min(6, count($row)); $c++) {
                if ((is_int($row[$c]) || is_float($row[$c])) && $row[$c] > 40000) {
                    $dateRow = $ri;
                    break 2;
                }
            }
        }
        if ($dateRow === null) {
            $res->note("Sheet “{$sh->getTitle()}” has no row of dates, so it was not read.");
            return 0;
        }

        $dim = $res->daysInMonth();
        $cells = 0;
        $dateMismatch = 0;
        for ($ri = $dateRow + 1; $ri < count($grid); $ri++) {
            $row = $grid[$ri];
            $meter = $this->meterFor((string) ($row[0] ?? ''));
            if ($meter === null) {
                continue;
            }
            for ($p = 0; ($c = 2 + $p * 2) < $lastIdx; $p++) {
                $day = $p + 1;
                $cell = CellCleaner::clean($row[$c] ?? null);
                if ($cell['status'] === CellCleaner::EMPTY) {
                    continue;
                }
                $addr = Coordinate::stringFromColumnIndex($c + 1) . ($ri + 1);
                // the reading in column pair dim+1 is the counter on the 1st of next month
                $date = $day <= $dim ? sprintf('%s-%02d', $res->month, $day) : ($day === $dim + 1 ? date('Y-m-d', strtotime($res->month . '-01 +1 month')) : null);
                if ($date === null) {
                    $res->issue('info', $sh->getTitle(), $addr, $cell['raw'], (int) $meter['id'], null, null, "A reading in column pair {$day}, but {$res->month} only has {$dim} days. It was not imported.");
                    continue;
                }
                $at = $date . ' 00:00:00';
                if ($cell['status'] === CellCleaner::NUMBER) {
                    $res->row((int) $meter['id'], $at, $cell['value']);
                    $cells++;
                } else {
                    $res->issue('cell', $sh->getTitle(), $addr, $cell['raw'], (int) $meter['id'], $at, $cell['suggestion'], $cell['reason'] . ' (' . $meter['name'] . ', ' . $date . ')');
                }
            }
        }
        return $cells;
    }

    private function meterFor(string $label): ?array
    {
        if (!preg_match('/^\s*(line|generator|gen|inverter|inv)\.?\s*0*(\d+)/i', $label, $m)) {
            return null;
        }
        $code = match (strtolower($m[1])) {
            'line' => 'WATER-L' . $m[2],
            'generator', 'gen' => 'GEN-' . $m[2],
            default => 'INV-' . $m[2],
        };
        return $this->idx->find($code);
    }

    private function statistics(Worksheet $sh, Result $res): void
    {
        $lastCol = Coordinate::stringFromColumnIndex(min(20, Coordinate::columnIndexFromString($sh->getHighestColumn())));
        $grid = $sh->rangeToArray("A1:{$lastCol}" . min(60, $sh->getHighestRow()), null, true, false);

        $hdrRow = null;
        $col = null;
        $other = null;
        foreach (array_slice($grid, 0, 20, true) as $ri => $row) {
            foreach ($row as $ci => $h) {
                $k = is_string($h) ? MeterIndex::norm($h) : '';
                if (str_contains($k, 'POWERHOUSE') && str_contains($k, 'DIESEL')) {
                    $hdrRow = $ri;
                    $col = $ci;
                }
                if (str_contains($k, 'OTHERDIESEL')) {
                    $other = $ci;
                }
            }
            if ($hdrRow !== null) {
                break;
            }
        }
        $fuel = $this->idx->find('DIESEL-PH');
        if ($hdrRow === null || $fuel === null) {
            $res->sheet($sh->getTitle(), 'skipped', 'No “power house diesel” column found');
            return;
        }

        $dim = $res->daysInMonth();
        $litres = [];
        for ($d = 1; $d <= $dim; $d++) {
            $row = $grid[$hdrRow + $d] ?? null;
            if ($row === null) {
                break;
            }
            $c = CellCleaner::clean($row[$col] ?? null);
            $addr = Coordinate::stringFromColumnIndex($col + 1) . ($hdrRow + $d + 1);
            if ($c['status'] === CellCleaner::NUMBER) {
                $litres[$d] = (float) $c['value'];
            } elseif ($c['status'] === CellCleaner::BAD) {
                $res->issue('info', $sh->getTitle(), $addr, $c['raw'], (int) $fuel['id'], null, $c['suggestion'], 'Diesel litres for day ' . $d . ' is not a number, so the running total stops before it.');
                break;
            } else {
                break;
            }
        }
        if (!$litres) {
            $res->sheet($sh->getTitle(), 'used', 'No diesel litres filled in', 0);
            return;
        }

        $first = $res->month . '-01';
        $running = (float) ($this->dieselBase)($first);
        $at = static fn (int $d): string => sprintf('%s-%02d 00:00:00', substr($first, 0, 7), $d);
        $res->row((int) $fuel['id'], $at(1), self::num($running));
        $n = 0;
        foreach ($litres as $d => $l) {
            $running += $l;
            $next = $d + 1 <= $dim ? $at($d + 1) : date('Y-m-d', strtotime($first . ' +1 month')) . ' 00:00:00';
            $res->row((int) $fuel['id'], $next, self::num($running));
            $n++;
        }
        $res->note("Diesel: the old sheets record litres used per day, not a flow-meter reading, so {$n} days were turned into a running total (the counter starts at " . self::num((float) ($this->dieselBase)($first)) . '). Daily use and kWh/L come out exactly as on the sheet. When real flow-meter readings start, the first one may need a supervisor “meter replaced / reset” override.');
        if ($other !== null) {
            $res->note('The “Other diesel” column was not imported (that meter is switched off).');
        }
        $res->sheet($sh->getTitle(), 'used', 'Diesel litres', $n);
    }

    private static function num(float $f): string
    {
        return rtrim(rtrim(sprintf('%.3F', $f), '0'), '.') ?: '0';
    }
}
