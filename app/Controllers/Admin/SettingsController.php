<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Setting;

final class SettingsController
{
    /** key => [label, hint, type] — only keys listed here can be edited. */
    public const FIELDS = [
        'Entry rules' => [
            'late_minutes'       => ['Late after (minutes)', 'A reading saved this long after its slot is flagged late.', 'int'],
            'operator_edit_days' => ['Operator change window (days)', 'How far back operators can CHANGE a reading that is already saved. 1 = today and yesterday. On older days within the look-back below, operators can only add readings that are missing.', 'int'],
            'view_back_days'     => ['Look-back for operators and viewers (days)', 'How far back they can open the screens. Operators can also ADD missing readings that far back (catch-up); viewers only look. Supervisors and admins can always go back a year.', 'int'],
            'genset_day_start'   => ['Genset log day starts', 'The log day runs 24 hours from this time.', 'time'],
        ],
        'Warnings' => [
            'median_days'              => ['Median window (days)', 'Days of history used to find the usual value for a slot.', 'int'],
            'spike_factor'             => ['Spike factor', 'Warn above factor × median or below median ÷ factor.', 'num'],
            'spike_floor_feeder'       => ['Spike floor — feeders (kW)', 'Ignore the spike check when the median is below this.', 'num'],
            'spike_floor_solar'        => ['Spike floor — solar (kW)', '', 'num'],
            'daily_change_pct'         => ['Daily change (%)', 'Cumulative meters: warn when a day differs this much from the 7-day average.', 'num'],
            'feeder_sum_check'         => ['Compare feeders with HV Network', 'Turn on once all feeders are confirmed to sit under HV Network.', 'bool'],
            'feeder_sum_tolerance_pct' => ['Feeder sum tolerance (%)', '', 'num'],
        ],
        'Generator limits' => [
            'genset_oil_bar_min'   => ['Oil pressure min (bar)', 'Leave blank to skip the check. Limits come from the chief engineer.', 'num'],
            'genset_water_c_max'   => ['Water temperature max (°C)', '', 'num'],
            'genset_battery_v_min' => ['Battery min (V)', '', 'num'],
            'genset_hz_min'        => ['Frequency min (Hz)', '', 'num'],
            'genset_hz_max'        => ['Frequency max (Hz)', '', 'num'],
            'genset_volts_min'     => ['Voltage min (V)', '', 'num'],
            'genset_volts_max'     => ['Voltage max (V)', '', 'num'],
        ],
        'Alerts' => [
            'alert_email' => ['Alert email', 'Nightly missing-reading summary is sent here.', 'email'],
        ],
    ];

    public function index(Request $req): string
    {
        return View::render('admin/settings', [
            'title'  => 'Settings',
            'active' => 'more',
            'fields' => self::FIELDS,
            'values' => Setting::all(),
        ]);
    }

    public function save(Request $req): Response
    {
        $errors = [];
        $clean = [];
        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => [$label, , $type]) {
                $raw = trim((string) $req->input($key, ''));
                if ($type === 'bool') {
                    $clean[$key] = $req->input($key) === '1' ? '1' : '0';
                    continue;
                }
                if ($raw === '') {
                    $clean[$key] = null;
                    continue;
                }
                $ok = match ($type) {
                    'int'   => preg_match('/^\d+$/', $raw) === 1,
                    'num'   => preg_match('/^\d+(\.\d+)?$/', $raw) === 1,
                    'time'  => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw) === 1,
                    'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) !== false,
                    default => true,
                };
                if (!$ok) {
                    $errors[] = "{$label}: invalid value.";
                }
                $clean[$key] = $raw;
            }
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            return Response::redirect(url('/admin/settings'));
        }
        // Required settings can't be blank — keep their current value.
        foreach (['late_minutes', 'operator_edit_days', 'view_back_days', 'median_days', 'spike_factor', 'daily_change_pct', 'genset_day_start'] as $req_) {
            if ($clean[$req_] === null) {
                $clean[$req_] = Setting::get($req_);
            }
        }
        foreach ($clean as $k => $v) {
            Setting::set($k, $v);
        }
        flash('success', 'Settings saved.');
        return Response::redirect(url('/admin/settings'));
    }
}
