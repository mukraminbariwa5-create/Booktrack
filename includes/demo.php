<?php
/**
 * Public demo mode, for running BookTrack as an open portfolio demo.
 *
 * config 'demo' => ['enabled' => true] (set it in config.local.php on the demo server):
 *   - the one-click demo sign-in buttons are shown
 *   - the demo accounts are protected: nobody can change their password, details,
 *     two-step sign-in, or delete or deactivate them, so one visitor can't lock out the next
 *   - file uploads are off, so strangers can't publish images or files on the site
 *   - with 'reset_daily' => true, the first visit of each day puts the demo data back
 *     (MySQL only), so whatever visitors changed yesterday is gone
 */

function demo_mode(): bool
{
    return (bool)config('demo.enabled', false);
}

function demo_accounts(): array
{
    return (array)config('demo.accounts', ['admin', 'alice', 'brian', 'carla', 'diego', 'ella']);
}

/** True for a protected demo account (by user row, id or username). */
function is_demo_account($user): bool
{
    if (!demo_mode()) return false;
    if (is_int($user)) $user = q_val('SELECT username FROM users WHERE id = ?', [$user]);
    if (is_array($user)) $user = $user['username'] ?? '';
    return in_array(strtolower((string)$user), demo_accounts(), true);
}

/** Throws a friendly DomainException when $user is a protected demo account. */
function demo_guard($user, string $what): void
{
    if (is_demo_account($user)) {
        throw new DomainException("This is a shared demo account, so its $what can't be changed. Create your own account to try that.");
    }
}

function demo_uploads_blocked(): bool
{
    return demo_mode();
}

function demo_reset_stamp(): string
{
    return APP_ROOT . '/storage/demo-reset.txt';
}

/** Run the daily reset once per day (first request wins; others wait on the lock and skip). */
function run_daily_demo_reset(): void
{
    if (!demo_mode() || !config('demo.reset_daily', true) || !db_is_mysql()) return;
    $stamp = demo_reset_stamp();
    if (is_file($stamp) && trim((string)@file_get_contents($stamp)) === today()) return;
    $fh = @fopen($stamp, 'c+');
    if (!$fh) return;
    try {
        if (!flock($fh, LOCK_EX)) return;
        rewind($fh);
        if (trim((string)stream_get_contents($fh)) === today()) return;   // another request just did it
        demo_reset_now();
        ftruncate($fh, 0); rewind($fh); fwrite($fh, today());
    } catch (Throwable $e) {
        error_log('BookTrack demo reset failed: ' . $e->getMessage());
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Put every table back to the demo data and remove files visitors added. */
function demo_reset_now(): void
{
    require_once __DIR__ . '/installer.php';
    $c = config('db');
    install_database(installer_server_pdo($c), (string)$c['name']);
    foreach (glob(APP_ROOT . '/uploads/covers/*') ?: [] as $f) {
        if (is_file($f) && !str_starts_with(basename($f), 'seed-') && basename($f) !== '.gitkeep') @unlink($f);
    }
    foreach (glob(APP_ROOT . '/uploads/pdfs/*.pdf') ?: [] as $f) @unlink($f);
    foreach (glob(mail_dir() . '/*.eml') ?: [] as $f) @unlink($f);
    @unlink(APP_ROOT . '/storage/reminders-last-run.txt');
}
