<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Models\Setting;
use DateTimeImmutable;

/**
 * Every rule in spec 2.4. Hard rules go to `errors`, soft rules to `warnings`.
 * Returns ['errors' => [], 'warnings' => [], 'value' => '12.345'|null].
 * Each item: ['code' => 'SPIKE', 'message' => '...'] (+ 'overridable' => true on COUNTER_BACKWARDS).
 */
final class ReadingValidator
{
    public const MAX_VALUE = 99999999999.0; // DECIMAL(14,3)

    /** Plain number, one optional decimal point, nothing else. Returns the trimmed string or null. */
    public static function parseNumber(string $raw): ?string
    {
        $s = trim($raw);
        return preg_match('/^\d+(\.\d+)?$/', $s) === 1 ? $s : null;
    }

    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    /**
     * @param array $opts override(bool) — a supervisor/admin accepted COUNTER_BACKWARDS; now(DateTimeImmutable)
     */
    public function check(array $meter, string $raw, DateTimeImmutable $at, array $user, array $opts = []): array
    {
        $errors = [];
        $warnings = [];
        $now = $opts['now'] ?? now();

        // --- NOT_NUMBER / NEGATIVE -------------------------------------------------------
        $trim = trim($raw);
        if (preg_match('/^-\d+(\.\d+)?$/', $trim) === 1) {
            return $this->result([['code' => 'NEGATIVE', 'message' => 'A reading can\'t be negative.']], [], null);
        }
        $num = self::parseNumber($raw);
        if ($num === null) {
            return $this->result([['code' => 'NOT_NUMBER', 'message' => 'Enter a plain number such as 354 or 12.5.']], [], null);
        }
        $f = (float) $num;
        if ($f > self::MAX_VALUE) {
            return $this->result([['code' => 'NOT_NUMBER', 'message' => 'That number is too large.']], [], null);
        }
        $value = number_format($f, 3, '.', '');

        // --- FUTURE ----------------------------------------------------------------------
        if ($at > $now->modify('+10 minutes')) {
            $errors[] = ['code' => 'FUTURE', 'message' => 'That time hasn\'t happened yet.'];
        }

        // --- OUT_OF_WINDOW (operators: adding missing readings is allowed within the look-back) ---
        if (($user['role'] ?? '') === 'operator') {
            if ($at < Window::addFrom($now)) {
                $errors[] = ['code' => 'OUT_OF_WINDOW', 'message' => 'Operators can add readings up to ' . Window::lookBackDays() . ' days back. Ask a supervisor.'];
            }
        }

        // --- NOT_A_SLOT ------------------------------------------------------------------
        if (!Slots::isSlot($meter, $at)) {
            $errors[] = ['code' => 'NOT_A_SLOT', 'message' => 'That time isn\'t a reading slot for this meter.'];
        }

        if ($errors) {
            return $this->result($errors, [], $value);
        }

        $isCumulative = $meter['reading_type'] === 'cumulative';
        $mult = (float) ($meter['multiplier'] ?? 1);
        $meterId = (int) $meter['id'];
        $atStr = $at->format('Y-m-d H:i:s');

        // --- COUNTER_BACKWARDS -----------------------------------------------------------
        $prev = null;
        if ($isCumulative) {
            $prev = DB::one('SELECT reading_at, value FROM readings WHERE meter_id = ? AND reading_at < ? ORDER BY reading_at DESC LIMIT 1', [$meterId, $atStr]);
            $next = DB::one('SELECT reading_at, value FROM readings WHERE meter_id = ? AND reading_at > ? ORDER BY reading_at ASC LIMIT 1', [$meterId, $atStr]);
            $back = null;
            if ($prev && $f < (float) $prev['value']) {
                $back = 'Lower than the previous reading (' . trim_dec($prev['value']) . ' on ' . substr($prev['reading_at'], 0, 10) . '). Check the meter.';
            } elseif ($next && $f > (float) $next['value']) {
                $back = 'Higher than the next day\'s reading (' . trim_dec($next['value']) . ' on ' . substr($next['reading_at'], 0, 10) . '). Check the meter.';
            }
            if ($back !== null && empty($opts['override'])) {
                $canOverride = Auth::can('override_counter', $user);
                $errors[] = [
                    'code'        => 'COUNTER_BACKWARDS',
                    'message'     => $back . ($canOverride ? '' : ' A supervisor can override if the meter was replaced or reset.'),
                    'overridable' => $canOverride,
                ];
                return $this->result($errors, [], $value);
            }
        }

        // --- MIN_MAX ---------------------------------------------------------------------
        $min = $meter['min_value'] ?? null;
        $max = $meter['max_value'] ?? null;
        if (($min !== null && $f < (float) $min) || ($max !== null && $f > (float) $max)) {
            $warnings[] = [
                'code'    => 'MIN_MAX',
                'message' => 'Outside the usual range (' . ($min !== null ? trim_dec($min) : '…') . ' to ' . ($max !== null ? trim_dec($max) : '…') . ').',
            ];
        }

        // --- SPIKE (interval) ---------------------------------------------------------------
        if (!$isCumulative && Slots::isInterval($meter)) {
            $w = $this->spike($meter, $f, $at);
            if ($w) {
                $warnings[] = $w;
            }
        }

        // --- JUMP (cumulative) ----------------------------------------------------------------
        if ($isCumulative && $prev) {
            $w = $this->jump($meter, $f, $at, $prev, $mult);
            if ($w) {
                $warnings[] = $w;
            }
        }

        return $this->result([], $warnings, $value);
    }

    private function spike(array $meter, float $f, DateTimeImmutable $at): ?array
    {
        $days   = Setting::int('median_days', 14);
        $factor = Setting::float('spike_factor', 3.0);
        $floor  = $meter['kind'] === 'solar' ? Setting::float('spike_floor_solar', 20) : Setting::float('spike_floor_feeder', 1);

        $rows = DB::all(
            'SELECT value FROM readings
             WHERE meter_id = ? AND TIME(reading_at) = ? AND reading_at >= ? AND reading_at < ?',
            [
                (int) $meter['id'],
                $at->format('H:i:s'),
                $at->setTime(0, 0)->modify("-{$days} days")->format('Y-m-d H:i:s'),
                $at->setTime(0, 0)->format('Y-m-d H:i:s'),
            ]
        );
        if (count($rows) < 5) {
            return null;
        }
        $median = self::median(array_map(static fn ($r) => (float) $r['value'], $rows));
        if ($median < $floor) {
            return null;
        }
        if ($f > $factor * $median || $f < $median / $factor) {
            return [
                'code'    => 'SPIKE',
                'message' => 'Far from the usual ' . trim_dec(number_format($median, 1, '.', '')) . ' at this time (median of ' . count($rows) . ' days). Check for a missed decimal.',
            ];
        }
        return null;
    }

    private function jump(array $meter, float $f, DateTimeImmutable $at, array $prev, float $mult): ?array
    {
        // Only compare when the previous reading is exactly the day before.
        $prevDay = substr($prev['reading_at'], 0, 10);
        if ($prevDay !== $at->modify('-1 day')->format('Y-m-d')) {
            return null;
        }
        $cons = ($f - (float) $prev['value']) * $mult;
        $pct = Setting::float('daily_change_pct', 40);

        $rows = DB::all(
            'SELECT consumption FROM daily_totals WHERE meter_id = ? AND day BETWEEN ? AND ? AND consumption IS NOT NULL',
            [(int) $meter['id'], $at->modify('-8 days')->format('Y-m-d'), $at->modify('-2 days')->format('Y-m-d')]
        );
        if (count($rows) < 3) {
            return null;
        }
        $avg = array_sum(array_map(static fn ($r) => (float) $r['consumption'], $rows)) / count($rows);
        if ($avg <= 0) {
            return null;
        }
        $diff = abs($cons - $avg) / $avg * 100;
        if ($diff > $pct) {
            return [
                'code'    => 'JUMP',
                'message' => 'Yesterday\'s use would be ' . number_format($cons, 1) . ' ' . $meter['unit'] . ', ' . round($diff) . '% away from the 7-day average of ' . number_format($avg, 1) . '.',
            ];
        }
        return null;
    }

    private function result(array $errors, array $warnings, ?string $value): array
    {
        return ['errors' => $errors, 'warnings' => $warnings, 'value' => $value];
    }
}
