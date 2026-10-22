<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\Meter;
use DateTimeImmutable;

/**
 * Every report returns one generic table so the screen, CSV, Excel and PDF all share it:
 * ['title','subtitle','cols'=>[['label','type'=>'text|num','dp']],'rows'=>[[...]],'foot'=>[[...]],'notes'=>[],'chart'=>?]
 */
final class ReportBuilder
{
    public const REPORTS = [
        'feeders-daily'   => ['Daily feeder report', 'lightning-charge', 'date', '24 hours × every feeder, estimated kWh, peak hour and HV Network vs the sum of feeders.'],
        'solar-daily'     => ['Daily solar report', 'sun', 'date', 'Half-hourly output, estimated energy and the cross-check against the inverter counters.'],
        'monthly'         => ['Water & Energy statistics', 'bar-chart-line', 'month', 'Per day: water, generator kWh, solar kWh, diesel, kWh per litre and solar share.'],
        'feeders-ranking' => ['Feeder consumption ranking', 'sort-down', 'month', 'Estimated kWh per feeder for the month, share of total and change on last month.'],
        'genset'          => ['Genset report', 'fuel-pump-diesel', 'month', 'Hours logged, kWh, average load and power factor and out-of-limit events per generator.'],
        'completeness'    => ['Data completeness', 'clipboard-check', 'date', 'Slots filled against expected, late readings, warnings saved anyway and who entered.'],
    ];

    public static function build(string $slug, string $date, string $month): ?array
    {
        return match ($slug) {
            'feeders-daily'   => self::feedersDaily($date),
            'solar-daily'     => self::solarDaily($date),
            'monthly'         => self::monthly($month),
            'feeders-ranking' => self::ranking($month),
            'genset'          => self::genset($month),
            'completeness'    => self::completeness($date),
            default           => null,
        };
    }

    // ------------------------------------------------------------------------------------

    private static function feedersDaily(string $date): array
    {
        $meters = array_merge(Meter::inGroup(1, false), Meter::inGroup(2, false));
        $meters = array_values(array_filter($meters, static fn ($m) => (int) $m['active'] === 1 || self::hasDay((int) $m['id'], $date)));
        $ids = array_map(static fn ($m) => (int) $m['id'], $meters);

        $vals = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::all("SELECT meter_id, reading_at, value FROM readings WHERE meter_id IN ({$in}) AND reading_at >= ? AND reading_at < DATE_ADD(?, INTERVAL 1 DAY)", array_merge($ids, [$date . ' 00:00:00', $date . ' 00:00:00'])) as $r) {
                $vals[(int) $r['meter_id']][(int) substr($r['reading_at'], 11, 2)] = (float) $r['value'];
            }
        }

        $cols = [['label' => 'Hour', 'type' => 'text']];
        foreach ($meters as $m) {
            $cols[] = ['label' => $m['name'], 'type' => 'num', 'dp' => 1];
        }
        $cols[] = ['label' => 'Σ feeders', 'type' => 'num', 'dp' => 1];
        $cols[] = ['label' => 'HV − Σ', 'type' => 'num', 'dp' => 1];

        $incomer = null;
        foreach ($meters as $m) {
            if ((int) $m['is_incomer'] === 1) {
                $incomer = (int) $m['id'];
            }
        }

        $rows = [];
        for ($h = 0; $h < 24; $h++) {
            $row = [sprintf('%02d:00', $h)];
            $sum = 0.0;
            $any = false;
            foreach ($meters as $m) {
                $v = $vals[(int) $m['id']][$h] ?? null;
                $row[] = $v;
                if ($v !== null && (int) $m['id'] !== $incomer) {
                    $sum += $v;
                    $any = true;
                }
            }
            $hv = $incomer !== null ? ($vals[$incomer][$h] ?? null) : null;
            $row[] = $any ? $sum : null;
            $row[] = ($any && $hv !== null) ? $hv - $sum : null;
            $rows[] = $row;
        }

        // footers from daily_totals
        $tot = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::all("SELECT * FROM daily_totals WHERE day = ? AND meter_id IN ({$in})", array_merge([$date], $ids)) as $t) {
                $tot[(int) $t['meter_id']] = $t;
            }
        }
        $kwh = ['Estimated kWh'];
        $peak = ['Peak hour'];
        $sumKwh = 0.0;
        foreach ($meters as $m) {
            $t = $tot[(int) $m['id']] ?? null;
            $kwh[] = $t ? (float) $t['energy'] : null;
            $peak[] = $t && $t['peak_at'] ? substr($t['peak_at'], 11, 5) : null;
            if ($t && (int) $m['id'] !== $incomer) {
                $sumKwh += (float) $t['energy'];
            }
        }
        $kwh[] = $sumKwh ?: null;
        $kwh[] = null;
        $peak[] = null;
        $peak[] = null;

        return [
            'title'    => 'Daily feeder report',
            'subtitle' => self::longDate($date),
            'cols'     => $cols,
            'rows'     => $rows,
            'foot'     => [$kwh, $peak],
            'notes'    => ['Each value is the instantaneous kW read on the hour; estimated kWh = Σ hourly kW × 1 h. "HV − Σ" is what the feeders do not account for.'],
            'sticky'   => true,
        ];
    }

    private static function solarDaily(string $date): array
    {
        $plants = Meter::ofKind('solar', false);
        $day = self::day($date);
        $slots = $plants ? Slots::forMeter($plants[0], $day) : [];
        $ids = array_map(static fn ($m) => (int) $m['id'], $plants);
        $vals = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::all("SELECT meter_id, reading_at, value FROM readings WHERE meter_id IN ({$in}) AND reading_at >= ? AND reading_at < DATE_ADD(?, INTERVAL 1 DAY)", array_merge($ids, [$date . ' 00:00:00', $date . ' 00:00:00'])) as $r) {
                $vals[(int) $r['meter_id']][substr($r['reading_at'], 11, 5)] = (float) $r['value'];
            }
        }
        $cols = [['label' => 'Time', 'type' => 'text']];
        foreach ($plants as $p) {
            $cols[] = ['label' => $p['name'] . ' (kW)', 'type' => 'num', 'dp' => 1];
        }
        $rows = [];
        foreach ($slots as $s) {
            $row = [$s->format('H:i')];
            foreach ($plants as $p) {
                $row[] = $vals[(int) $p['id']][$s->format('H:i')] ?? null;
            }
            $rows[] = $row;
        }

        $est = ['Estimated kWh'];
        $cnt = ['Inverter counters kWh'];
        $diff = ['Difference %'];
        $peak = ['Peak kW'];
        foreach ($plants as $p) {
            $t = DB::one('SELECT energy, peak_value FROM daily_totals WHERE meter_id = ? AND day = ?', [$p['id'], $date]);
            $c = DB::val(
                'SELECT SUM(d.consumption) FROM daily_totals d JOIN meters m ON m.id = d.meter_id WHERE m.parent_id = ? AND d.day = ?',
                [$p['id'], $date]
            );
            $e = $t ? (float) $t['energy'] : null;
            $c = $c !== null ? (float) $c : null;
            $est[] = $e;
            $cnt[] = $c;
            $diff[] = ($e !== null && $c !== null && $c > 0) ? ($e - $c) / $c * 100 : null;
            $peak[] = $t ? (float) $t['peak_value'] : null;
        }

        return [
            'title'    => 'Daily solar report',
            'subtitle' => self::longDate($date),
            'cols'     => $cols,
            'rows'     => $rows,
            'foot'     => [$est, $cnt, $diff, $peak],
            'notes'    => ['Estimated kWh = Σ half-hourly kW × 0.5 h, checked against the summed inverter kWh counters for the day.'],
            'chart'    => ['labels' => array_map(static fn ($r) => $r[0], $rows), 'series' => array_map(static fn ($i) => ['label' => $cols[$i + 1]['label'], 'data' => array_column($rows, $i + 1)], array_keys($plants))],
            'sticky'   => true,
        ];
    }

    private static function monthly(string $month): array
    {
        $first = $month . '-01';
        $last = (new DateTimeImmutable($first, site_tz()))->modify('last day of this month')->format('Y-m-d');
        $per = [];
        foreach (DB::all(
            "SELECT d.day, m.kind, SUM(d.consumption) c FROM daily_totals d JOIN meters m ON m.id = d.meter_id
             WHERE d.day BETWEEN ? AND ? AND m.kind IN ('water','inverter','generator','fuel') AND d.consumption IS NOT NULL
             GROUP BY d.day, m.kind",
            [$first, $last]
        ) as $r) {
            $per[$r['day']][$r['kind']] = (float) $r['c'];
        }

        $cols = [
            ['label' => 'Date', 'type' => 'text'],
            ['label' => 'Water m³', 'type' => 'num', 'dp' => 0],
            ['label' => 'Generator kWh', 'type' => 'num', 'dp' => 0],
            ['label' => 'Solar kWh', 'type' => 'num', 'dp' => 0],
            ['label' => 'Diesel L', 'type' => 'num', 'dp' => 0],
            ['label' => 'kWh / L', 'type' => 'num', 'dp' => 2],
            ['label' => 'Solar share %', 'type' => 'num', 'dp' => 1],
        ];
        $rows = [];
        $sum = ['water' => 0.0, 'generator' => 0.0, 'inverter' => 0.0, 'fuel' => 0.0];
        $n = ['water' => 0, 'generator' => 0, 'inverter' => 0, 'fuel' => 0];
        $kwhForEff = 0.0;
        $litForEff = 0.0;
        $d = new DateTimeImmutable($first, site_tz());
        $end = new DateTimeImmutable($last, site_tz());
        for (; $d <= $end; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $p = $per[$k] ?? [];
            $w = $p['water'] ?? null;
            $g = $p['generator'] ?? null;
            $s = $p['inverter'] ?? null;
            $f = $p['fuel'] ?? null;
            foreach (['water' => $w, 'generator' => $g, 'inverter' => $s, 'fuel' => $f] as $kind => $v) {
                if ($v !== null) {
                    $sum[$kind] += $v;
                    $n[$kind]++;
                }
            }
            $eff = ($g !== null && $f !== null && $f > 0) ? $g / $f : null;
            if ($eff !== null) {
                $kwhForEff += $g;
                $litForEff += $f;
            }
            $share = ($g !== null && $s !== null && ($g + $s) > 0) ? $s / ($g + $s) * 100 : null;
            $rows[] = [$d->format('D j M'), $w, $g, $s, $f, $eff, $share];
        }
        $avg = static fn ($kind) => $n[$kind] ? $sum[$kind] / $n[$kind] : null;
        $monthShare = ($sum['generator'] + $sum['inverter']) > 0 ? $sum['inverter'] / ($sum['generator'] + $sum['inverter']) * 100 : null;
        $foot = [
            ['Total', $sum['water'] ?: null, $sum['generator'] ?: null, $sum['inverter'] ?: null, $sum['fuel'] ?: null, $litForEff > 0 ? $kwhForEff / $litForEff : null, $monthShare],
            ['Daily average', $avg('water'), $avg('generator'), $avg('inverter'), $avg('fuel'), null, null],
        ];

        return [
            'title'    => 'Water & Energy statistics',
            'subtitle' => (new DateTimeImmutable($first, site_tz()))->format('F Y'),
            'cols'     => $cols,
            'rows'     => $rows,
            'foot'     => $foot,
            'notes'    => [
                'Consumption for a day = next day\'s counter − that day\'s counter, × the meter multiplier. The last day of the month needs the next morning\'s reading.',
                'Solar counts every inverter. Monthly kWh/L = Σ kWh ÷ Σ litres over the days that have both, not the average of daily ratios.',
            ],
            'sticky'   => true,
        ];
    }

    private static function ranking(string $month): array
    {
        $first = $month . '-01';
        $last = (new DateTimeImmutable($first, site_tz()))->modify('last day of this month')->format('Y-m-d');
        $prevFirst = (new DateTimeImmutable($first, site_tz()))->modify('-1 month')->format('Y-m-d');
        $prevLast = (new DateTimeImmutable($prevFirst, site_tz()))->modify('last day of this month')->format('Y-m-d');

        $sql = "SELECT m.id, m.name, m.is_incomer, SUM(d.energy) e, COUNT(*) days FROM daily_totals d JOIN meters m ON m.id = d.meter_id
                WHERE m.kind = 'feeder' AND d.day BETWEEN ? AND ? GROUP BY m.id, m.name, m.is_incomer";
        $cur = DB::all($sql, [$first, $last]);
        $prev = [];
        foreach (DB::all($sql, [$prevFirst, $prevLast]) as $r) {
            $prev[(int) $r['id']] = (float) $r['e'];
        }
        $feeders = array_values(array_filter($cur, static fn ($r) => (int) $r['is_incomer'] === 0));
        usort($feeders, static fn ($a, $b) => (float) $b['e'] <=> (float) $a['e']);
        $total = array_sum(array_map(static fn ($r) => (float) $r['e'], $feeders));

        $rows = [];
        $i = 0;
        foreach ($feeders as $r) {
            $e = (float) $r['e'];
            $p = $prev[(int) $r['id']] ?? null;
            $rows[] = [++$i, $r['name'], $e, $total > 0 ? $e / $total * 100 : null, (int) $r['days'], ($p !== null && $p > 0) ? ($e - $p) / $p * 100 : null];
        }
        return [
            'title'    => 'Feeder consumption ranking',
            'subtitle' => (new DateTimeImmutable($first, site_tz()))->format('F Y'),
            'cols'     => [
                ['label' => '#', 'type' => 'text'], ['label' => 'Feeder', 'type' => 'text'],
                ['label' => 'Est. kWh', 'type' => 'num', 'dp' => 0], ['label' => 'Share %', 'type' => 'num', 'dp' => 1],
                ['label' => 'Days', 'type' => 'num', 'dp' => 0], ['label' => 'vs last month %', 'type' => 'num', 'dp' => 1],
            ],
            'rows'     => $rows,
            'foot'     => [['', 'Total (excl. HV Network)', $total ?: null, $total ? 100.0 : null, null, null]],
            'notes'    => ['Estimated kWh per feeder = Σ hourly kW × 1 h. Change vs last month compares totals, so a month with missing days will look lower.'],
            'bars'     => [
                'labels' => array_column($rows, 1),
                'values' => array_map(static fn ($r) => round((float) $r[2], 1), $rows),
                'share'  => array_map(static fn ($r) => $r[3] === null ? null : round((float) $r[3], 1), $rows),
                'change' => array_map(static fn ($r) => $r[5] === null ? null : round((float) $r[5], 1), $rows),
                'unit'   => 'kWh',
            ],
        ];
    }

    private static function genset(string $month): array
    {
        $first = $month . '-01';
        $end = (new DateTimeImmutable($first, site_tz()))->modify('first day of next month')->format('Y-m-d');
        $rows = [];
        foreach (Meter::ofKind('generator', false) as $m) {
            $r = DB::one(
                'SELECT COUNT(*) hrs, MIN(kwh) kmin, MAX(kwh) kmax, AVG(kw) kw, AVG(pf) pf,
                        SUM(warning_codes LIKE \'%GENSET_LIMIT%\') alarms, MIN(run_hours) hmin, MAX(run_hours) hmax
                 FROM genset_logs WHERE meter_id = ? AND log_at >= ? AND log_at < ?',
                [$m['id'], $first . ' 00:00:00', $end . ' 00:00:00']
            );
            if (!$r || (int) $r['hrs'] === 0) {
                continue;
            }
            $rows[] = [
                $m['name'], (int) $r['hrs'],
                $r['hmax'] !== null ? (float) $r['hmax'] - (float) $r['hmin'] : null,
                $r['kmax'] !== null ? (float) $r['kmax'] - (float) $r['kmin'] : null,
                $r['kw'] !== null ? (float) $r['kw'] : null, $r['pf'] !== null ? (float) $r['pf'] : null, (int) $r['alarms'],
            ];
        }
        return [
            'title'    => 'Genset report',
            'subtitle' => (new DateTimeImmutable($first, site_tz()))->format('F Y'),
            'cols'     => [
                ['label' => 'Generator', 'type' => 'text'], ['label' => 'Hours logged', 'type' => 'num', 'dp' => 0],
                ['label' => 'Running hours (counter)', 'type' => 'num', 'dp' => 1], ['label' => 'kWh (counter)', 'type' => 'num', 'dp' => 0],
                ['label' => 'Avg kW', 'type' => 'num', 'dp' => 1], ['label' => 'Avg PF', 'type' => 'num', 'dp' => 2],
                ['label' => 'Out-of-limit', 'type' => 'num', 'dp' => 0],
            ],
            'rows'     => $rows,
            'foot'     => [],
            'notes'    => ['Counter figures are last minus first reading in the month. Out-of-limit counts hourly logs saved with a limit warning.'],
        ];
    }

    private static function completeness(string $date): array
    {
        $rows = [];
        foreach (Meter::groups() as $g) {
            $meters = Meter::inGroup((int) $g['id']);
            if (!$meters) {
                continue;
            }
            $expected = 0;
            foreach ($meters as $m) {
                $expected += Slots::expected($m);
            }
            $ids = array_map(static fn ($m) => (int) $m['id'], $meters);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $r = DB::one(
                "SELECT COUNT(*) filled, COALESCE(SUM(is_late),0) late, COALESCE(SUM(warning_codes IS NOT NULL),0) warned,
                        GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') who
                 FROM readings r LEFT JOIN users u ON u.id = r.entered_by
                 WHERE r.meter_id IN ({$in}) AND r.reading_at >= ? AND r.reading_at < DATE_ADD(?, INTERVAL 1 DAY)",
                array_merge($ids, [$date . ' 00:00:00', $date . ' 00:00:00'])
            );
            $rows[] = [$g['name'], (int) $r['filled'], $expected, $expected ? (int) $r['filled'] / $expected * 100 : null, (int) $r['late'], (int) $r['warned'], $r['who'] ?: '—'];
        }
        $gen = DB::one(
            "SELECT COUNT(*) filled, COALESCE(SUM(warning_codes IS NOT NULL),0) warned, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') who
             FROM genset_logs g LEFT JOIN users u ON u.id = g.entered_by WHERE g.log_at >= ? AND g.log_at < DATE_ADD(?, INTERVAL 1 DAY)",
            [$date . ' 00:00:00', $date . ' 00:00:00']
        );
        $rows[] = ['Genset log (hourly rows)', (int) $gen['filled'], null, null, 0, (int) $gen['warned'], $gen['who'] ?: '—'];

        return [
            'title'    => 'Data completeness',
            'subtitle' => self::longDate($date),
            'cols'     => [
                ['label' => 'Log', 'type' => 'text'], ['label' => 'Filled', 'type' => 'num', 'dp' => 0], ['label' => 'Expected', 'type' => 'num', 'dp' => 0],
                ['label' => 'Complete %', 'type' => 'num', 'dp' => 0], ['label' => 'Late', 'type' => 'num', 'dp' => 0],
                ['label' => 'Saved with warning', 'type' => 'num', 'dp' => 0], ['label' => 'Entered by', 'type' => 'text'],
            ],
            'rows'     => $rows,
            'foot'     => [],
            'notes'    => ['Expected counts every active meter × every slot for the day, so a half-finished day shows as incomplete.'],
        ];
    }

    private static function hasDay(int $meterId, string $date): bool
    {
        return (bool) DB::val('SELECT 1 FROM daily_totals WHERE meter_id = ? AND day = ?', [$meterId, $date]);
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, site_tz());
    }

    private static function longDate(string $date): string
    {
        return self::day($date)->format('l, j F Y');
    }
}
