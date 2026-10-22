<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Slots;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "solar log <month>.xlsx": the handwriting "page" sheets are ignored, only the "... Final" sheet is read.
 * Layout: one (time, kW) column pair per day, 25 half-hour rows from 06:00. The time cells are often mistyped,
 * so slots come from the row position and the day from the pair position; stray numbers in a time cell are logged.
 */
final class SolarParser
{
    public function parse(Spreadsheet $ss, Result $res, array $plant): void
    {
        $final = $this->findSheet($ss);
        foreach ($ss->getAllSheets() as $sh) {
            if ($final === null || $sh->getTitle() !== $final->getTitle()) {
                $res->sheet($sh->getTitle(), 'skipped', stripos($sh->getTitle(), 'page') !== false ? 'Handwriting page' : 'Not the Final sheet');
            }
        }
        if ($final === null) {
            $res->note('No “Final” solar sheet was found in this workbook.');
            return;
        }

        $lastIdx = min(80, Coordinate::columnIndexFromString($final->getHighestColumn()));
        $lastCol = Coordinate::stringFromColumnIndex($lastIdx);

        // the row of date serials marks the top of the block
        $dateRow = null;
        $top = $final->rangeToArray("A1:{$lastCol}10", null, true, false);
        foreach ($top as $ri => $row) {
            if ((is_int($row[0] ?? null) || is_float($row[0] ?? null)) && $row[0] > 40000) {
                $dateRow = $ri + 1;
                break;
            }
        }
        if ($dateRow === null) {
            $res->sheet($final->getTitle(), 'skipped', 'No row of dates found');
            return;
        }

        $month = new DateTimeImmutable($res->month . '-01', site_tz());
        $slots = Slots::forMeter($plant, $month);
        $n = count($slots);
        $block = $final->rangeToArray("A{$dateRow}:{$lastCol}" . ($dateRow + $n), null, true, false);
        $dim = $res->daysInMonth();
        $cells = 0;
        $dateMismatch = 0;
        $pairs = intdiv($lastIdx, 2);

        for ($p = 0; $p < $pairs; $p++) {
            $day = $p + 1;
            $tc = $p * 2;
            $vc = $tc + 1;
            $serial = $block[0][$tc] ?? null;
            if ($day <= $dim && (is_int($serial) || is_float($serial)) && $serial > 40000) {
                $d = gmdate('Y-m-d', (int) round(((float) $serial - 25569) * 86400));
                if ($d !== sprintf('%s-%02d', $res->month, $day)) {
                    $dateMismatch++;
                }
            }
            for ($i = 0; $i < $n; $i++) {
                $row = $block[$i + 1] ?? [];
                $r = $dateRow + 1 + $i;
                $valAddr = Coordinate::stringFromColumnIndex($vc + 1) . $r;
                $c = CellCleaner::clean($row[$vc] ?? null);

                if ($day > $dim) {
                    if ($c['status'] !== CellCleaner::EMPTY && !($c['status'] === CellCleaner::NUMBER && (float) $c['value'] === 0.0)) {
                        $res->issue('info', $final->getTitle(), $valAddr, $c['raw'], (int) $plant['id'], null, null, "A value in column pair {$day}, but {$res->month} only has {$dim} days. It was not imported.");
                    }
                    continue;
                }
                $at = $slots[$i]->setDate((int) substr($res->month, 0, 4), (int) substr($res->month, 5, 2), $day)->format('Y-m-d H:i:s');

                if ($c['status'] === CellCleaner::NUMBER) {
                    $res->row((int) $plant['id'], $at, $c['value'], null, $final->getTitle(), $valAddr);
                    $cells++;
                } elseif ($c['status'] === CellCleaner::BAD) {
                    $res->issue('cell', $final->getTitle(), $valAddr, $c['raw'], (int) $plant['id'], $at, $c['suggestion'], $c['reason'] . ' (' . $plant['name'] . ', ' . substr($at, 0, 16) . ')');
                }

                // a stray number or text where the time of day should be
                $t = $row[$tc] ?? null;
                if ($t !== null && $t !== '' && !((is_int($t) || is_float($t)) && $t >= 0 && $t < 1)) {
                    $res->issue('info', $final->getTitle(), Coordinate::stringFromColumnIndex($tc + 1) . $r, (string) $t, (int) $plant['id'], $at, null,
                        'Extra entry in the time column next to this slot. The kW cell beside it was used as normal.');
                }
            }
        }

        $res->sheet($final->getTitle(), 'used', '', $cells);
        if ($dateMismatch > 3) {
            $res->note("The date cells at the top of {$final->getTitle()} disagree with {$res->month} in {$dateMismatch} columns. Dates were taken from the month you chose and the column position.");
        }
    }

    private function findSheet(Spreadsheet $ss): ?Worksheet
    {
        foreach ($ss->getAllSheets() as $sh) {
            if (stripos($sh->getTitle(), 'final') !== false) {
                return $sh;
            }
        }
        // no "Final" label (e.g. Oct 2025): take a non-page sheet that carries the solar log title
        foreach ($ss->getAllSheets() as $sh) {
            if (stripos($sh->getTitle(), 'page') === false && stripos((string) $sh->getCell('A1')->getValue(), 'SOLAR LOG') !== false) {
                return $sh;
            }
        }
        return null;
    }
}
