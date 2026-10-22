<?php
declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\Api;
use App\Controllers\AuthController;
use App\Controllers\EntryController;
use App\Controllers\ReportController;
use App\Controllers\TodayController;
use App\Core\Router;

$r = new Router();

$entry   = ['operator', 'supervisor', 'admin'];
$admin   = ['admin'];

// --- auth
$r->get('/login', [AuthController::class, 'showLogin'], 'public');
$r->post('/login', [AuthController::class, 'login'], 'public');
$r->post('/logout', [AuthController::class, 'logout']);

// --- pages
$r->get('/', [TodayController::class, 'index']);
$r->get('/more', [TodayController::class, 'more']);

$r->get('/entry', [EntryController::class, 'hub'], $entry);
$r->get('/entry/feeders/{group}', [EntryController::class, 'feeders'], $entry);
$r->get('/entry/solar', [EntryController::class, 'solar'], $entry);
$r->get('/entry/daily', [EntryController::class, 'daily'], $entry);
$r->get('/entry/genset', [EntryController::class, 'genset'], $entry);

$r->get('/reports', [ReportController::class, 'index']);
$r->get('/reports/feeders/daily', [ReportController::class, 'feedersDaily']);
$r->get('/reports/solar/daily', [ReportController::class, 'solarDaily']);
$r->get('/reports/monthly', [ReportController::class, 'monthly']);
$r->get('/reports/feeders/ranking', [ReportController::class, 'ranking']);
$r->get('/reports/genset', [ReportController::class, 'genset']);
$r->get('/reports/completeness', [ReportController::class, 'completeness']);

// --- admin
$r->get('/admin/meters', [Admin\MetersController::class, 'index'], $admin);
$r->get('/admin/meters/new', [Admin\MetersController::class, 'form'], $admin);
$r->get('/admin/meters/{id}/edit', [Admin\MetersController::class, 'form'], $admin);
$r->post('/admin/meters', [Admin\MetersController::class, 'save'], $admin);
$r->post('/admin/meters/{id}/toggle', [Admin\MetersController::class, 'toggle'], $admin);
$r->post('/admin/meters/{id}/move', [Admin\MetersController::class, 'move'], $admin);
$r->post('/admin/meters/{id}/delete', [Admin\MetersController::class, 'delete'], $admin);
$r->get('/admin/users', [Admin\UsersController::class, 'index'], $admin);
$r->post('/admin/users', [Admin\UsersController::class, 'create'], $admin);
$r->post('/admin/users/{id}', [Admin\UsersController::class, 'update'], $admin);
$r->get('/admin/settings', [Admin\SettingsController::class, 'index'], $admin);
$r->post('/admin/settings', [Admin\SettingsController::class, 'save'], $admin);
$r->get('/admin/audit', [Admin\AuditController::class, 'index'], $admin);
$r->get('/admin/import', [Admin\ImportController::class, 'index'], $admin);
$r->post('/admin/import/analyse', [Admin\ImportController::class, 'analyse'], $admin);
$r->get('/admin/import/preview/{token}', [Admin\ImportController::class, 'preview'], $admin);
$r->post('/admin/import/commit', [Admin\ImportController::class, 'commit'], $admin);
$r->post('/admin/import/cancel', [Admin\ImportController::class, 'cancel'], $admin);
$r->get('/admin/import/review', [Admin\ImportController::class, 'review'], $admin);
$r->get('/admin/import/batch/{id}', [Admin\ImportController::class, 'batch'], $admin);
$r->post('/admin/import/batch/{id}/undo', [Admin\ImportController::class, 'undo'], $admin);
$r->post('/admin/import/batch/{id}/bulk', [Admin\ImportController::class, 'bulk'], $admin);
$r->post('/admin/import/issues/{id}', [Admin\ImportController::class, 'resolve'], $admin);

// --- JSON API (session cookie + X-CSRF-Token on POST)
$r->get('/api/today', [Api\TodayController::class, 'index']);
$r->get('/api/csrf', [Api\TodayController::class, 'csrf']);
$r->post('/api/day-status', [Api\TodayController::class, 'approve'], ['supervisor', 'admin']);
$r->get('/api/slots', [Api\ReadingsController::class, 'slots'], $entry);
$r->get('/api/grid', [Api\ReadingsController::class, 'grid'], $entry);
$r->get('/api/daily', [Api\ReadingsController::class, 'daily'], $entry);
$r->post('/api/readings', [Api\ReadingsController::class, 'save'], $entry);
$r->post('/api/readings/batch', [Api\ReadingsController::class, 'batch'], $entry);
$r->get('/api/genset', [Api\GensetController::class, 'show'], $entry);
$r->post('/api/genset-logs', [Api\GensetController::class, 'save'], $entry);
$r->get('/api/chart', [Api\ChartController::class, 'index']);

return $r;
