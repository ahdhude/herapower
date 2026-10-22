<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\DB;
use App\Services\Totals;

/**
 * Writes a parsed file into `readings` as one batch, keeps the review queue (`import_issues`), and can undo a batch.
 * Existing readings win by default: history never overwrites something an operator entered in the app.
 */
final class Importer
{
    public const POLICIES = [
        'keep'             => 'Keep what is already in the database (recommended)',
        'replace-imported' => 'Replace earlier imported values, keep anything entered in the app',
    ];

    /** @return array{new:int,same:int,conflict:int,replace:int,perDay:array<string,int>,existing:array<string,array>} */
    public static function plan(Result $res, string $policy): array
    {
        $existing = self::existing($res);
        $c = ['new' => 0, 'same' => 0, 'conflict' => 0, 'replace' => 0];
        $perDay = [];
        $seen = [];
        foreach ($res->rows as $r) {
            $key = $r[0] . '|' . $r[1];
            if (isset($seen[$key])) {
                continue; // the same slot twice in one file: first wins
            }
            $seen[$key] = true;
            $e = $existing[$key] ?? null;
            $day = substr($r[1], 0, 10);
            if ($e === null) {
                $c['new']++;
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            } elseif (abs((float) $e['value'] - (float) $r[2]) < 0.0005) {
                $c['same']++;
            } elseif ($policy === 'replace-imported' && $e['source'] === 'import' && $e['updated_at'] === null) {
                $c['replace']++;
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            } else {
                $c['conflict']++;
            }
        }
        return $c + ['perDay' => $perDay, 'existing' => $existing];
    }

    /** @return array<string,array> key "meter|datetime" => reading row */
    private static function existing(Result $res): array
    {
        $ids = array_values(array_unique(array_map(static fn ($r) => (int) $r[0], $res->rows)));
        if (!$ids) {
            return [];
        }
        $times = array_column($res->rows, 1);
        sort($times);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::all(
            "SELECT id, meter_id, reading_at, value, source, updated_at FROM readings WHERE meter_id IN ({$in}) AND reading_at BETWEEN ? AND ?",
            array_merge($ids, [$times[0], end($times)])
        ) as $e) {
            $out[$e['meter_id'] . '|' . $e['reading_at']] = $e;
        }
        return $out;
    }

    /** @return int batch id */
    public static function commit(Result $res, string $policy, array $user): int
    {
        @set_time_limit(300);
        return (int) DB::tx(function () use ($res, $policy, $user) {
            $now = date('Y-m-d H:i:s');
            DB::exec(
                'INSERT INTO import_batches (file, kind, month, policy, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [mb_substr($res->file, 0, 255), $res->kind, $res->month, $policy, $user['id'], $now]
            );
            $batch = DB::lastId();

            $plan = self::plan($res, $policy);
            $existing = $plan['existing'];
            $add = [];
            $replace = [];
            $seen = [];
            $kept = [];       // slots whose value in the database stays as it was
            foreach ($res->rows as $r) {
                $key = $r[0] . '|' . $r[1];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $e = $existing[$key] ?? null;
                $note = $r[4] ? mb_substr($r[4] . '!' . $r[5], 0, 255) : null;
                if ($e === null) {
                    $add[] = [$r, $note];
                } elseif (abs((float) $e['value'] - (float) $r[2]) >= 0.0005) {
                    if ($policy === 'replace-imported' && $e['source'] === 'import' && $e['updated_at'] === null) {
                        $replace[] = [$r, $note, $e];
                    } else {
                        $kept[$key] = true;
                    }
                } else {
                    $kept[$key] = true;
                }
            }

            $touched = []; // "meter|Y-m-d"
            foreach (array_chunk($add, 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), "(?,?,?,?,?,0,?,?,'import',?)"));
                $args = [];
                foreach ($chunk as [$r, $note]) {
                    array_push($args, $r[0], $r[1], $r[2], $user['id'], $now, $r[3], $note, $batch);
                    $touched[$r[0] . '|' . substr($r[1], 0, 10)] = true;
                }
                DB::exec("INSERT INTO readings (meter_id, reading_at, value, entered_by, entered_at, is_late, warning_codes, note, source, import_batch_id) VALUES {$ph}", $args);
            }
            foreach ($replace as [$r, $note, $e]) {
                DB::exec('UPDATE readings SET value = ?, warning_codes = ?, note = ?, import_batch_id = ? WHERE id = ?', [$r[2], $r[3], $note, $batch, $e['id']]);
                $touched[$r[0] . '|' . substr($r[1], 0, 10)] = true;
            }

            // review items: skip ones whose slot already holds a reading from elsewhere, and ones already raised before
            $issues = 0;
            $raised = self::raisedBefore($res);
            foreach ($res->issues as $i) {
                $key = $i['meter_id'] . '|' . $i['reading_at'];
                if ($i['kind'] !== 'info' && isset($raised[$key . '|' . $i['kind'] . '|' . $i['raw']])) {
                    continue;
                }
                if ($i['kind'] !== 'info' && $i['meter_id'] && $i['reading_at'] && (isset($existing[$key]) && ($i['kind'] === 'cell' || isset($kept[$key])))) {
                    continue;
                }
                DB::exec(
                    'INSERT INTO import_issues (file, sheet, cell, raw_value, meter_id, reading_at, suggested_value, status, batch_id, kind, message)
                     VALUES (?, ?, ?, ?, ?, ?, ?, \'open\', ?, ?, ?)',
                    [mb_substr($res->file, 0, 255), mb_substr($i['sheet'], 0, 60), mb_substr($i['cell'], 0, 10), $i['raw'], $i['meter_id'], $i['reading_at'], $i['suggested'], $batch, $i['kind'], $i['message']]
                );
                $issues++;
            }

            self::refreshTotals($touched);

            $missing = $res->kind === 'sql' ? [] : $res->missingDays();
            DB::exec(
                'UPDATE import_batches SET rows_added = ?, rows_same = ?, rows_conflict = ?, rows_replaced = ?, issues = ?, days_missing = ?, summary = ? WHERE id = ?',
                [count($add), $plan['same'], $plan['conflict'], count($replace), $issues, $missing ? self::ranges($missing) : null,
                    json_encode(['sheets' => $res->sheets, 'notes' => $res->notes], JSON_UNESCAPED_UNICODE), $batch]
            );
            return $batch;
        });
    }

    /** @return array<string,bool> "meter|time|kind|raw" of review items that already exist in any state */
    private static function raisedBefore(Result $res): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($i) => $i['meter_id'], $res->issues))));
        $times = array_values(array_filter(array_column($res->issues, 'reading_at')));
        if (!$ids || !$times) {
            return [];
        }
        sort($times);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::all(
            "SELECT meter_id, reading_at, kind, raw_value FROM import_issues WHERE kind <> 'info' AND meter_id IN ({$in}) AND reading_at BETWEEN ? AND ?",
            array_merge($ids, [$times[0], end($times)])
        ) as $r) {
            $out[$r['meter_id'] . '|' . $r['reading_at'] . '|' . $r['kind'] . '|' . $r['raw_value']] = true;
        }
        return $out;
    }

    /** @param array<string,bool> $touched "meter|day" */
    private static function refreshTotals(array $touched): void
    {
        $done = [];
        foreach (array_keys($touched) as $k) {
            [$mid, $day] = explode('|', $k);
            foreach ([$day, date('Y-m-d', strtotime($day . ' -1 day'))] as $d) {   // a counter reading also finishes the previous day
                if (!isset($done[$mid . '|' . $d])) {
                    $done[$mid . '|' . $d] = true;
                    Totals::refresh((int) $mid, $d);
                }
            }
        }
    }

    /** "1-3, 7, 12-14" */
    public static function ranges(array $days): string
    {
        sort($days);
        $out = [];
        $start = $prev = null;
        foreach ($days as $d) {
            if ($start === null) {
                $start = $prev = $d;
            } elseif ($d === $prev + 1) {
                $prev = $d;
            } else {
                $out[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
                $start = $prev = $d;
            }
        }
        if ($start !== null) {
            $out[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
        }
        return implode(', ', $out);
    }

    // ---------------------------------------------------------------- review queue ----------

    /** @return array{ok:bool,message:string} */
    public static function resolve(int $issueId, string $action, ?string $value, array $user): array
    {
        $i = DB::one('SELECT * FROM import_issues WHERE id = ?', [$issueId]);
        if (!$i || $i['status'] !== 'open') {
            return ['ok' => false, 'message' => 'That item is already dealt with.'];
        }
        $now = date('Y-m-d H:i:s');
        $close = static function (string $status) use ($issueId, $user, $now): void {
            DB::exec('UPDATE import_issues SET status = ?, resolved_by = ?, resolved_at = ? WHERE id = ?', [$status, $user['id'], $now, $issueId]);
        };

        if ($action === 'ignore' || $i['kind'] === 'info') {
            $close('ignored');
            return ['ok' => true, 'message' => 'Dismissed.'];
        }
        if ($action === 'accept') {
            $value = $i['suggested_value'];
            if ($value === null) {
                return ['ok' => false, 'message' => 'There is no suggestion for this one. Type the right value, or dismiss it.'];
            }
        }
        $c = CellCleaner::clean($value);
        if ($c['status'] !== CellCleaner::NUMBER) {
            return ['ok' => false, 'message' => 'Enter a plain number such as 354 or 12.5.'];
        }
        if (!$i['meter_id'] || !$i['reading_at']) {
            return ['ok' => false, 'message' => 'This item has no meter or time, so it can only be dismissed.'];
        }

        $status = $action === 'accept' ? 'accepted' : 'fixed';
        $meter = (int) $i['meter_id'];
        $at = $i['reading_at'];
        $existing = DB::one('SELECT * FROM readings WHERE meter_id = ? AND reading_at = ?', [$meter, $at]);

        try {
            DB::tx(function () use ($i, $c, $meter, $at, $existing, $user, $now, $status, $close) {
            if ($i['kind'] === 'cell') {
                if ($existing) {
                    throw new \DomainException('A reading was entered for this slot in the meantime (' . trim_dec($existing['value']) . '). Dismiss this item instead.');
                }
                DB::exec(
                    "INSERT INTO readings (meter_id, reading_at, value, entered_by, entered_at, is_late, note, source, import_batch_id) VALUES (?, ?, ?, ?, ?, 0, ?, 'import', ?)",
                    [$meter, $at, $c['value'], $user['id'], $now, mb_substr('Review of ' . $i['sheet'] . '!' . $i['cell'] . ' (was “' . $i['raw_value'] . '”)', 0, 255), $i['batch_id']]
                );
            } else { // suspect: the number was imported flagged; correct it with an audit trail
                if (!$existing) {
                    throw new \DomainException('The reading is no longer there.');
                }
                if (abs((float) $existing['value'] - (float) $c['value']) >= 0.0005) {
                    DB::exec('UPDATE readings SET value = ?, warning_codes = NULL, updated_by = ?, updated_at = ? WHERE id = ?', [$c['value'], $user['id'], $now, $existing['id']]);
                    DB::exec(
                        'INSERT INTO reading_audit (reading_id, old_value, new_value, changed_by, changed_at, reason) VALUES (?, ?, ?, ?, ?, ?)',
                        [$existing['id'], $existing['value'], $c['value'], $user['id'], $now, 'Import review: ' . $i['message']]
                    );
                }
            }
            $close($status);
            self::refreshTotals([$meter . '|' . substr($at, 0, 10) => true]);
            });
        } catch (\DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => $status === 'accepted' ? 'Applied the suggestion.' : 'Saved.'];
    }

    /** Accept every open suggestion of a kind in a batch (a person pressed the button; nothing happens by itself). */
    public static function acceptAll(int $batch, string $kind, array $user): array
    {
        $done = 0;
        $skipped = 0;
        foreach (DB::all("SELECT id, suggested_value FROM import_issues WHERE batch_id = ? AND kind = ? AND status = 'open'", [$batch, $kind]) as $r) {
            if ($r['suggested_value'] === null) {
                $skipped++;
                continue;
            }
            $res = self::resolve((int) $r['id'], 'accept', null, $user);
            $res['ok'] ? $done++ : $skipped++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    public static function ignoreAll(int $batch, string $kind, array $user): int
    {
        return DB::exec(
            "UPDATE import_issues SET status = 'ignored', resolved_by = ?, resolved_at = ? WHERE batch_id = ? AND kind = ? AND status = 'open'",
            [$user['id'], date('Y-m-d H:i:s'), $batch, $kind]
        );
    }

    // ---------------------------------------------------------------- undo ----------

    /** @return array{deleted:int,kept:int} */
    public static function undo(int $batch): array
    {
        return DB::tx(function () use ($batch) {
            $rows = DB::all('SELECT meter_id, reading_at FROM readings WHERE import_batch_id = ? AND updated_at IS NULL', [$batch]);
            $kept = (int) DB::val('SELECT COUNT(*) FROM readings WHERE import_batch_id = ? AND updated_at IS NOT NULL', [$batch]);
            $touched = [];
            foreach ($rows as $r) {
                $touched[$r['meter_id'] . '|' . substr($r['reading_at'], 0, 10)] = true;
            }
            DB::exec('DELETE FROM readings WHERE import_batch_id = ? AND updated_at IS NULL', [$batch]);
            DB::exec("DELETE FROM import_issues WHERE batch_id = ? AND status = 'open'", [$batch]);
            DB::exec('UPDATE import_batches SET undone_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $batch]);
            self::refreshTotals($touched);
            return ['deleted' => count($rows), 'kept' => $kept];
        });
    }
}
