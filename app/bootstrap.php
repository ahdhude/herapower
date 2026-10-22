<?php
declare(strict_types=1);

use App\Core\Config;

define('APP_ROOT', dirname(__DIR__));

// Composer's autoloader when installed; our own PSR-4 loader always works for App\.
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/Core/helpers.php';

Config::load(APP_ROOT . '/config/config.php');
date_default_timezone_set((string) Config::get('TIMEZONE', 'Indian/Maldives'));

if (Config::debug()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
