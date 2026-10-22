<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function download(string $body, string $filename, string $mime): self
    {
        return new self($body, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
