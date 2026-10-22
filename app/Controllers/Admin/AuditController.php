<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\Request;
use App\Core\View;

final class AuditController
{
    public function index(Request $req): string
    {
        $page = max(1, (int) $req->query('page', 1));
        $per = 50;
        $total = (int) DB::val('SELECT COUNT(*) FROM reading_audit');
        $rows = DB::all(
            'SELECT a.*, r.reading_at, m.name AS meter_name, m.unit, u.name AS user_name
             FROM reading_audit a
             JOIN readings r ON r.id = a.reading_id
             JOIN meters m ON m.id = r.meter_id
             JOIN users u ON u.id = a.changed_by
             ORDER BY a.changed_at DESC, a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)
        );
        return View::render('admin/audit', [
            'title'  => 'Audit log',
            'active' => 'more',
            'rows'   => $rows,
            'page'   => $page,
            'pages'  => max(1, (int) ceil($total / $per)),
            'total'  => $total,
        ]);
    }
}
