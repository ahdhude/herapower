<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

try {
    Auth::startSession();
    $router = require APP_ROOT . '/app/routes.php';
    $response = $router->dispatch(new Request());
} catch (\Throwable $e) {
    log_error($e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $req = new Request();
    $message = Config::debug() ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'Something went wrong. Please try again.';
    $response = $req->wantsJson()
        ? Response::json(['status' => 'error', 'message' => $message], 500)
        : Response::html(View::render('error', ['code' => 500, 'message' => $message], 'layout_plain'), 500);
}

$response->send();
