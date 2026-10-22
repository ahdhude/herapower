<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class UsersController
{
    public function index(Request $req): string
    {
        return View::render('admin/users', [
            'title'  => 'Users',
            'active' => 'more',
            'users'  => DB::all('SELECT id, name, username, role, active, last_login_at FROM users ORDER BY active DESC, name'),
            'roles'  => Auth::ROLES,
        ]);
    }

    public function create(Request $req): Response
    {
        $name = trim((string) $req->input('name', ''));
        $username = strtolower(trim((string) $req->input('username', '')));
        $role = (string) $req->input('role', 'operator');
        $password = (string) $req->input('password', '');

        $err = null;
        if ($name === '' || !preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            $err = 'Enter a name and a username of 3–50 letters, numbers, dots, dashes or underscores.';
        } elseif (!in_array($role, Auth::ROLES, true)) {
            $err = 'Choose a role.';
        } elseif (mb_strlen($password) < 8) {
            $err = 'The password must be at least 8 characters.';
        } elseif (DB::val('SELECT id FROM users WHERE username = ?', [$username])) {
            $err = "The username '{$username}' is taken.";
        }
        if ($err) {
            flash('error', $err);
            return Response::redirect(url('/admin/users'));
        }
        DB::exec(
            'INSERT INTO users (site_id, name, username, password_hash, role) VALUES (?, ?, ?, ?, ?)',
            [Auth::siteId(), mb_substr($name, 0, 100), $username, password_hash($password, PASSWORD_DEFAULT), $role]
        );
        flash('success', "Added {$name} ({$role}).");
        return Response::redirect(url('/admin/users'));
    }

    public function update(Request $req, array $p): Response
    {
        $id = (int) $p['id'];
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            return Response::redirect(url('/admin/users'));
        }
        $name = trim((string) $req->input('name', $u['name']));
        $role = (string) $req->input('role', $u['role']);
        $active = !empty($req->input('active')) ? 1 : 0;
        $password = (string) $req->input('password', '');

        if (!in_array($role, Auth::ROLES, true) || $name === '') {
            flash('error', 'Enter a name and choose a role.');
            return Response::redirect(url('/admin/users'));
        }
        if ($id === Auth::id() && ($role !== 'admin' || !$active)) {
            flash('error', 'You can\'t remove your own admin access.');
            return Response::redirect(url('/admin/users'));
        }
        if ($password !== '' && mb_strlen($password) < 8) {
            flash('error', 'The new password must be at least 8 characters.');
            return Response::redirect(url('/admin/users'));
        }
        DB::exec('UPDATE users SET name = ?, role = ?, active = ? WHERE id = ?', [mb_substr($name, 0, 100), $role, $active, $id]);
        if ($password !== '') {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            DB::exec('DELETE FROM login_attempts WHERE username = ?', [$u['username']]); // clears any lockout
        }
        flash('success', "Saved {$name}.");
        return Response::redirect(url('/admin/users'));
    }
}
