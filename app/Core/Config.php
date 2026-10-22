<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static ?array $data = null;

    public static function load(string $file): void
    {
        self::$data = require $file;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV') === 'production';
    }

    public static function debug(): bool
    {
        return (bool) self::get('APP_DEBUG', false);
    }
}
