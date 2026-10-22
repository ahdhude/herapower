<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Applies database/migrations/*.sql once each, in filename order. */
final class Migrator
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /** @return string[] filenames applied by this call */
    public static function run(): array
    {
        $pdo = DB::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $done = array_column(DB::all('SELECT name FROM schema_migrations'), 'name');
        $applied = [];
        $files = glob(self::dir() . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            $name = basename($f);
            if (in_array($name, $done, true)) {
                continue;
            }
            $pdo->exec((string) file_get_contents($f)); // DDL auto-commits, so no wrapping transaction
            DB::exec('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
            $applied[] = $name;
        }
        return $applied;
    }
}
