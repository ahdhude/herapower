<?php
declare(strict_types=1);

$envFile = __DIR__ . '/env.php';
$env = is_file($envFile) ? require $envFile : [];

return array_merge([
    'APP_ENV'      => 'production',
    'APP_DEBUG'    => false,
    'APP_NAME'     => 'Herahakatha',
    'APP_BASE'     => '',
    'DB_HOST'      => '127.0.0.1',
    'DB_PORT'      => 3306,
    'DB_NAME'      => 'herapower',
    'DB_USER'      => 'root',
    'DB_PASS'      => '',
    'TIMEZONE'     => 'Indian/Maldives',
    'DB_TZ_OFFSET' => '+05:00',          // Maldives has no DST
    'SESSION_NAME' => 'hh_session',
], $env);
