<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class AuthController
{
    public function showLogin(Request $req): Response
    {
        if (Auth::check()) {
            return Response::redirect(url('/'));
        }
        return Response::html(View::render('login', [
            'error'    => null,
            'username' => '',
            'next'     => self::safeNext((string) $req->query('next', '')),
        ], 'layout_plain'));
    }

    public function login(Request $req): Response
    {
        $username = (string) $req->input('username', '');
        $result = Auth::attempt($username, (string) $req->input('password', ''), $req->ip());
        if (!$result['ok']) {
            return Response::html(View::render('login', [
                'error'    => $result['error'],
                'username' => $username,
                'next'     => self::safeNext((string) $req->input('next', '')),
            ], 'layout_plain'), 401);
        }
        $next = self::safeNext((string) $req->input('next', ''));
        return Response::redirect(url($next ?: '/'));
    }

    public function logout(Request $req): Response
    {
        Auth::logout();
        return Response::redirect(url('/login') . '?out=1');
    }

    private static function safeNext(string $next): string
    {
        return ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, '\\')) ? $next : '';
    }
}
