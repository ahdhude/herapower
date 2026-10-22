<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class Meter
{
    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM meters WHERE id = ?', [$id]);
    }

    public static function groups(): array
    {
        return DB::all('SELECT * FROM meter_groups ORDER BY sort_order, id');
    }

    public static function group(int $id): ?array
    {
        return DB::one('SELECT * FROM meter_groups WHERE id = ?', [$id]);
    }

    /** Meters of one group in walk order. */
    public static function inGroup(int $groupId, bool $activeOnly = true): array
    {
        return DB::all(
            'SELECT * FROM meters WHERE group_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort_order, id',
            [$groupId]
        );
    }

    /** Active meters of a kind, in walk order. */
    public static function ofKind(string $kind, bool $activeOnly = true): array
    {
        return DB::all(
            'SELECT * FROM meters WHERE kind = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY group_id, sort_order, id',
            [$kind]
        );
    }

    /** Active groups of an entry_type with their active meters attached. */
    public static function groupsWithMeters(string $entryType): array
    {
        $groups = DB::all('SELECT * FROM meter_groups WHERE entry_type = ? ORDER BY sort_order, id', [$entryType]);
        foreach ($groups as &$g) {
            $g['meters'] = self::inGroup((int) $g['id']);
        }
        return $groups;
    }

    public static function hasReadings(int $id): bool
    {
        return (bool) DB::val('SELECT 1 FROM readings WHERE meter_id = ? LIMIT 1', [$id])
            || (bool) DB::val('SELECT 1 FROM genset_logs WHERE meter_id = ? LIMIT 1', [$id]);
    }

    /** Next free id: inside the conventional range for the kind if possible, else above everything. */
    public static function nextId(string $kind): int
    {
        $ranges = [
            'feeder' => [6, 39], 'solar' => [40, 50], 'inverter' => [51, 59], 'water' => [60, 79],
            'generator' => [80, 89], 'fuel' => [90, 99],
        ];
        [$lo, $hi] = $ranges[$kind] ?? [100, 100];
        $used = array_map('intval', array_column(DB::all('SELECT id FROM meters WHERE id BETWEEN ? AND ?', [$lo, $hi]), 'id'));
        for ($i = $lo; $i <= $hi; $i++) {
            if (!in_array($i, $used, true)) {
                return $i;
            }
        }
        return max(100, (int) DB::val('SELECT MAX(id) FROM meters') + 1);
    }
}
