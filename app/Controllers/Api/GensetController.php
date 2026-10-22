<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\GensetLog;
use App\Models\Meter;
use App\Services\Slots;
use DateTimeImmutable;

final class GensetController
{
    /** GET /api/genset?meter&date — the 24 hourly slots of one log day with what is already saved. */
    public function show(Request $req): Response
    {
        $meter = Meter::find((int) $req->query('meter', 0));
        $date = (string) $req->query('date', '');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, site_tz());
        if (!$meter || $meter['kind'] !== 'generator' || !$day || $day->format('Y-m-d') !== $date) {
            return Response::json(['status' => 'error', 'message' => 'meter and date (Y-m-d) are required.'], 422);
        }
        $logs = GensetLog::forMeterDay((int) $meter['id'], $day);
        $now = now();
        $slots = [];
        foreach (Slots::gensetSlots($day) as $s) {
            $key = $s->format('Y-m-d H:i');
            $slots[] = [
                'at'     => $key,
                'label'  => $s->format('H:i'),
                'future' => $s > $now->modify('+10 minutes'),
                'log'    => $logs[$key] ?? null,
            ];
        }
        return Response::json([
            'status' => 'ok',
            'meter'  => ['id' => (int) $meter['id'], 'name' => $meter['name']],
            'date'   => $date,
            'now'    => $now->format('Y-m-d H:i'),
            'slots'  => $slots,
            'limits' => GensetLog::limits(),
        ]);
    }

    /** POST /api/genset-logs */
    public function save(Request $req): Response
    {
        $res = GensetLog::save($req->body(), Auth::user());
        return Response::json($res, $res['status'] === 'error' ? 422 : 200);
    }
}
