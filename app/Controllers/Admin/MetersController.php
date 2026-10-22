<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Meter;

final class MetersController
{
    private const KINDS = ['feeder', 'solar', 'water', 'inverter', 'generator', 'fuel'];

    public function index(Request $req): string
    {
        $groups = Meter::groups();
        foreach ($groups as &$g) {
            $g['meters'] = Meter::inGroup((int) $g['id'], false);
        }
        return View::render('admin/meters', ['title' => 'Meters', 'active' => 'more', 'groups' => $groups]);
    }

    public function form(Request $req, array $p): Response|string
    {
        $meter = null;
        if (isset($p['id'])) {
            $meter = Meter::find((int) $p['id']);
            if (!$meter) {
                return Response::redirect(url('/admin/meters'));
            }
        }
        return View::render('admin/meter_form', [
            'title'  => $meter ? 'Edit meter' : 'Add meter',
            'active' => 'more',
            'meter'  => $meter,
            'groups' => Meter::groups(),
            'plants' => Meter::ofKind('solar', false),
            'errors' => [],
            'locked' => $meter ? Meter::hasReadings((int) $meter['id']) : false,
        ]);
    }

    public function save(Request $req): Response|string
    {
        $in = $req->body();
        $id = (int) ($in['id'] ?? 0);
        $existing = $id ? Meter::find($id) : null;
        $errors = [];

        $kind = (string) ($in['kind'] ?? '');
        $type = (string) ($in['reading_type'] ?? '');
        $code = trim((string) ($in['code'] ?? ''));
        $name = trim((string) ($in['name'] ?? ''));
        $unit = trim((string) ($in['unit'] ?? ''));
        $group = (int) ($in['group_id'] ?? 0);
        $mult = (string) ($in['multiplier'] ?? '1');
        $intervalMin = $type === 'interval' ? (int) ($in['interval_min'] ?? 0) : null;
        $ws = $type === 'interval' ? $this->time((string) ($in['window_start'] ?? '')) : null;
        $we = $type === 'interval' ? $this->time((string) ($in['window_end'] ?? '')) : null;
        $min = $this->decimalOrNull($in['min_value'] ?? null);
        $max = $this->decimalOrNull($in['max_value'] ?? null);
        $parent = ((int) ($in['parent_id'] ?? 0)) ?: null;

        if (!in_array($kind, self::KINDS, true)) {
            $errors[] = 'Choose a kind.';
        }
        if (!in_array($type, ['interval', 'cumulative'], true)) {
            $errors[] = 'Choose interval or cumulative.';
        }
        if ($code === '' || $name === '' || $unit === '') {
            $errors[] = 'Code, name and unit are required.';
        }
        if (!Meter::group($group)) {
            $errors[] = 'Choose a group.';
        }
        if (!is_numeric($mult) || (float) $mult <= 0) {
            $errors[] = 'Multiplier must be a number above zero.';
        }
        if ($type === 'interval' && ($intervalMin < 1 || $intervalMin > 1440 || $ws === null || $we === null || $ws > $we)) {
            $errors[] = 'Interval meters need an interval (minutes) and a window start before its end.';
        }
        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            $errors[] = 'Min must not be above max.';
        }
        if ($existing && Meter::hasReadings($id) && ($existing['reading_type'] !== $type || (float) $existing['multiplier'] !== (float) $mult)) {
            $errors[] = 'This meter already has readings, so its type and multiplier can\'t change. Add a new meter instead.';
        }
        $dupe = DB::val('SELECT id FROM meters WHERE site_id = ? AND code = ? AND id <> ?', [Auth::siteId(), $code, $id]);
        if ($dupe) {
            $errors[] = "Code '{$code}' is already used by another meter.";
        }

        $row = [
            'id' => $id ?: null, 'group_id' => $group, 'parent_id' => $parent, 'code' => $code, 'name' => $name, 'kind' => $kind,
            'reading_type' => $type, 'unit' => $unit, 'multiplier' => $mult, 'interval_min' => $intervalMin,
            'window_start' => $ws, 'window_end' => $we, 'min_value' => $min, 'max_value' => $max,
            'is_incomer' => !empty($in['is_incomer']) ? 1 : 0, 'sort_order' => (int) ($in['sort_order'] ?? 0),
            'active' => !empty($in['active']) ? 1 : 0,
        ];

        if ($errors) {
            return Response::html(View::render('admin/meter_form', [
                'title' => $existing ? 'Edit meter' : 'Add meter', 'active' => 'more', 'meter' => $row,
                'groups' => Meter::groups(), 'plants' => Meter::ofKind('solar', false),
                'errors' => $errors, 'locked' => $existing ? Meter::hasReadings($id) : false,
            ]), 422);
        }

        if ($existing) {
            DB::exec(
                'UPDATE meters SET group_id=?, parent_id=?, code=?, name=?, kind=?, reading_type=?, unit=?, multiplier=?, interval_min=?,
                   window_start=?, window_end=?, min_value=?, max_value=?, is_incomer=?, sort_order=?, active=? WHERE id=?',
                [$group, $parent, $code, $name, $kind, $type, $unit, $mult, $intervalMin, $ws, $we, $min, $max, $row['is_incomer'], $row['sort_order'], $row['active'], $id]
            );
            flash('success', "Saved {$name}.");
        } else {
            $newId = (int) ($in['new_id'] ?? 0);
            if ($newId < 1 || DB::val('SELECT id FROM meters WHERE id = ?', [$newId])) {
                $newId = Meter::nextId($kind);
            }
            DB::exec(
                'INSERT INTO meters (id, site_id, group_id, parent_id, code, name, kind, reading_type, unit, multiplier, interval_min,
                   window_start, window_end, min_value, max_value, is_incomer, sort_order, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$newId, Auth::siteId(), $group, $parent, $code, $name, $kind, $type, $unit, $mult, $intervalMin, $ws, $we, $min, $max, $row['is_incomer'], $row['sort_order'], $row['active']]
            );
            flash('success', "Added {$name} (meter {$newId}).");
        }
        return Response::redirect(url('/admin/meters'));
    }

    public function toggle(Request $req, array $p): Response
    {
        $m = Meter::find((int) $p['id']);
        if ($m) {
            DB::exec('UPDATE meters SET active = 1 - active WHERE id = ?', [$m['id']]);
            flash('success', $m['name'] . ((int) $m['active'] ? ' switched off.' : ' switched on.'));
        }
        return Response::redirect(url('/admin/meters'));
    }

    public function move(Request $req, array $p): Response
    {
        $m = Meter::find((int) $p['id']);
        $dir = (string) $req->input('dir', '');
        if ($m && in_array($dir, ['up', 'down'], true)) {
            $siblings = Meter::inGroup((int) $m['group_id'], false);
            $i = array_search((int) $m['id'], array_map(static fn ($s) => (int) $s['id'], $siblings), true);
            $j = $dir === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($siblings[$j])) {
                // Renumber the whole group 1..n so equal sort_order values can't stall a swap.
                [$siblings[$i], $siblings[$j]] = [$siblings[$j], $siblings[$i]];
                DB::tx(function () use ($siblings) {
                    foreach ($siblings as $n => $s) {
                        DB::exec('UPDATE meters SET sort_order = ? WHERE id = ?', [$n + 1, $s['id']]);
                    }
                });
            }
        }
        return Response::redirect(url('/admin/meters') . '#g' . ($m['group_id'] ?? ''));
    }

    public function delete(Request $req, array $p): Response
    {
        $m = Meter::find((int) $p['id']);
        if (!$m) {
            return Response::redirect(url('/admin/meters'));
        }
        if (Meter::hasReadings((int) $m['id'])) {
            flash('error', 'This meter has readings, so it can\'t be deleted. Switch it off instead.');
        } elseif (DB::val('SELECT 1 FROM meters WHERE parent_id = ? LIMIT 1', [$m['id']])) {
            flash('error', 'Inverters are linked to this plant. Move or delete them first.');
        } else {
            DB::exec('DELETE FROM daily_totals WHERE meter_id = ?', [$m['id']]);
            DB::exec('DELETE FROM meters WHERE id = ?', [$m['id']]);
            flash('success', 'Deleted ' . $m['name'] . '.');
        }
        return Response::redirect(url('/admin/meters'));
    }

    private function time(string $t): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t) ? (strlen($t) === 5 ? $t . ':00' : $t) : null;
    }

    private function decimalOrNull(mixed $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : null;
    }
}
