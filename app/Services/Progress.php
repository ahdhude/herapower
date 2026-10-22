<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\Meter;
use DateTimeImmutable;

/** "What is still missing" for one day, per log. Feeds the Today screen and /api/today. */
final class Progress
{
    /** @return array{date:string,now:string,logs:array} */
    public static function forDay(DateTimeImmutable $day, ?DateTimeImmutable $now = null): array
    {
        $now ??= now();
        $date = $day->format('Y-m-d');
        $isToday = $date === $now->format('Y-m-d');

        $logs = [];
        foreach (Meter::groupsWithMeters('interval') as $g) {
            if ($g['meters']) {
                $logs[] = self::intervalLog($g, $day, $now, $isToday);
            }
        }
        $logs[] = self::dailyLog($day, $now);
        $logs[] = self::gensetLog($day, $now, $isToday);

        $approvals = [];
        foreach (DB::all('SELECT d.group_id, d.approved_at, u.name FROM day_status d JOIN users u ON u.id = d.approved_by WHERE d.day = ?', [$date]) as $r) {
            $approvals[(int) $r['group_id']] = ['by' => $r['name'], 'at' => $r['approved_at']];
        }
        foreach ($logs as &$l) {
            $gids = $l['approve_groups'];
            $all = $gids && count(array_intersect($gids, array_keys($approvals))) === count($gids);
            $l['approved'] = $all ? $approvals[$gids[0]] : null;
        }
        unset($l);

        return ['date' => $date, 'now' => $now->format('Y-m-d H:i'), 'logs' => $logs];
    }

    private static function intervalLog(array $g, DateTimeImmutable $day, DateTimeImmutable $now, bool $isToday): array
    {
        $meters = $g['meters'];
        $first = $meters[0];
        $slots = Slots::forMeter($first, $day);
        $ids = array_map(static fn ($m) => (int) $m['id'], $meters);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $rows = DB::all(
            "SELECT reading_at, COUNT(*) c, SUM(is_late) late FROM readings
             WHERE meter_id IN ({$in}) AND reading_at >= ? AND reading_at < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY reading_at",
            array_merge($ids, [$day->format('Y-m-d') . ' 00:00:00', $day->format('Y-m-d') . ' 00:00:00'])
        );
        $filled = [];
        foreach ($rows as $r) {
            $filled[substr($r['reading_at'], 11, 5)] = (int) $r['c'];
        }

        $need = count($meters);
        $done = 0;
        $incomplete = [];
        foreach ($slots as $s) {
            $k = $s->format('H:i');
            if (($filled[$k] ?? 0) >= $need) {
                $done++;
            } else {
                $incomplete[] = $k;
            }
        }
        $due = $isToday ? Slots::due($first, $now) : null;
        $dueKey = $due?->format('H:i');
        $elapsed = array_map(static fn ($s) => $s->format('H:i'), Slots::elapsed($first, $day, $now));
        $missed = array_values(array_filter($incomplete, static fn ($k) => in_array($k, $elapsed, true) && $k !== $dueKey));
        $dueOpen = $dueKey !== null && in_array($dueKey, $incomplete, true);

        $unit = $first['interval_min'] == 30 ? 'slots' : 'hours';
        $state = $done === count($slots) ? 'complete' : ($missed ? 'missed' : ($dueOpen ? 'due' : 'ok'));

        $isSolar = $first['kind'] === 'solar';
        $target = $dueOpen ? $dueKey : ($missed[0] ?? ($dueKey ?? ($incomplete[0] ?? $slots[0]->format('H:i'))));
        if (!$isToday && $incomplete) {
            $target = $incomplete[0];
        }
        $href = $isSolar
            ? '/entry/solar?date=' . $day->format('Y-m-d') . '&slot=' . $target
            : '/entry/feeders/' . $g['id'] . '?date=' . $day->format('Y-m-d') . '&hour=' . $target;

        if ($isToday && $due === null) {
            $summary = 'Starts at ' . $slots[0]->format('H:i');
        } elseif ($dueOpen) {
            $summary = $dueKey . ' due';
        } elseif ($isToday) {
            $summary = ($done === count($slots)) ? 'All slots complete' : 'Up to date';
        } else {
            $summary = $done === count($slots) ? 'Complete' : (count($slots) - $done) . ' missing';
        }

        return [
            'key'            => ($isSolar ? 'solar-' : 'feeders-') . $g['id'],
            'kind'           => $isSolar ? 'solar' : 'feeders',
            'title'          => $g['name'],
            'icon'           => $isSolar ? 'sun' : 'lightning-charge',
            'done'           => $done,
            'total'          => count($slots),
            'unit'           => $unit,
            'due'            => $dueKey,
            'due_open'       => $dueOpen,
            'missed'         => count($missed),
            'state'          => $state,
            'summary'        => $summary,
            'detail'         => $done . ' of ' . count($slots) . ' ' . $unit . ' complete' . ($missed ? ' · ' . count($missed) . ' missed' : ''),
            'href'           => $href,
            'lines'          => [],
            'approve_groups' => [(int) $g['id']],
        ];
    }

    private static function dailyLog(DateTimeImmutable $day, DateTimeImmutable $now): array
    {
        $meters = [];
        $groupIds = [];
        foreach (Meter::groupsWithMeters('daily') as $g) {
            foreach ($g['meters'] as $m) {
                $meters[] = (int) $m['id'];
            }
            if ($g['meters']) {
                $groupIds[] = (int) $g['id'];
            }
        }
        $date = $day->format('Y-m-d');
        $done = 0;
        $last = null;
        if ($meters) {
            $in = implode(',', array_fill(0, count($meters), '?'));
            $rows = DB::all(
                "SELECT r.entered_at, u.name FROM readings r LEFT JOIN users u ON u.id = r.entered_by
                 WHERE r.meter_id IN ({$in}) AND r.reading_at = ? ORDER BY r.entered_at DESC",
                array_merge($meters, [$date . ' 00:00:00'])
            );
            $done = count($rows);
            $last = $rows[0] ?? null;
        }
        $total = count($meters);
        $complete = $total > 0 && $done >= $total;
        if ($complete && $last) {
            $summary = 'Done ' . substr($last['entered_at'], 11, 5) . ($last['name'] ? ' by ' . $last['name'] : '');
        } elseif ($done > 0) {
            $summary = $done . ' of ' . $total . ' entered';
        } else {
            $summary = 'Not done';
        }
        $isToday = $date === $now->format('Y-m-d');
        return [
            'key'            => 'daily',
            'kind'           => 'daily',
            'title'          => 'Daily meters',
            'icon'           => 'speedometer2',
            'done'           => $done,
            'total'          => $total,
            'unit'           => 'meters',
            'due'            => null,
            'due_open'       => false,
            'missed'         => 0,
            'state'          => $complete ? 'complete' : ($done > 0 ? 'due' : ($isToday && (int) $now->format('H') >= 9 ? 'missed' : 'ok')),
            'summary'        => $summary,
            'detail'         => 'Water, solar inverters, generators and diesel',
            'href'           => '/entry/daily?date=' . $date,
            'lines'          => [],
            'approve_groups' => $groupIds,
        ];
    }

    private static function gensetLog(DateTimeImmutable $day, DateTimeImmutable $now, bool $isToday): array
    {
        $logDay = $isToday ? Slots::gensetLogDayOf($now) : $day;
        $from = $logDay->setTime(0, 0)->modify('+' . Slots::gensetStartMinutes() . ' minutes');
        $to = $from->modify('+24 hours');

        $lines = [];
        $firstHref = null;
        foreach (Meter::ofKind('generator') as $m) {
            $rows = DB::all(
                'SELECT log_at FROM genset_logs WHERE meter_id = ? AND log_at >= ? AND log_at < ? ORDER BY log_at DESC',
                [$m['id'], $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
            );
            $href = '/entry/genset?meter=' . $m['id'] . '&date=' . $logDay->format('Y-m-d');
            if ($rows) {
                $firstHref ??= $href;
                $lines[] = [
                    'label' => $m['name'],
                    'text'  => count($rows) . ' hour' . (count($rows) === 1 ? '' : 's') . ' logged · last ' . substr($rows[0]['log_at'], 11, 5),
                    'href'  => $href,
                ];
            }
        }
        $running = count($lines);
        return [
            'key'            => 'genset',
            'kind'           => 'genset',
            'title'          => 'Genset log',
            'icon'           => 'fuel-pump-diesel',
            'done'           => $running,
            'total'          => 0,
            'unit'           => 'generators',
            'due'            => null,
            'due_open'       => false,
            'missed'         => 0,
            'state'          => $running ? 'ok' : 'idle',
            'summary'        => $running ? $running . ' running' : 'None logged',
            'detail'         => 'Log day ' . $logDay->format('j M') . ' ' . $from->format('H:i') . ' → ' . $to->format('H:i'),
            'href'           => $firstHref ?? '/entry/genset?date=' . $logDay->format('Y-m-d'),
            'lines'          => $lines,
            'approve_groups' => [0],
        ];
    }
}
