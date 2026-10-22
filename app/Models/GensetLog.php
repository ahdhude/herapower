<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\DB;
use App\Services\ReadingValidator;
use App\Services\Slots;

final class GensetLog
{
    /** field => [label, unit, max allowed by its column] */
    public const FIELDS = [
        'volts'     => ['Voltage', 'V', 99999.99],
        'hz'        => ['Frequency', 'Hz', 999.99],
        'amp_r'     => ['Current R', 'A', 999999.99],
        'amp_y'     => ['Current Y', 'A', 999999.99],
        'amp_b'     => ['Current B', 'A', 999999.99],
        'pf'        => ['Power factor', '', 9.999],
        'kw'        => ['Active load', 'kW', 9999999.99],
        'oil_bar'   => ['Lube oil pressure', 'bar', 999.99],
        'water_c'   => ['Water temp', '°C', 9999.9],
        'kwh'       => ['Energy', 'kWh', 9999999999999.9],
        'run_hours' => ['Running hours', 'h', 999999999.9],
        'battery_v' => ['Battery', 'V', 999.99],
        'rpm'       => ['RPM', 'rpm', 65535.0],
        'solar_kw'  => ['Solar active power', 'kW', 9999999.99],
    ];

    public static function forMeterDay(int $meterId, \DateTimeImmutable $logDay): array
    {
        $from = $logDay->setTime(0, 0)->modify('+' . Slots::gensetStartMinutes() . ' minutes');
        $rows = DB::all(
            'SELECT g.*, u.name AS entered_by_name FROM genset_logs g LEFT JOIN users u ON u.id = g.entered_by
             WHERE g.meter_id = ? AND g.log_at >= ? AND g.log_at < ? ORDER BY g.log_at',
            [$meterId, $from->format('Y-m-d H:i:s'), $from->modify('+24 hours')->format('Y-m-d H:i:s')]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[substr($r['log_at'], 0, 16)] = self::present($r);
        }
        return $out;
    }

    public static function present(array $r): array
    {
        $o = [
            'id'            => (int) $r['id'],
            'meter_id'      => (int) $r['meter_id'],
            'log_at'        => $r['log_at'],
            'visual_ok'     => $r['visual_ok'] === null ? null : (bool) $r['visual_ok'],
            'visual_note'   => $r['visual_note'],
            'warning_codes' => $r['warning_codes'],
            'entered_by'    => $r['entered_by_name'] ?? null,
        ];
        foreach (array_keys(self::FIELDS) as $f) {
            $o[$f] = trim_dec($r[$f] ?? null);
        }
        return $o;
    }

    /** Settings-driven limits; a blank limit means "don't check". */
    public static function limits(): array
    {
        return [
            'oil_bar'   => ['min' => Setting::get('genset_oil_bar_min')],
            'water_c'   => ['max' => Setting::get('genset_water_c_max')],
            'battery_v' => ['min' => Setting::get('genset_battery_v_min')],
            'hz'        => ['min' => Setting::get('genset_hz_min'), 'max' => Setting::get('genset_hz_max')],
            'volts'     => ['min' => Setting::get('genset_volts_min'), 'max' => Setting::get('genset_volts_max')],
        ];
    }

    /** @return array{status:string,errors:array,warnings:array,log?:array} */
    public static function save(array $in, array $user): array
    {
        if (!Auth::can('enter', $user)) {
            return Reading::fail('FORBIDDEN', 'You do not have permission to enter readings.');
        }
        $meter = Meter::find((int) ($in['meter_id'] ?? 0));
        if (!$meter || $meter['kind'] !== 'generator' || !(int) $meter['active']) {
            return Reading::fail('NO_METER', 'Unknown generator.');
        }
        $at = Reading::parseAt((string) ($in['log_at'] ?? ''));
        if (!$at) {
            return Reading::fail('BAD_TIME', 'Invalid log time.');
        }

        $uuid = Reading::cleanUuid($in['client_uuid'] ?? null);
        if ($uuid !== null && ($dupe = DB::one('SELECT * FROM genset_logs WHERE client_uuid = ?', [$uuid]))) {
            return ['status' => 'saved', 'errors' => [], 'warnings' => [], 'log' => self::present($dupe)];
        }

        $errors = [];
        $now = now();
        if (!Slots::isGensetSlot($at)) {
            $errors[] = ['code' => 'NOT_A_SLOT', 'message' => 'Genset logs are hourly, on the hour.'];
        }
        if ($at > $now->modify('+10 minutes')) {
            $errors[] = ['code' => 'FUTURE', 'message' => 'That time hasn\'t happened yet.'];
        }
        if (($user['role'] ?? '') === 'operator') {
            $days = Setting::int('operator_edit_days', 1);
            if ($at < $now->setTime(0, 0)->modify("-{$days} days")) {
                $errors[] = ['code' => 'OUT_OF_WINDOW', 'message' => 'Operators can only enter today and yesterday. Ask a supervisor.'];
            }
        }

        $vals = [];
        foreach (self::FIELDS as $f => [$label, , $max]) {
            $raw = trim((string) ($in[$f] ?? ''));
            if ($raw === '') {
                $vals[$f] = null;
                continue;
            }
            if (preg_match('/^-\d+(\.\d+)?$/', $raw)) {
                $errors[] = ['code' => 'NEGATIVE', 'message' => "{$label} can't be negative.", 'field' => $f];
                continue;
            }
            $n = ReadingValidator::parseNumber($raw);
            if ($n === null) {
                $errors[] = ['code' => 'NOT_NUMBER', 'message' => "{$label}: enter a plain number.", 'field' => $f];
                continue;
            }
            if ((float) $n > $max) {
                $errors[] = ['code' => 'NOT_NUMBER', 'message' => "{$label} is too large.", 'field' => $f];
                continue;
            }
            $vals[$f] = $f === 'rpm' ? (string) (int) round((float) $n) : $n;
        }

        $visual = $in['visual_ok'] ?? null;
        $visualOk = ($visual === null || $visual === '') ? null : (filter_var($visual, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        $visualNote = Reading::cleanText($in['visual_note'] ?? null);

        if (!$errors && count(array_filter($vals, static fn ($v) => $v !== null)) === 0 && $visualOk === null) {
            $errors[] = ['code' => 'EMPTY', 'message' => 'Enter at least one value.'];
        }
        if ($errors) {
            return ['status' => 'error', 'errors' => $errors, 'warnings' => []];
        }

        // GENSET_LIMIT (red warnings; skipped for blank limits)
        $warnings = [];
        foreach (self::limits() as $f => $lim) {
            $v = $vals[$f] ?? null;
            if ($v === null) {
                continue;
            }
            $label = self::FIELDS[$f][0];
            if (($lim['min'] ?? null) !== null && (float) $v < (float) $lim['min']) {
                $warnings[] = ['code' => 'GENSET_LIMIT', 'field' => $f, 'message' => "{$label} {$v} is below the limit of {$lim['min']}."];
            }
            if (($lim['max'] ?? null) !== null && (float) $v > (float) $lim['max']) {
                $warnings[] = ['code' => 'GENSET_LIMIT', 'field' => $f, 'message' => "{$label} {$v} is above the limit of {$lim['max']}."];
            }
        }
        if ($visualOk === 0) {
            $warnings[] = ['code' => 'GENSET_LIMIT', 'field' => 'visual_ok', 'message' => 'Visual check marked Not OK.'];
        }
        if ($warnings && empty($in['force'])) {
            return ['status' => 'warning', 'errors' => [], 'warnings' => $warnings];
        }
        $codeStr = $warnings ? 'GENSET_LIMIT' : null;

        $atStr = $at->format('Y-m-d H:i:s');
        $cols = array_keys(self::FIELDS);
        DB::exec(
            'INSERT INTO genset_logs (meter_id, log_at, ' . implode(', ', $cols) . ', visual_ok, visual_note, warning_codes, entered_by, entered_at, client_uuid)
             VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "{$c} = VALUES({$c})", $cols))
            . ', visual_ok = VALUES(visual_ok), visual_note = VALUES(visual_note), warning_codes = VALUES(warning_codes),
               entered_by = VALUES(entered_by), entered_at = VALUES(entered_at), client_uuid = VALUES(client_uuid)',
            array_merge(
                [$meter['id'], $atStr],
                array_map(static fn ($c) => $vals[$c], $cols),
                [$visualOk, $visualNote, $codeStr, $user['id'], $now->format('Y-m-d H:i:s'), $uuid]
            )
        );

        $row = DB::one(
            'SELECT g.*, u.name AS entered_by_name FROM genset_logs g LEFT JOIN users u ON u.id = g.entered_by WHERE g.meter_id = ? AND g.log_at = ?',
            [$meter['id'], $atStr]
        );
        return ['status' => 'saved', 'errors' => [], 'warnings' => [], 'log' => self::present($row)];
    }
}
