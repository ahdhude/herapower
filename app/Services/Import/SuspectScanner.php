<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * Catches numbers that parse fine but look like a missed or extra decimal point (e.g. 3.54 between 239 and 497).
 * The value is imported and flagged SPIKE; a review item carries a suggested replacement that a person must accept.
 */
final class SuspectScanner
{
    private const FLOOR = ['solar' => 20.0, 'feeder' => 2.0];

    public static function scan(Result $res, MeterIndex $idx): int
    {
        if (!in_array($res->kind, ['feeders', 'solar', 'sql'], true)) {
            return 0;
        }
        // series per meter and day, in time order
        $series = [];
        foreach ($res->rows as $i => $r) {
            $series[$r[0] . '|' . substr($r[1], 0, 10)][] = $i;
        }
        $flagged = 0;
        foreach ($series as $key => $idxs) {
            [$mid] = explode('|', $key);
            $meter = $idx->byId((int) $mid);
            if (!$meter || $meter['reading_type'] !== 'interval' || count($idxs) < 3) {
                continue;
            }
            $floor = self::FLOOR[$meter['kind']] ?? 5.0;
            $step = ((int) $meter['interval_min']) * 60;
            usort($idxs, static fn ($a, $b) => strcmp($res->rows[$a][1], $res->rows[$b][1]));

            for ($k = 1; $k < count($idxs) - 1; $k++) {
                $row = $res->rows[$idxs[$k]];
                $prev = $res->rows[$idxs[$k - 1]];
                $next = $res->rows[$idxs[$k + 1]];
                $t = strtotime($row[1]);
                if ($t - strtotime($prev[1]) > $step || strtotime($next[1]) - $t > $step) {
                    continue; // neighbours are not the adjacent slots
                }
                $v = (float) $row[2];
                $p = (float) $prev[2];
                $n = (float) $next[2];
                $lo = min($p, $n);
                $hi = max($p, $n);
                // a stable local level (both neighbours agree), an order-of-magnitude gap, and a shifted decimal point that fits
                if ($lo <= 0 || $hi / $lo > 2.5) {
                    continue;
                }
                $low = $lo >= $floor && $v <= $lo / 5;
                $high = $hi >= 1 && $v >= $floor && $v >= 5 * $hi;
                if (!$low && !$high) {
                    continue;
                }
                $sug = self::suggest($row[2], $lo, $hi, $low);
                if ($sug === null) {
                    continue; // genuine swings (a machine starting up) have no decimal explanation: leave them alone
                }
                $res->rows[$idxs[$k]][3] = 'SPIKE';
                $res->issue(
                    'suspect', (string) ($row[4] ?? ''), (string) ($row[5] ?? ''), $row[2], (int) $mid, $row[1], $sug,
                    sprintf('%s at %s looks off: %s between %s and %s. Possibly a missed decimal point.', $meter['name'], substr($row[1], 0, 16), $row[2], self::fmt($p), self::fmt($n))
                );
                $flagged++;
            }
        }
        return $flagged;
    }

    private static function suggest(string $v, float $lo, float $hi, bool $low): ?string
    {
        $mean = ($lo + $hi) / 2;
        $cands = [];
        if ($low) {
            if (str_contains($v, '.')) {
                $cands[] = str_replace('.', '', ltrim($v, '0') === '' ? '0' : $v); // 3.54 -> 354
            }
            $cands[] = self::fmt((float) $v * 10);
            $cands[] = self::fmt((float) $v * 100);
        } else {
            $cands[] = self::fmt((float) $v / 10);
            $cands[] = self::fmt((float) $v / 100);
        }
        foreach ($cands as $c) {
            if (is_numeric($c) && (float) $c >= $mean * 0.7 && (float) $c <= $mean * 1.3 && (float) $c > 0) {
                return $c;
            }
        }
        return null;
    }

    private static function fmt(float $f): string
    {
        return rtrim(rtrim(sprintf('%.3F', $f), '0'), '.') ?: '0';
    }
}
