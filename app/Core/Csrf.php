<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** Form field `_csrf` or `X-CSRF-Token` header. */
    public static function verify(Request $req): bool
    {
        $sent = $req->header('X-CSRF-Token') ?? (string) ($req->body()['_csrf'] ?? '');
        $known = $_SESSION['_csrf'] ?? '';
        return $known !== '' && $sent !== '' && hash_equals($known, $sent);
    }
}
