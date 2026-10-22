<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Dependency-free SQL dump (no mysqldump or exec needed, so it works on shared hosting). */
final class Backup
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/backups';
    }

    /** @return string path of the .sql.gz written */
    public static function run(): string
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/herahakatha-' . date('Ymd-His') . '.sql.gz';
        $gz = gzopen($path, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
        $pdo = DB::pdo();
        gzwrite($gz, "-- Herahakatha backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(\PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `{$t}`;\n{$create};\n\n");
            $st = $pdo->query("SELECT * FROM `{$t}`");
            $batch = [];
            $cols = null;
            while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) >= 300) {
                    gzwrite($gz, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        return $path;
    }

    /** Delete dumps older than $days. @return int files removed */
    public static function prune(int $days = 30): int
    {
        $n = 0;
        foreach (glob(self::dir() . '/herahakatha-*.sql.gz') ?: [] as $f) {
            if (filemtime($f) < time() - $days * 86400) {
                @unlink($f);
                $n++;
            }
        }
        return $n;
    }
}
