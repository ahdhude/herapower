<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use DateTimeImmutable;

/**
 * The only place that does slot-grid arithmetic.
 * Interval meters read on a fixed grid inside a window; cumulative meters have one slot a day at 00:00.
 */
final class Slots
{
    public static function minutes(?string $time): int
    {
        if ($time === null || $time === '') {
            return 0;
        }
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    public static function isInterval(array $meter): bool
    {
        return !empty($meter['interval_min']);
    }

    /** @return DateTimeImmutable[] every slot of $day for the meter */
    public static function forMeter(array $meter, DateTimeImmutable $day): array
    {
        $d = $day->setTime(0, 0);
        if (!self::isInterval($meter)) {
            return [$d];
        }
        $start = self::minutes($meter['window_start'] ?? '00:00');
        $end   = self::minutes($meter['window_end'] ?? '23:59');
        $step  = (int) $meter['interval_min'];
        $out = [];
        for ($m = $start; $m <= $end; $m += $step) {
            $out[] = $d->setTime(intdiv($m, 60), $m % 60);
        }
        return $out;
    }

    public static function expected(array $meter): int
    {
        if (!self::isInterval($meter)) {
            return 1;
        }
        $start = self::minutes($meter['window_start'] ?? '00:00');
        $end   = self::minutes($meter['window_end'] ?? '23:59');
        return intdiv($end - $start, (int) $meter['interval_min']) + 1;
    }

    public static function isSlot(array $meter, DateTimeImmutable $at): bool
    {
        if ($at->format('s') !== '00') {
            return false;
        }
        $m = (int) $at->format('H') * 60 + (int) $at->format('i');
        if (!self::isInterval($meter)) {
            return $m === 0;
        }
        $start = self::minutes($meter['window_start'] ?? '00:00');
        $end   = self::minutes($meter['window_end'] ?? '23:59');
        return $m >= $start && $m <= $end && (($m - $start) % (int) $meter['interval_min']) === 0;
    }

    /** Latest slot at or before $now on $now's own day; null if the window hasn't opened yet. */
    public static function due(array $meter, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $due = null;
        foreach (self::forMeter($meter, $now) as $slot) {
            if ($slot <= $now) {
                $due = $slot;
            }
        }
        return $due;
    }

    /** Slots of $day that are already in the past (or current) at $now. */
    public static function elapsed(array $meter, DateTimeImmutable $day, DateTimeImmutable $now): array
    {
        return array_values(array_filter(self::forMeter($meter, $day), static fn ($s) => $s <= $now));
    }

    // ---- generator set log: log day runs 08:00 -> 07:00 next day --------------------------

    public static function gensetStartMinutes(): int
    {
        return self::minutes(Setting::get('genset_day_start', '08:00'));
    }

    /** Date of the log day that contains $at. */
    public static function gensetLogDayOf(DateTimeImmutable $at): DateTimeImmutable
    {
        $m = (int) $at->format('H') * 60 + (int) $at->format('i');
        $d = $at->setTime(0, 0);
        return $m < self::gensetStartMinutes() ? $d->modify('-1 day') : $d;
    }

    /** @return DateTimeImmutable[] 24 hourly slots of a log day */
    public static function gensetSlots(DateTimeImmutable $logDay): array
    {
        $start = $logDay->setTime(0, 0)->modify('+' . self::gensetStartMinutes() . ' minutes');
        $out = [];
        for ($i = 0; $i < 24; $i++) {
            $out[] = $start->modify("+{$i} hours");
        }
        return $out;
    }

    public static function isGensetSlot(DateTimeImmutable $at): bool
    {
        return $at->format('i') === '00' && $at->format('s') === '00';
    }
}
