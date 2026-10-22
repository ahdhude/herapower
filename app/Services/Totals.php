<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\Meter;

/** Maintains daily_totals (spec 2.3). Called inside the reading-save transaction. */
final class Totals
{
    public static function refresh(int $meterId, string $day): void
    {
        $meter = Meter::find($meterId);
        if (!$meter) {
            return;
        }
        if ($meter['reading_type'] === 'interval') {
            self::refreshInterval($meter, $day);
        } else {
            self::refreshCumulative($meter, $day);
        }
    }

    /** Recompute every meter for one day (nightly job / repair). */
    public static function refreshDay(string $day): int
    {
        $n = 0;
        foreach (DB::all('SELECT id FROM meters') as $m) {
            self::refresh((int) $m['id'], $day);
            $n++;
        }
        return $n;
    }

    private static function refreshInterval(array $meter, string $day): void
    {
        $rows = DB::all(
            'SELECT reading_at, value FROM readings WHERE meter_id = ? AND reading_at >= ? AND reading_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY reading_at',
            [$meter['id'], $day . ' 00:00:00', $day . ' 00:00:00']
        );
        if (!$rows) {
            DB::exec('DELETE FROM daily_totals WHERE meter_id = ? AND day = ?', [$meter['id'], $day]);
            return;
        }
        $total = 0.0;
        $peak = -1.0;
        $peakAt = null;
        foreach ($rows as $r) {
            $v = (float) $r['value'];
            $total += $v;
            if ($v > $peak) {
                $peak = $v;
                $peakAt = $r['reading_at'];
            }
        }
        $hours = ((int) $meter['interval_min']) / 60;
        self::upsert((int) $meter['id'], $day, [
            'total'          => $total,
            'energy'         => $total * $hours,
            'consumption'    => null,
            'avg_value'      => $total / count($rows),
            'peak_value'     => $peak,
            'peak_at'        => $peakAt,
            'slots_filled'   => count($rows),
            'slots_expected' => Slots::expected($meter),
        ]);
    }

    private static function refreshCumulative(array $meter, string $day): void
    {
        $r0 = DB::val('SELECT value FROM readings WHERE meter_id = ? AND reading_at = ?', [$meter['id'], $day . ' 00:00:00']);
        $r1 = DB::val(
            'SELECT value FROM readings WHERE meter_id = ? AND reading_at = DATE_ADD(?, INTERVAL 1 DAY)',
            [$meter['id'], $day . ' 00:00:00']
        );
        if ($r0 === null && $r1 === null) {
            DB::exec('DELETE FROM daily_totals WHERE meter_id = ? AND day = ?', [$meter['id'], $day]);
            return;
        }
        $consumption = null;
        if ($r0 !== null && $r1 !== null && (float) $r1 >= (float) $r0) {
            $consumption = ((float) $r1 - (float) $r0) * (float) $meter['multiplier'];
        }
        self::upsert((int) $meter['id'], $day, [
            'total'          => null,
            'energy'         => null,
            'consumption'    => $consumption,
            'avg_value'      => null,
            'peak_value'     => null,
            'peak_at'        => null,
            'slots_filled'   => $r0 !== null ? 1 : 0,
            'slots_expected' => 1,
        ]);
    }

    private static function upsert(int $meterId, string $day, array $f): void
    {
        DB::exec(
            'INSERT INTO daily_totals (meter_id, day, total, energy, consumption, avg_value, peak_value, peak_at, slots_filled, slots_expected, refreshed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE total = VALUES(total), energy = VALUES(energy), consumption = VALUES(consumption),
               avg_value = VALUES(avg_value), peak_value = VALUES(peak_value), peak_at = VALUES(peak_at),
               slots_filled = VALUES(slots_filled), slots_expected = VALUES(slots_expected), refreshed_at = VALUES(refreshed_at)',
            [
                $meterId, $day, $f['total'], $f['energy'], $f['consumption'], $f['avg_value'], $f['peak_value'], $f['peak_at'],
                $f['slots_filled'], $f['slots_expected'], date('Y-m-d H:i:s'),
            ]
        );
    }
}
