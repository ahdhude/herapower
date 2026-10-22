<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Progress;
use DateTimeImmutable;

final class TodayController
{
    /** GET /api/today[?date=Y-m-d] */
    public function index(Request $req): Response
    {
        $now = now();
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $req->query('date', ''), site_tz()) ?: $now;
        return Response::json(['status' => 'ok'] + Progress::forDay($d, $now));
    }

    /** GET /api/csrf — fresh token for the offline queue after the session was recreated. */
    public function csrf(Request $req): Response
    {
        return Response::json(['status' => 'ok', 'token' => \App\Core\Csrf::token()]);
    }

    /** POST /api/day-status {date, group_id | group_ids[]} — supervisor sign-off. */
    public function approve(Request $req): Response
    {
        if (!Auth::can('approve')) {
            return Response::json(['status' => 'error', 'message' => 'Only a supervisor can approve a day.'], 403);
        }
        $date = (string) $req->input('date', '');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, site_tz());
        if (!$day || $day->format('Y-m-d') !== $date || $date > now()->format('Y-m-d')) {
            return Response::json(['status' => 'error', 'message' => 'A valid past or present date is required.'], 422);
        }
        $ids = $req->input('group_ids', null);
        if (!is_array($ids)) {
            $ids = [$req->input('group_id', null)];
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v) => $v !== null && $v !== ''))));
        if (!$ids) {
            return Response::json(['status' => 'error', 'message' => 'group_id is required.'], 422);
        }
        foreach ($ids as $gid) {
            DB::exec(
                'INSERT INTO day_status (site_id, day, group_id, approved_by, approved_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE approved_by = VALUES(approved_by), approved_at = VALUES(approved_at)',
                [Auth::siteId(), $date, $gid, Auth::id(), date('Y-m-d H:i:s')]
            );
        }
        return Response::json(['status' => 'saved', 'approved' => $ids, 'date' => $date, 'by' => Auth::user()['name']]);
    }
}
