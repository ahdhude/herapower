<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use DateTimeImmutable;

/**
 * Which days an operator may touch.
 *  - look-back  (Settings → view_back_days, default 90): they may open these days and ADD readings that are missing;
 *  - change     (Settings → operator_edit_days, default 1): they may also CHANGE a saved reading from this day on.
 * Supervisors and admins are not limited by either.
 */
final class Window
{
    /** Days an operator or viewer can look back, and operators can add missing readings. Never shorter than the change window. */
    public static function lookBackDays(): int
    {
        return max(Setting::int('view_back_days', 90), Setting::int('operator_edit_days', 1));
    }

    /** Earliest day an operator may add a missing reading. */
    public static function addFrom(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? now())->setTime(0, 0)->modify('-' . self::lookBackDays() . ' days');
    }

    /** Earliest day from which an operator may change a reading that is already saved. */
    public static function changeFrom(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? now())->setTime(0, 0)->modify('-' . Setting::int('operator_edit_days', 1) . ' days');
    }
}
