<?php
/**
 * BookTrack bootstrap — loaded at the top of every page and API endpoint.
 * Sets up configuration, errors, the session, the database and shared helpers.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/load_config.php';
$GLOBALS['__config'] = load_config(APP_ROOT);

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
    // Content Security Policy: scripts only from BookTrack's own files (no inline or injected
    // scripts), and pages only talk to this server. Inline style attributes are still allowed.
    // Cover previews come from Open Library, which serves images from archive.org.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: https://covers.openlibrary.org https://*.archive.org; font-src 'self'; "
        . "connect-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; "
        . "form-action 'self'; frame-ancestors 'self'");
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/covers.php';
require_once __DIR__ . '/holds.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/reviews.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/fines.php';
require_once __DIR__ . '/two_factor.php';
require_once __DIR__ . '/demo.php';

// Not installed yet (no tables): send people to the installer instead of an error page.
if (PHP_SAPI !== 'cli') {
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
    } catch (PDOException $e) {
        header('Location: ' . url('setup.php'));
        exit;
    }
}

// Public demo: put the demo data back on the first visit of each day.
if (PHP_SAPI !== 'cli') run_daily_demo_reset();
// Tables added after the first release are created on older installs.
reviews_ensure_schema();
fines_ensure_schema();
two_factor_ensure_schema();
// Keep the hold queue current (and send the day's reminders) before any page reads availability.
refresh_holds();
if (PHP_SAPI !== 'cli') run_daily_reminders();
