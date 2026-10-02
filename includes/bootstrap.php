<?php
/**
 * BookTrack bootstrap — loaded at the top of every page and API endpoint.
 * Sets up configuration, errors, the session, the database and shared helpers.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['__config'] = require APP_ROOT . '/config.php';

// Test suite / CLI override: run against SQLite instead of MySQL.
if ($sqlite = getenv('BOOKTRACK_SQLITE')) {
    $GLOBALS['__config']['db']['driver'] = 'sqlite';
    $GLOBALS['__config']['db']['sqlite_path'] = $sqlite;
}

function config(string $key, $default = null)
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
date_default_timezone_set(config('timezone', 'UTC'));

// ---------------------------------------------------------------- session
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('booktrack_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// Basic security headers for every response
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/covers.php';
