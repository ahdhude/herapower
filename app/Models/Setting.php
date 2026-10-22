<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class Setting
{
    private static ?array $all = null;

    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (DB::all('SELECT `key`, `value` FROM settings') as $r) {
                self::$all[$r['key']] = $r['value'];
            }
        }
        return self::$all;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::all()[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function float(string $key, float $default): float
    {
        $v = self::get($key);
        return $v === null ? $default : (float) $v;
    }

    public static function set(string $key, ?string $value): void
    {
        DB::exec(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, ($value === '' ? null : $value)]
        );
        self::$all = null;
    }

    public static function reset(): void
    {
        self::$all = null;
    }
}
