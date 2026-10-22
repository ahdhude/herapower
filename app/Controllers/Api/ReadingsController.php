<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Services\ReadingValidator;
use App\Services\Slots;
use DateTimeImmutable;

final class ReadingsController
{
    /** GET /api/slots?group_id&date&time — meters in walk order with the value at one slot. */
    public function slots(Request $req): Response
    {
        $group = Meter::group((int) $req->query('group_id', 0));
        $day = $this->day((string) $req->query('date', ''));
        $time = (string) $req->query('time', '');
        if (!$group || !$day || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return Response::json(['status' => 'error', 'message' => 'group_id, date (Y-m-d) and time (HH:MM) are required.'], 422);
        }
        $at = Reading::parseAt($day->format('Y-m-d') . ' ' . $time);
        $meters = Meter::inGroup((int) $group['id']);
        $cells = Reading::groupDay((int) $group['id'], $day->format('Y-m-d'));
        $out = [];
        foreach ($meters as $m) {
            $step = (int) ($m['interval_min'] ?? 60);
            $prevKey = $at->modify("-{$step} minutes");
            $prev = $prevKey->format('Y-m-d') === $day->format('Y-m-d')
                ? ($cells[(int) $m['id']][$prevKey->format('H:i')]['value'] ?? null)
                : DB::val('SELECT value FROM readings WHERE meter_id = ? AND reading_at = ?', [$m['id'], $prevKey->format('Y-m-d H:i:s')]);
            $cur = $cells[(int) $m['id']][$time] ?? null;
            $out[] = [
                'meter_id'   => (int) $m['id'],
                'code'       => $m['code'],
                'name'       => $m['name'],
                'unit'       => $m['unit'],
                'is_incomer' => (bool) $m['is_incomer'],
                'value'      => $cur ? trim_dec($cur['value']) : null,
                'prev_value' => $prev !== null ? trim_dec($prev) : null,
                'median'     => $this->median($m, $at),
                'entered_by' => $cur['entered_by_name'] ?? null,
                'is_late'    => $cur ? (bool) $cur['is_late'] : false,
            ];
        }
        return Response::json(['status' => 'ok', 'group_id' => (int) $group['id'], 'reading_at' => $at->format('Y-m-d H:i:s'), 'meters' => $out]);
    }

    /** GET /api/grid?group_id&date — the whole day for one group (phone list and PC grid share it). */
    public function grid(Request $req): Response
    {
        $group = Meter::group((int) $req->query('group_id', 0));
        $day = $this->day((string) $req->query('date', ''));
        if (!$group || !$day) {
            return Response::json(['status' => 'error', 'message' => 'group_id and date (Y-m-d) are required.'], 422);
        }
        $date = $day->format('Y-m-d');
        $meters = Meter::inGroup((int) $group['id']);
        if (!$meters) {
            return Response::json(['status' => 'ok', 'group' => ['id' => (int) $group['id'], 'name' => $group['name']], 'date' => $date, 'slots' => [], 'meters' => [], 'cells' => (object) []]);
        }
        $slots = array_map(static fn ($s) => $s->format('H:i'), Slots::forMeter($meters[0], $day));
        $cells = Reading::groupDay((int) $group['id'], $date);

        $outCells = [];
        $prevDayLast = [];
        foreach ($meters as $m) {
            $id = (int) $m['id'];
            foreach ($cells[$id] ?? [] as $k => $r) {
                $outCells[$id][$k] = [
                    'value' => trim_dec($r['value']),
                    'late'  => (bool) $r['is_late'],
                    'codes' => $r['warning_codes'],
                    'note'  => $r['note'],
                    'by'    => $r['entered_by_name'],
                ];
            }
            $last = DB::val(
                'SELECT value FROM readings WHERE meter_id = ? AND reading_at < ? ORDER BY reading_at DESC LIMIT 1',
                [$id, $date . ' 00:00:00']
            );
            $prevDayLast[$id] = $last !== null ? trim_dec($last) : null;
        }

        $now = now();
        $due = $date === $now->format('Y-m-d') ? Slots::due($meters[0], $now) : null;

        return Response::json([
            'status'        => 'ok',
            'group'         => ['id' => (int) $group['id'], 'name' => $group['name']],
            'date'          => $date,
            'now'           => $now->format('Y-m-d H:i'),
            'due'           => $due?->format('H:i'),
            'intervalMin'   => (int) $meters[0]['interval_min'],
            'slots'         => $slots,
            'meters'        => array_map(static fn ($m) => [
                'id'         => (int) $m['id'],
                'code'       => $m['code'],
                'name'       => $m['name'],
                'unit'       => $m['unit'],
                'is_incomer' => (bool) $m['is_incomer'],
                'parent_id'  => $m['parent_id'] ? (int) $m['parent_id'] : null,
            ], $meters),
            'cells'         => $outCells ?: (object) [],
            'prev_day_last' => $prevDayLast,
        ]);
    }

    /** GET /api/daily?date — every active cumulative meter with today's and the previous reading. */
    public function daily(Request $req): Response
    {
        $day = $this->day((string) $req->query('date', ''));
        if (!$day) {
            return Response::json(['status' => 'error', 'message' => 'date (Y-m-d) is required.'], 422);
        }
        $date = $day->format('Y-m-d') . ' 00:00:00';
        $groups = [];
        foreach (Meter::groupsWithMeters('daily') as $g) {
            if (!$g['meters']) {
                continue;
            }
            $rows = [];
            foreach ($g['meters'] as $m) {
                $cur = Reading::find((int) $m['id'], $date);
                $prev = DB::one(
                    'SELECT reading_at, value FROM readings WHERE meter_id = ? AND reading_at < ? ORDER BY reading_at DESC LIMIT 1',
                    [$m['id'], $date]
                );
                $rows[] = [
                    'id'         => (int) $m['id'],
                    'name'       => $m['name'],
                    'unit'       => $m['unit'],
                    'multiplier' => (float) $m['multiplier'],
                    'kind'       => $m['kind'],
                    'value'      => $cur ? trim_dec($cur['value']) : null,
                    'warning'    => $cur['warning_codes'] ?? null,
                    'prev_value' => $prev ? trim_dec($prev['value']) : null,
                    'prev_date'  => $prev ? substr($prev['reading_at'], 0, 10) : null,
                ];
            }
            $groups[] = ['id' => (int) $g['id'], 'name' => $g['name'], 'meters' => $rows];
        }
        return Response::json([
            'status'    => 'ok',
            'date'      => $day->format('Y-m-d'),
            'yesterday' => $day->modify('-1 day')->format('Y-m-d'),
            'groups'    => $groups,
        ]);
    }

    /** POST /api/readings */
    public function save(Request $req): Response
    {
        $res = Reading::save($req->body(), Auth::user());
        return Response::json($res, $res['status'] === 'error' ? 422 : 200);
    }

    /** POST /api/readings/batch  {items:[...]} — used by the offline queue. */
    public function batch(Request $req): Response
    {
        $items = $req->body()['items'] ?? [];
        if (!is_array($items) || count($items) > 200) {
            return Response::json(['status' => 'error', 'message' => 'items must be a list of at most 200.'], 422);
        }
        $results = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $res = Reading::save($it, Auth::user());
            $res['client_uuid'] = $it['client_uuid'] ?? null;
            $results[] = $res;
        }
        return Response::json(['status' => 'ok', 'results' => $results]);
    }

    private function day(string $s): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, site_tz());
        return ($d && $d->format('Y-m-d') === $s) ? $d : null;
    }

    private function median(array $meter, DateTimeImmutable $at): ?string
    {
        $days = Setting::int('median_days', 14);
        $rows = DB::all(
            'SELECT value FROM readings WHERE meter_id = ? AND TIME(reading_at) = ? AND reading_at >= ? AND reading_at < ?',
            [$meter['id'], $at->format('H:i:s'), $at->setTime(0, 0)->modify("-{$days} days")->format('Y-m-d H:i:s'), $at->setTime(0, 0)->format('Y-m-d H:i:s')]
        );
        if (count($rows) < 5) {
            return null;
        }
        return trim_dec(number_format(ReadingValidator::median(array_map(static fn ($r) => (float) $r['value'], $rows)), 3, '.', ''));
    }
}
