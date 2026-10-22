<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function url(string $path = '/'): string
{
    return rtrim((string) Config::get('APP_BASE', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = dirname(__DIR__, 2) . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 1;
    return url('/assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null && $message !== null) {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}

function site_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone((string) Config::get('TIMEZONE', 'Indian/Maldives'));
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', site_tz());
}

/** Format a decimal string/number with thousands separators; null → em dash. */
function num(mixed $v, int $dp = 1): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float) $v, $dp);
}

/** Strip trailing zeros of a DECIMAL string ("13.400" → "13.4"). */
function trim_dec(mixed $v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $s = (string) $v;
    if (str_contains($s, '.')) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s === '' || $s === '-' ? '0' : $s;
}

function icon(string $name, string $extra = ''): string
{
    return '<i class="bi bi-' . e($name) . ($extra !== '' ? ' ' . e($extra) : '') . '" aria-hidden="true"></i>';
}

function log_error(string $message): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) {
        return;
    }
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}
