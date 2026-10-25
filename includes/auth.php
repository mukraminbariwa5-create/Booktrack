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
    if (two_factor_enabled((int)$user['id'])) {
        two_factor_begin((int)$user['id']);   // signed in only after the code (two_factor.php)
    } else {
        sign_in_as((int)$user['id']);
    }
    return null;
}

/** Start a fresh session for a user (new session id, so an old cookie can't be reused). */
function sign_in_as(int $userId): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $userId]);
}

/**
 * Validate a new member's details. Returns ['field' => 'message'] for every
 * problem, so each message can be shown right under its field.
 */
function validate_new_member(array $d, string $password, string $confirm): array
{
    $e = [];
    if ($d['name'] === '') $e['name'] = 'Enter your full name.';
    elseif (mb_strlen($d['name']) > 120) $e['name'] = 'Use 120 characters or fewer.';

    if ($d['username'] === '') $e['username'] = 'Choose a username.';
    elseif (!preg_match('/^[a-z0-9._-]{3,60}$/', $d['username'])) $e['username'] = 'Use 3 to 60 letters, numbers, dots, dashes or underscores.';
    elseif (q_val('SELECT COUNT(*) FROM users WHERE username = ?', [$d['username']])) $e['username'] = 'That username is taken. Try another.';

    if ($d['email'] === '') $e['email'] = 'Enter your email address.';
    elseif (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 160) $e['email'] = 'Enter a valid email address, like name@example.com.';
    elseif (q_val('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?', [strtolower($d['email'])])) $e['email'] = 'An account already uses this email. Try signing in instead.';

    if ($d['phone'] !== '' && !preg_match('/^[0-9+()\s-]{7,40}$/', $d['phone'])) $e['phone'] = 'Use numbers, spaces, + or - only.';

    if (strlen($password) < 8) $e['password'] = 'Use at least 8 characters.';
    elseif (strlen($password) > 72) $e['password'] = 'Use 72 characters or fewer.';
    elseif ($password !== $confirm) $e['confirm'] = 'The passwords don\'t match.';
    return $e;
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

/** After a successful sign-in: back to the page the user was trying to open, or their home page. */
function redirect_after_sign_in(): void
{
    $user = current_user(true);
    $intended = $_SESSION['intended'] ?? null;
    unset($_SESSION['intended']);
    // only follow same-app paths for the intended redirect
    if ($intended && str_starts_with($intended, base_url()) && !str_starts_with($intended, '//') && !str_contains($intended, 'logout')) {
        $isAdminPath = str_contains($intended, '/admin/');
        if ($isAdminPath === ($user['role'] === 'admin')) { header('Location: ' . $intended); exit; }
    }
    redirect(home_for($user));
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
    // Optional policy: librarians must protect their account with two-step sign-in first.
    if ($role === 'admin' && config('auth.require_2fa_for_librarians') && !two_factor_enabled((int)$user['id']) && !is_demo_account($user)) {
        if (wants_json()) json_out(['ok' => false, 'error' => 'Turn on two-step sign-in first.'], 403);
        flash('error', 'Librarian accounts need two-step sign-in. Turn it on below to continue.');
        redirect('profile.php', ['setup' => '2fa']);
    }
    return $user;
}
