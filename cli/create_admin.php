<?php
declare(strict_types=1);
/**
 * php cli/create_admin.php <username> "<Full name>" [role]
 * The password is read from the HH_PASSWORD environment variable, or prompted for.
 * role defaults to admin (operator | supervisor | viewer | admin).
 */
require __DIR__ . '/_boot.php';

use App\Core\Auth;
use App\Core\DB;

$username = $argv[1] ?? '';
$name     = $argv[2] ?? '';
$role     = $argv[3] ?? 'admin';

if ($username === '' || $name === '' || !in_array($role, Auth::ROLES, true)) {
    fwrite(STDERR, "Usage: php cli/create_admin.php <username> \"<Full name>\" [operator|supervisor|viewer|admin]\n");
    exit(1);
}

$password = getenv('HH_PASSWORD') ?: cli_secret('Password (min 8 characters): ');
if (mb_strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
if (DB::val('SELECT id FROM users WHERE username = ?', [$username])) {
    fwrite(STDERR, "User '{$username}' already exists.\n");
    exit(1);
}

DB::exec(
    'INSERT INTO users (site_id, name, username, password_hash, role) VALUES (1, ?, ?, ?, ?)',
    [$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]
);
echo "Created {$role} '{$username}'.\n";
