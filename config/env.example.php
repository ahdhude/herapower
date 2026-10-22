<?php
// Copy to config/env.php and fill in. env.php is never committed or web-reachable.
return [
    'APP_ENV'   => 'local',        // 'production' on Hostinger
    'APP_DEBUG' => true,           // false in production
    'APP_BASE'  => '',             // '' at the domain root; '/herapower' for http://localhost/herapower/
    'DB_HOST'   => '127.0.0.1',
    'DB_PORT'   => 3306,
    'DB_NAME'   => 'herapower',
    'DB_USER'   => 'root',
    'DB_PASS'   => '',
];
