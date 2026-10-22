<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Slots;
use DateTimeImmutable;

/**
 * The old meter_readings_bulk*.sql export:
 *   INSERT INTO meter_readings (panel_id, reading_time, value, entered_by) VALUES (6, '2025-07-01 00:00:00', 199.1, 4);
 * panel_id is the new meter id (6-31 were kept identical), reading_time is the slot.
 */
final class SqlParser
{
    public function __construct(private MeterIndex $idx)
    {
    }

    public function parse(string $path, Result $res): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            $res->note('The file could not be opened.');
            return;
        }
        $bad = 0;
        $lines = 0;
        while (($line = fgets($fh)) !== false) {
            if (!preg_match('/INSERT\s+INTO\s+`?meter_readings`?\s*\(.*?\)\s*VALUES\s*\(\s*(\d+)\s*,\s*\'([^\']+)\'\s*,\s*([^,\s)]+)/i', $line, $m)) {
                continue;
            }
            $lines++;
            $meter = $this->idx->byId((int) $m[1]);
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[2], site_tz());
            $c = CellCleaner::clean(trim($m[3], "'\""));
            if ($meter === null || !$at || $c['status'] !== CellCleaner::NUMBER || !Slots::isSlot($meter, $at)) {
                $bad++;
                if ($bad <= 200) {
                    $res->issue('cell', basename($path), 'line ' . $lines, trim($line), $meter ? (int) $meter['id'] : null, $at ? $at->format('Y-m-d H:i:s') : null, $c['suggestion'],
                        !$meter ? "Meter {$m[1]} does not exist" : (!$at ? 'Unreadable time' : ($c['status'] !== CellCleaner::NUMBER ? $c['reason'] : 'Time is not on this meter\'s slot grid')));
                }
                continue;
            }
            $res->row((int) $meter['id'], $at->format('Y-m-d H:i:s'), $c['value']);
        }
        fclose($fh);
        $res->sheet(basename($path), 'used', "{$lines} INSERT lines", count($res->rows));
        if ($bad > 200) {
            $res->note(($bad - 200) . ' more lines were rejected and are not listed.');
        }
        if ($res->rows) {
            $times = array_column($res->rows, 1);
            sort($times);
            $res->note('Readings span ' . substr($times[0], 0, 16) . ' to ' . substr(end($times), 0, 16) . '.');
        }
    }
}
