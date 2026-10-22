<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public const ROLES = ['operator', 'supervisor', 'viewer', 'admin'];

    private const CAPABILITIES = [
        'enter'            => ['operator', 'supervisor', 'admin'],
        'edit_old'         => ['supervisor', 'admin'],
        'override_counter' => ['supervisor', 'admin'],
        'approve'          => ['supervisor', 'admin'],
        'reports'          => ['operator', 'supervisor', 'viewer', 'admin'],
        'admin'            => ['admin'],
    ];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name((string) Config::get('SESSION_NAME', 'hh_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => rtrim((string) Config::get('APP_BASE', ''), '/') . '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '43200'); // 12 h: covers a shift
        session_start();
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            if ($id) {
                $u = DB::one('SELECT id, site_id, name, username, role, active FROM users WHERE id = ?', [$id]);
                self::$user = ($u && (int) $u['active'] === 1) ? $u : null;
                if (!self::$user) {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function siteId(): int
    {
        return (int) (self::user()['site_id'] ?? 1);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $role = self::role();
        return $role !== null && in_array($role, $roles, true);
    }

    public static function can(string $capability, ?array $user = null): bool
    {
        $role = ($user ?? self::user())['role'] ?? null;
        return $role !== null && in_array($role, self::CAPABILITIES[$capability] ?? [], true);
    }

    /** @return array{ok:bool,error?:string} */
    public static function attempt(string $username, string $password, string $ip): array
    {
        $username = trim($username);
        if (self::isLocked($username)) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in 15 minutes.'];
        }

        $u = DB::one('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);
        // Always run a verify so timing doesn't reveal whether the user exists.
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt.invalidinvalidinvalidinvalidin';
        $ok = password_verify($password, $hash) && $u !== null;

        DB::exec('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)', [mb_substr($username, 0, 50), $ip, $ok ? 1 : 0]);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Wrong username or password.'];
        }

        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        DB::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        self::$loaded = false;
        self::$user = null;
        return ['ok' => true];
    }

    public static function isLocked(string $username): bool
    {
        $fails = (int) DB::val(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = ? AND success = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND attempted_at > COALESCE((SELECT MAX(attempted_at) FROM login_attempts WHERE username = ? AND success = 1), '1970-01-01 00:00:00')",
            [$username, $username]
        );
        return $fails >= 5;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$loaded = false;
    }
}
