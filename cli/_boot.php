<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

/** Read a line from STDIN (visible). */
function cli_ask(string $prompt): string
{
    echo $prompt;
    return trim((string) fgets(STDIN));
}

/** Read a password without echo where the platform allows it. */
function cli_secret(string $prompt): string
{
    echo $prompt;
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: no portable no-echo read in plain PHP; fall back to visible input.
        return trim((string) fgets(STDIN));
    }
    system('stty -echo');
    $v = trim((string) fgets(STDIN));
    system('stty echo');
    echo PHP_EOL;
    return $v;
}
