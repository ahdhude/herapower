<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Import\Importer;
use App\Services\Import\Reader;
use App\Services\Import\Result;

/** Admin → Import history. Upload one month at a time, look at what it would do, then import as a batch. */
final class ImportController
{
    private const EXT = ['xlsx', 'xlsm', 'xls', 'sql'];

    public function index(Request $req): string
    {
        return View::render('admin/import', [
            'title'    => 'Import history',
            'active'   => 'more',
            'ready'    => Reader::ready(),
            'kinds'    => Reader::KINDS,
            'batches'  => DB::all(
                "SELECT b.*, u.name AS by_name,
                        (SELECT COUNT(*) FROM import_issues i WHERE i.batch_id = b.id AND i.status = 'open' AND i.kind <> 'info') AS open_issues
                 FROM import_batches b JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 40"
            ),
            'openTotal' => (int) DB::val("SELECT COUNT(*) FROM import_issues WHERE status = 'open' AND kind <> 'info'"),
            'maxUpload' => ini_get('upload_max_filesize'),
        ]);
    }

    /** POST: take the file, parse it, show what an import would do. Nothing is written yet. */
    public function analyse(Request $req): Response
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail($f && ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is bigger than this server allows (' . ini_get('upload_max_filesize') . ').' : 'Choose a file to upload.');
        }
        $orig = basename((string) $f['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT, true)) {
            return $this->fail('Upload an Excel file (.xlsx) or an old meter_readings .sql export.');
        }
        if ($ext !== 'sql' && !Reader::ready()) {
            return $this->fail('Reading Excel files needs the PHP “zip” extension and the Composer packages on this server.');
        }

        $this->cleanPending();
        $token = bin2hex(random_bytes(12));
        $dir = $this->pendingDir();
        $path = "{$dir}/{$token}.{$ext}";
        if (!move_uploaded_file($f['tmp_name'], $path)) {
            return $this->fail('The upload could not be saved on the server.');
        }

        $kind = (string) $req->input('kind', 'auto');
        $kind = isset(Reader::KINDS[$kind]) ? $kind : null;
        $month = trim((string) $req->input('month', ''));
        [$guess, $why] = Reader::guessMonth($orig);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = '';
        }
        $monthHow = $month !== '' ? 'chosen by you' : $why;
        if ($month === '') {
            $month = $guess ?? '';
        }
        if ($month === '' && $ext !== 'sql') {
            @unlink($path);
            return $this->fail('Pick the month this file covers. It could not be worked out from the file name.');
        }

        try {
            $res = Reader::parse($path, $month !== '' ? $month : date('Y-m'), $kind, $orig);
        } catch (\Throwable $e) {
            @unlink($path);
            log_error('Import analyse failed: ' . $e->getMessage());
            return $this->fail($e instanceof \RuntimeException ? $e->getMessage() : 'This file could not be read as a logsheet (' . $e->getMessage() . ').');
        }
        if ($res->kind === 'sql' && $res->rows && $month === '') {
            $res->month = substr(min(array_column($res->rows, 1)), 0, 7);
        }

        file_put_contents("{$dir}/{$token}.json", json_encode(['result' => $res->toArray(), 'monthHow' => $monthHow], JSON_UNESCAPED_UNICODE));
        @unlink($path); // the parsed result is all the import needs

        return Response::redirect(url('/admin/import/preview/' . $token));
    }

    /** GET: what an import would do, from the stored parse (survives a page refresh). */
    public function preview(Request $req, array $p): Response|string
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string) $p['token']) ?: '';
        $file = $this->pendingDir() . "/{$token}.json";
        if ($token === '' || !is_file($file)) {
            return $this->fail('That preview has expired. Upload the file again.');
        }
        $data = json_decode((string) file_get_contents($file), true);
        $res = Result::fromArray($data['result']);

        return View::render('admin/import_preview', [
            'title'    => 'Check before importing',
            'active'   => 'more',
            'token'    => $token,
            'res'      => $res,
            'monthHow' => $data['monthHow'] ?? '',
            'plans'    => ['keep' => Importer::plan($res, 'keep'), 'replace-imported' => Importer::plan($res, 'replace-imported')],
            'policies' => Importer::POLICIES,
            'kinds'    => Reader::KINDS,
            'missing'  => $res->kind === 'sql' ? [] : $res->missingDays(),
            'perDay'   => $this->perDay($res),
        ]);
    }

    public function commit(Request $req): Response
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string) $req->input('token', '')) ?: '';
        $file = $this->pendingDir() . "/{$token}.json";
        if ($token === '' || !is_file($file)) {
            flash('error', 'That preview has expired. Upload the file again.');
            return Response::redirect(url('/admin/import'));
        }
        $data = json_decode((string) file_get_contents($file), true);
        $policy = (string) $req->input('policy', 'keep');
        $policy = isset(Importer::POLICIES[$policy]) ? $policy : 'keep';

        try {
            $batch = Importer::commit(Result::fromArray($data['result']), $policy, Auth::user());
        } catch (\Throwable $e) {
            log_error('Import commit failed: ' . $e->getMessage());
            flash('error', 'The import failed and nothing was saved: ' . $e->getMessage());
            return Response::redirect(url('/admin/import'));
        }
        @unlink($file);
        flash('success', 'Imported. Have a look at the review items below.');
        return Response::redirect(url('/admin/import/batch/' . $batch));
    }

    public function cancel(Request $req): Response
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string) $req->input('token', '')) ?: '';
        if ($token !== '') {
            @unlink($this->pendingDir() . "/{$token}.json");
        }
        return Response::redirect(url('/admin/import'));
    }

    public function batch(Request $req, array $p): Response|string
    {
        $b = DB::one('SELECT b.*, u.name AS by_name FROM import_batches b JOIN users u ON u.id = b.created_by WHERE b.id = ?', [(int) $p['id']]);
        if (!$b) {
            return Response::redirect(url('/admin/import'));
        }
        $sum = json_decode((string) $b['summary'], true) ?: ['sheets' => [], 'notes' => []];
        return View::render('admin/import_batch', [
            'title'  => 'Import #' . $b['id'],
            'active' => 'more',
            'b'      => $b,
            'sum'    => $sum,
            'kinds'  => Reader::KINDS,
            'counts' => $this->counts((int) $b['id']),
            'list'   => $this->issueList($req, (int) $b['id']),
        ]);
    }

    public function review(Request $req): string
    {
        return View::render('admin/import_review', [
            'title'  => 'Import review',
            'active' => 'more',
            'counts' => $this->counts(null),
            'list'   => $this->issueList($req, null),
        ]);
    }

    /** POST /admin/import/issues/{id}: accept the suggestion, type a corrected value, or dismiss. JSON when asked for. */
    public function resolve(Request $req, array $p): Response
    {
        $res = Importer::resolve((int) $p['id'], (string) $req->input('action', ''), (string) $req->input('value', '') ?: null, Auth::user());
        if ($req->wantsJson() || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'json')) {
            return Response::json($res, $res['ok'] ? 200 : 422);
        }
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        return Response::redirect($this->back($req));
    }

    public function bulk(Request $req, array $p): Response
    {
        $batch = (int) $p['id'];
        $kind = (string) $req->input('kind', '');
        if (!in_array($kind, ['cell', 'suspect', 'info'], true)) {
            return Response::redirect(url('/admin/import/batch/' . $batch));
        }
        if ($req->input('action') === 'accept' && $kind !== 'info') {
            $r = Importer::acceptAll($batch, $kind, Auth::user());
            flash('success', "Applied {$r['done']} suggestion(s)." . ($r['skipped'] ? " {$r['skipped']} had no suggestion or could not be applied and are still open." : ''));
        } else {
            $n = Importer::ignoreAll($batch, $kind, Auth::user());
            flash('success', "Dismissed {$n} item(s).");
        }
        return Response::redirect(url('/admin/import/batch/' . $batch));
    }

    public function undo(Request $req, array $p): Response
    {
        $b = DB::one('SELECT * FROM import_batches WHERE id = ?', [(int) $p['id']]);
        if ($b && !$b['undone_at']) {
            $r = Importer::undo((int) $b['id']);
            flash('success', "Undone: {$r['deleted']} reading(s) removed" . ($r['kept'] ? ", {$r['kept']} kept because they were edited afterwards." : '.'));
        }
        return Response::redirect(url('/admin/import/batch/' . (int) $p['id']));
    }

    // ------------------------------------------------------------------ helpers ----------

    private function fail(string $message): Response
    {
        flash('error', $message);
        return Response::redirect(url('/admin/import'));
    }

    private function pendingDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/storage/imports/pending';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function cleanPending(): void
    {
        foreach (glob($this->pendingDir() . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }

    /** @return array<int,array{rows:int,issues:int}> day number => counts */
    private function perDay(Result $res): array
    {
        $out = [];
        foreach ($res->rows as $r) {
            $d = (int) substr($r[1], 8, 2);
            if (str_starts_with($r[1], $res->month)) {
                $out[$d]['rows'] = ($out[$d]['rows'] ?? 0) + 1;
            }
        }
        foreach ($res->issues as $i) {
            if ($i['reading_at'] && str_starts_with($i['reading_at'], $res->month) && $i['kind'] !== 'info') {
                $d = (int) substr($i['reading_at'], 8, 2);
                $out[$d]['issues'] = ($out[$d]['issues'] ?? 0) + 1;
            }
        }
        return $out;
    }

    private function counts(?int $batch): array
    {
        $where = $batch ? 'WHERE batch_id = ?' : '';
        $rows = DB::all("SELECT kind, status, COUNT(*) n FROM import_issues {$where} GROUP BY kind, status", $batch ? [$batch] : []);
        $c = [];
        foreach ($rows as $r) {
            $c[$r['kind']][$r['status']] = (int) $r['n'];
        }
        return $c;
    }

    /** @return array{rows:array,page:int,pages:int,total:int,kind:string,status:string,batch:?int} */
    private function issueList(Request $req, ?int $batch): array
    {
        $kind = (string) $req->query('kind', '');
        $status = (string) $req->query('status', 'open');
        $kind = in_array($kind, ['cell', 'suspect', 'info'], true) ? $kind : '';
        $status = in_array($status, ['open', 'accepted', 'fixed', 'ignored', 'all'], true) ? $status : 'open';
        $where = ['1=1'];
        $args = [];
        if ($batch) {
            $where[] = 'i.batch_id = ?';
            $args[] = $batch;
        }
        if ($kind !== '') {
            $where[] = 'i.kind = ?';
            $args[] = $kind;
        } elseif ($status === 'open') {
            $where[] = "i.kind <> 'info'";   // notes are shown separately; the queue is things to decide
        }
        if ($status !== 'all') {
            $where[] = 'i.status = ?';
            $args[] = $status;
        }
        $w = implode(' AND ', $where);
        $per = 40;
        $page = max(1, (int) $req->query('page', 1));
        $total = (int) DB::val("SELECT COUNT(*) FROM import_issues i WHERE {$w}", $args);
        $rows = DB::all(
            "SELECT i.*, m.name AS meter_name, m.unit AS meter_unit, r.value AS current_value
             FROM import_issues i
             LEFT JOIN meters m ON m.id = i.meter_id
             LEFT JOIN readings r ON r.meter_id = i.meter_id AND r.reading_at = i.reading_at
             WHERE {$w} ORDER BY i.reading_at, i.meter_id, i.id LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $args
        );
        return ['rows' => $rows, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total, 'kind' => $kind, 'status' => $status, 'batch' => $batch];
    }

    private function back(Request $req): string
    {
        $b = (string) $req->input('back', '');
        return str_starts_with($b, '/admin/import') && !str_contains($b, '//') ? url($b) : url('/admin/import/review');
    }
}
