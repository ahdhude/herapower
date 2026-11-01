<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Meter;
use App\Models\Setting;
use App\Services\Progress;
use App\Services\Slots;
use DateTimeImmutable;

final class EntryController
{
    public function hub(Request $req): string
    {
        return View::render('entry/hub', [
            'title'    => Auth::can('enter') ? 'Enter readings' : 'Readings',
            'active'   => 'entry',
            'progress' => Progress::forDay(now()),
        ]);
    }

    public function feeders(Request $req, array $p): Response|string
    {
        $group = Meter::group((int) $p['group']);
        if (!$group || $group['entry_type'] !== 'interval' || !self::isKind($group, 'feeder')) {
            return Response::html(View::render('error', ['code' => 404, 'message' => 'That feeder log does not exist.']), 404);
        }
        return $this->interval($req, $group, 'feeder');
    }

    public function solar(Request $req): Response|string
    {
        foreach (Meter::groupsWithMeters('interval') as $g) {
            if ($g['meters'] && $g['meters'][0]['kind'] === 'solar') {
                return $this->interval($req, $g, 'solar');
            }
        }
        return Response::html(View::render('error', ['code' => 404, 'message' => 'No solar plant is set up yet.']), 404);
    }

    private function interval(Request $req, array $group, string $kind): string
    {
        $now = now();
        $date = $this->date($req);
        $meters = Meter::inGroup((int) $group['id']);
        $first = $meters[0] ?? null;
        $slotKey = (string) $req->query($kind === 'solar' ? 'slot' : 'hour', '');
        if (!preg_match('/^\d{2}:\d{2}$/', $slotKey)) {
            $slotKey = '';
        }
        if ($slotKey === '' && $first) {
            $due = $date === $now->format('Y-m-d') ? Slots::due($first, $now) : null;
            $slotKey = ($due ?? Slots::forMeter($first, $now)[0])->format('H:i');
        }

        $tabs = [];
        if ($kind === 'feeder') {
            foreach (Meter::groupsWithMeters('interval') as $g) {
                if ($g['meters'] && $g['meters'][0]['kind'] === 'feeder') {
                    $tabs[] = ['id' => (int) $g['id'], 'name' => $g['name']];
                }
            }
        }

        return View::render('entry/interval', [
            'title'  => $group['name'],
            'active' => 'entry',
            'kind'   => $kind,
            'group'  => $group,
            'tabs'   => $tabs,
            'cfg'    => [
                'kind'           => $kind,
                'groupId'        => (int) $group['id'],
                'groupName'      => $group['name'],
                'date'           => $date,
                'slot'           => $slotKey,
                'today'          => $now->format('Y-m-d'),
                'minDate'        => $this->minDate($now),
                'editFrom'       => $this->editFrom($now),
                'canOverride'    => Auth::can('override_counter'),
                'sumCheck'       => Setting::get('feeder_sum_check') === '1',
                'sumTolerance'   => Setting::float('feeder_sum_tolerance_pct', 25),
                'intervalMin'    => (int) ($first['interval_min'] ?? 60),
                'feederGroups'   => array_column($tabs, 'id'),
            ],
        ]);
    }

    public function daily(Request $req): string
    {
        $now = now();
        return View::render('entry/daily', [
            'title'  => 'Daily meters',
            'active' => 'entry',
            'cfg'    => [
                'date'        => $this->date($req),
                'today'       => $now->format('Y-m-d'),
                'minDate'     => $this->minDate($now),
                'editFrom'    => $this->editFrom($now),
                'canOverride' => Auth::can('override_counter'),
            ],
        ]);
    }

    public function genset(Request $req): string
    {
        $now = now();
        $gens = Meter::ofKind('generator');
        $meterId = (int) $req->query('meter', 0);
        if (!in_array($meterId, array_map(static fn ($m) => (int) $m['id'], $gens), true)) {
            $meterId = (int) ($gens[0]['id'] ?? 0);
        }
        $logDay = Slots::gensetLogDayOf($now)->format('Y-m-d');
        $date = $this->date($req, $logDay);
        $limits = array_filter(
            \App\Models\GensetLog::limits(),
            static fn ($l) => count(array_filter($l, static fn ($v) => $v !== null)) > 0
        );

        return View::render('entry/genset', [
            'title'  => 'Genset log',
            'active' => 'entry',
            'gens'   => array_map(static fn ($m) => ['id' => (int) $m['id'], 'name' => $m['name']], $gens),
            'cfg'    => [
                'meterId'  => $meterId,
                'date'     => $date,
                'today'    => $logDay,
                'minDate'  => $this->minDate($now),
                'editFrom' => $this->editFrom($now),
                'fields'   => array_map(
                    static fn ($k, $f) => ['key' => $k, 'label' => $f[0], 'unit' => $f[1]],
                    array_keys(\App\Models\GensetLog::FIELDS),
                    array_values(\App\Models\GensetLog::FIELDS)
                ),
                'hasLimits' => (bool) $limits,
            ],
        ]);
    }

    private function date(Request $req, ?string $default = null): string
    {
        $today = $default ?? now()->format('Y-m-d');
        $d = (string) $req->query('date', $today);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $d, site_tz());
        if (!$parsed || $parsed->format('Y-m-d') !== $d || $d > now()->format('Y-m-d')) {
            return $today;
        }
        return $d;
    }

    /**
     * Earliest day the date picker offers. Operators and viewers can look back `view_back_days`
     * (Admin → Settings); supervisors and admins a year. Looking is separate from changing: see editFrom().
     */
    private function minDate(DateTimeImmutable $now): string
    {
        $days = Auth::hasRole('operator', 'viewer')
            ? max(Setting::int('view_back_days', 90), Setting::int('operator_edit_days', 1))
            : 365;
        return $now->modify("-{$days} days")->format('Y-m-d');
    }

    /** Earliest day this person may change a reading. null = nothing (viewers); earlier days open read-only. */
    private function editFrom(DateTimeImmutable $now): ?string
    {
        if (!Auth::can('enter')) {
            return null;
        }
        if (Auth::hasRole('operator')) {
            return $now->modify('-' . Setting::int('operator_edit_days', 1) . ' days')->format('Y-m-d');
        }
        return '2000-01-01'; // supervisors and admins: any day (older changes ask for a reason)
    }

    private static function isKind(array $group, string $kind): bool
    {
        $m = Meter::inGroup((int) $group['id'], false);
        return $m && $m[0]['kind'] === $kind;
    }
}
