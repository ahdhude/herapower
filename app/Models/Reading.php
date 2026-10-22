<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\DB;
use App\Services\ReadingValidator;
use App\Services\Slots;
use App\Services\Totals;
use DateTimeImmutable;

final class Reading
{
    /** Accepts "2026-10-15 14:00", "2026-10-15 14:00:00" and "2026-10-15T14:00". */
    public static function parseAt(?string $s): ?DateTimeImmutable
    {
        if ($s === null) {
            return null;
        }
        $s = str_replace('T', ' ', trim($s));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!' . $fmt, $s, site_tz());
            if ($d && $d->format($fmt) === $s) {
                return $d;
            }
        }
        return null;
    }

    public static function present(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'meter_id'      => (int) $r['meter_id'],
            'reading_at'    => $r['reading_at'],
            'value'         => trim_dec($r['value']),
            'entered_by'    => (int) $r['entered_by'],
            'entered_at'    => $r['entered_at'],
            'is_late'       => (bool) $r['is_late'],
            'warning_codes' => $r['warning_codes'],
            'note'          => $r['note'],
        ];
    }

    public static function find(int $meterId, string $at): ?array
    {
        return DB::one('SELECT * FROM readings WHERE meter_id = ? AND reading_at = ?', [$meterId, $at]);
    }

    /** Readings of every meter in a group for one day, keyed [meter_id][HH:MM]. */
    public static function groupDay(int $groupId, string $day): array
    {
        $rows = DB::all(
            'SELECT r.*, u.name AS entered_by_name FROM readings r
             JOIN meters m ON m.id = r.meter_id
             LEFT JOIN users u ON u.id = r.entered_by
             WHERE m.group_id = ? AND r.reading_at >= ? AND r.reading_at < DATE_ADD(?, INTERVAL 1 DAY)',
            [$groupId, $day . ' 00:00:00', $day . ' 00:00:00']
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['meter_id']][substr($r['reading_at'], 11, 5)] = $r;
        }
        return $out;
    }

    /**
     * Save one reading (spec 5.3). $in: meter_id, reading_at, value, note?, force?, reason?, override?, client_uuid?
     * @return array{status:string,errors:array,warnings:array,reading?:array,created?:bool}
     */
    public static function save(array $in, array $user): array
    {
        if (!Auth::can('enter', $user)) {
            return self::fail('FORBIDDEN', 'You do not have permission to enter readings.');
        }
        $meter = Meter::find((int) ($in['meter_id'] ?? 0));
        if (!$meter) {
            return self::fail('NO_METER', 'Unknown meter.');
        }
        if (!(int) $meter['active']) {
            return self::fail('METER_INACTIVE', 'This meter is switched off.');
        }
        $at = self::parseAt((string) ($in['reading_at'] ?? ''));
        if (!$at) {
            return self::fail('BAD_TIME', 'Invalid reading time.');
        }

        $uuid = self::cleanUuid($in['client_uuid'] ?? null);
        if ($uuid !== null) {
            $dupe = DB::one('SELECT * FROM readings WHERE client_uuid = ?', [$uuid]);
            if ($dupe) { // offline queue resent something we already have
                return ['status' => 'saved', 'errors' => [], 'warnings' => [], 'reading' => self::present($dupe), 'created' => false];
            }
        }

        $now      = now();
        $force    = !empty($in['force']);
        $override = !empty($in['override']) && Auth::can('override_counter', $user);
        $note     = self::cleanText($in['note'] ?? null);
        $reason   = self::cleanText($in['reason'] ?? null);

        $check = (new ReadingValidator())->check($meter, (string) ($in['value'] ?? ''), $at, $user, ['override' => $override, 'now' => $now]);
        if ($check['errors']) {
            return ['status' => 'error', 'errors' => $check['errors'], 'warnings' => []];
        }
        if ($check['warnings'] && !$force) {
            return ['status' => 'warning', 'errors' => [], 'warnings' => $check['warnings']];
        }

        $value = $check['value'];
        $codes = array_column($check['warnings'], 'code');
        if ($override) {
            $codes[] = 'OVERRIDE';
            $note = trim(($note ? $note . ' · ' : '') . 'Counter override: ' . ($reason ?: 'meter replaced/reset'));
        }
        $codeStr = $codes ? mb_substr(implode(',', array_unique($codes)), 0, 255) : null;
        $atStr = $at->format('Y-m-d H:i:s');

        return DB::tx(function () use ($meter, $at, $atStr, $value, $codeStr, $note, $reason, $uuid, $user, $now) {
            $existing = DB::one('SELECT * FROM readings WHERE meter_id = ? AND reading_at = ? FOR UPDATE', [$meter['id'], $atStr]);

            if ($existing) {
                if (abs((float) $existing['value'] - (float) $value) < 0.0005) {
                    return ['status' => 'saved', 'errors' => [], 'warnings' => [], 'reading' => self::present($existing), 'created' => false];
                }
                $days = Setting::int('operator_edit_days', 1);
                $isOld = $at < $now->setTime(0, 0)->modify("-{$days} days");
                if ($isOld && ($reason === null || $reason === '')) {
                    return self::fail('REASON_REQUIRED', 'Say why you are changing an older reading.');
                }
                DB::exec(
                    'UPDATE readings SET value = ?, updated_by = ?, updated_at = ?, warning_codes = ?, note = ?, client_uuid = ? WHERE id = ?',
                    [$value, $user['id'], $now->format('Y-m-d H:i:s'), $codeStr, $note ?? $existing['note'], $uuid, $existing['id']]
                );
                DB::exec(
                    'INSERT INTO reading_audit (reading_id, old_value, new_value, changed_by, changed_at, reason) VALUES (?, ?, ?, ?, ?, ?)',
                    [$existing['id'], $existing['value'], $value, $user['id'], $now->format('Y-m-d H:i:s'), $reason]
                );
                $id = (int) $existing['id'];
                $created = false;
            } else {
                $late = 0;
                if (Slots::isInterval($meter)) {
                    $lateMin = Setting::int('late_minutes', 20);
                    $late = $now > $at->modify("+{$lateMin} minutes") ? 1 : 0;
                }
                DB::exec(
                    'INSERT INTO readings (meter_id, reading_at, value, entered_by, entered_at, is_late, warning_codes, note, source, client_uuid)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'app\', ?)',
                    [$meter['id'], $atStr, $value, $user['id'], $now->format('Y-m-d H:i:s'), $late, $codeStr, $note, $uuid]
                );
                $id = DB::lastId();
                $created = true;
            }

            $day = $at->format('Y-m-d');
            Totals::refresh((int) $meter['id'], $day);
            if ($meter['reading_type'] === 'cumulative') {
                Totals::refresh((int) $meter['id'], $at->modify('-1 day')->format('Y-m-d'));
            }

            $row = DB::one('SELECT * FROM readings WHERE id = ?', [$id]);
            return ['status' => 'saved', 'errors' => [], 'warnings' => [], 'reading' => self::present($row), 'created' => $created];
        });
    }

    public static function fail(string $code, string $message): array
    {
        return ['status' => 'error', 'errors' => [['code' => $code, 'message' => $message]], 'warnings' => []];
    }

    public static function cleanUuid(mixed $u): ?string
    {
        $u = is_string($u) ? strtolower(trim($u)) : '';
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $u) === 1 ? $u : null;
    }

    public static function cleanText(mixed $t, int $max = 255): ?string
    {
        if (!is_string($t)) {
            return null;
        }
        $t = trim($t);
        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
