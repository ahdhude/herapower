<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private ?array $json = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim((string) Config::get('APP_BASE', ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = '/' . trim($path, '/');
        return $path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path(), '/api/')
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** JSON body (decoded array) or form body. */
    public function body(): array
    {
        if ($this->isJson()) {
            if ($this->json === null) {
                $decoded = json_decode((string) file_get_contents('php://input'), true);
                $this->json = is_array($decoded) ? $decoded : [];
            }
            return $this->json;
        }
        return $_POST;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body()[$key] ?? $_GET[$key] ?? $default;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
