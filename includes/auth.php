<?php
/**
 * Authentication and role checks.
 */

function current_user(bool $refresh = false): ?array
{
    static $cache = [];
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) return null;
    if ($refresh || !array_key_exists($id, $cache)) {
        $cache[$id] = q_one('SELECT id, name, username, email, phone, role, is_active, created_at FROM users WHERE id = ?', [$id]);
    }
    $user = $cache[$id];
    if (!$user || !(int)$user['is_active']) {
        logout();
        return null;
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

/*
 * Brute-force protection, stored in the database so it can't be dodged by
 * clearing cookies: 5 failed attempts for one username (or 20 from one IP)
 * within 5 minutes locks sign-in for that username / IP for 5 minutes.
 */
const LOGIN_WINDOW = 300;
const LOGIN_MAX_PER_USER = 5;
const LOGIN_MAX_PER_IP = 20;

function login_attempts_sql(string $sql, array $params = [])
{
    try {
        return q($sql, $params);
    } catch (PDOException $e) {
        // Databases installed before this table existed get it on first use.
        db()->exec('CREATE TABLE IF NOT EXISTS login_attempts (username VARCHAR(60) NOT NULL, ip VARCHAR(45) NOT NULL, attempted_at INTEGER NOT NULL)');
        return q($sql, $params);
    }
}

/** Seconds until sign-in is allowed again for this username/IP, or 0. */
function login_locked_for(string $username, string $ip): int
{
    $since = time() - LOGIN_WINDOW;
    $row = login_attempts_sql('SELECT
            SUM(CASE WHEN username = ? THEN 1 ELSE 0 END) AS by_user,
            SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END) AS by_ip,
            MAX(attempted_at) AS latest
        FROM login_attempts WHERE attempted_at > ? AND (username = ? OR ip = ?)', [$username, $ip, $since, $username, $ip])->fetch();
    if ((int)$row['by_user'] >= LOGIN_MAX_PER_USER || (int)$row['by_ip'] >= LOGIN_MAX_PER_IP) {
        return max(1, (int)$row['latest'] + LOGIN_WINDOW - time());
    }
    return 0;
}

/** Attempt a login. Returns an error message, or null on success. */
function attempt_login(string $username, string $password): ?string
{
    $username = mb_substr(strtolower(trim($username)), 0, 60);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

    if ($wait = login_locked_for($username, $ip)) {
        return 'Too many failed attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
    }

    $user = q_one('SELECT id, password_hash, is_active FROM users WHERE username = ?', [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_attempts_sql('INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)', [$username, $ip, time()]);
        login_attempts_sql('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 86400]);
        return 'That username and password don\'t match.';
    }
    if (!(int)$user['is_active']) return 'This account has been deactivated. Please contact the library.';

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    login_attempts_sql('DELETE FROM login_attempts WHERE username = ?', [$username]);
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $user['id']]);
    return null;
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        session_destroy();
    }
}

function home_for(array $user): string
{
    return $user['role'] === 'admin' ? 'admin/index.php' : 'discover.php';
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        if (wants_json()) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }
    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    if ($user['role'] !== $role) {
        if (wants_json()) json_out(['ok' => false, 'error' => 'Not allowed.'], 403);
        redirect(home_for($user));
    }
    return $user;
}
