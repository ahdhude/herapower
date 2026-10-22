<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use DateTimeImmutable;

final class ChartController
{
    /** GET /api/chart?meter_ids=40,41&from=Y-m-d&to=Y-m-d&bucket=raw|hour|day|month */
    public function index(Request $req): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $req->query('meter_ids', ''))))));
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $req->query('from', ''), site_tz());
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $req->query('to', ''), site_tz());
        $bucket = (string) $req->query('bucket', 'raw');
        if (!$ids || count($ids) > 30 || !$from || !$to || $to < $from || !in_array($bucket, ['raw', 'hour', 'day', 'month'], true)) {
            return Response::json(['status' => 'error', 'message' => 'meter_ids, from, to and bucket are required.'], 422);
        }
        if ($from->diff($to)->days > 400) {
            return Response::json(['status' => 'error', 'message' => 'Range is limited to about 13 months.'], 422);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $meters = [];
        foreach (DB::all("SELECT id, name, unit, reading_type FROM meters WHERE id IN ({$in})", $ids) as $m) {
            $meters[(int) $m['id']] = $m;
        }

        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        if ($bucket === 'raw' || $bucket === 'hour') {
            $key = $bucket === 'raw' ? 'reading_at' : "DATE_FORMAT(reading_at, '%Y-%m-%d %H:00:00')";
            $agg = $bucket === 'raw' ? 'value' : 'AVG(value)';
            $sql = "SELECT meter_id, {$key} AS k, {$agg} AS v FROM readings
                    WHERE meter_id IN ({$in}) AND reading_at >= ? AND reading_at < DATE_ADD(?, INTERVAL 1 DAY)"
                 . ($bucket === 'hour' ? ' GROUP BY meter_id, k' : '') . ' ORDER BY k';
            $rows = DB::all($sql, array_merge($ids, [$f . ' 00:00:00', $t . ' 00:00:00']));
            $fmt = static fn ($k) => substr((string) $k, 5, 11);
        } else {
            $key = $bucket === 'day' ? 'day' : "DATE_FORMAT(day, '%Y-%m')";
            $sql = "SELECT meter_id, {$key} AS k, SUM(COALESCE(energy, consumption)) AS v FROM daily_totals
                    WHERE meter_id IN ({$in}) AND day BETWEEN ? AND ? GROUP BY meter_id, k ORDER BY k";
            $rows = DB::all($sql, array_merge($ids, [$f, $t]));
            $fmt = static fn ($k) => (string) $k;
        }

        $labels = [];
        foreach ($rows as $r) {
            $labels[$r['k']] = true;
        }
        $keys = array_keys($labels);
        sort($keys);
        $index = array_flip($keys);
        $series = [];
        foreach ($ids as $id) {
            $series[$id] = array_fill(0, count($keys), null);
        }
        foreach ($rows as $r) {
            $series[(int) $r['meter_id']][$index[$r['k']]] = round((float) $r['v'], 3);
        }

        return Response::json([
            'status'   => 'ok',
            'bucket'   => $bucket,
            'labels'   => array_map($fmt, $keys),
            'keys'     => $keys,
            'datasets' => array_map(static fn ($id) => [
                'meter_id' => $id,
                'label'    => $meters[$id]['name'] ?? ('Meter ' . $id),
                'unit'     => $bucket === 'raw' || $bucket === 'hour' ? ($meters[$id]['unit'] ?? '') : '',
                'data'     => $series[$id],
            ], $ids),
        ]);
    }
}
