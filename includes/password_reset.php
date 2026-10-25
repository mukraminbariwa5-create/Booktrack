<?php
/**
 * Self-service password reset by email.
 *
 * - The link holds a random 256-bit token; only its SHA-256 hash is stored, so a
 *   leaked database can't be used to reset anyone's password.
 * - Links work once and expire after RESET_TTL_MINUTES. Asking again cancels older links.
 * - The page answers the same way whether or not an account matches, so it can't be
 *   used to find out which usernames or emails exist.
 * - At most RESET_MAX_PER_USER emails per account and RESET_MAX_PER_IP requests per
 *   connection each hour, so it can't be used to flood someone's inbox.
 */

const RESET_TTL_MINUTES = 30;
const RESET_MAX_PER_USER = 3;
const RESET_MAX_PER_IP = 10;

function password_resets_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query('SELECT 1 FROM password_resets LIMIT 1');
        return;
    } catch (PDOException $e) {
        // missing on older installs: create it
    }
    try {
        if (db_is_mysql()) {
            db()->exec("CREATE TABLE password_resets (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id     INT UNSIGNED NULL,
                token_hash  CHAR(64) NULL,
                ip          VARCHAR(45) NOT NULL,
                created_at  DATETIME NOT NULL,
                expires_at  DATETIME NULL,
                used_at     DATETIME NULL,
                UNIQUE KEY uq_password_resets_token (token_hash),
                INDEX idx_password_resets_user (user_id, created_at),
                INDEX idx_password_resets_ip (ip, created_at),
                CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            db()->exec("CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL REFERENCES users(id) ON DELETE CASCADE, token_hash TEXT NULL UNIQUE,
                ip TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NULL, used_at TEXT NULL)");
        }
    } catch (PDOException $e) {
        // created by another request
    }
}

function reset_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

/**
 * Handle a "forgot password" request for a username or email address.
 * Returns an error message only for rate limiting; otherwise null, whether or
 * not an email was actually sent (see the note at the top of this file).
 */
function request_password_reset(string $identifier): ?string
{
    password_resets_ensure_schema();
    $identifier = mb_substr(strtolower(trim($identifier)), 0, 160);
    $ip = reset_client_ip();
    $now = date('Y-m-d H:i:s');
    $hourAgo = date('Y-m-d H:i:s', time() - 3600);

    $byIp = (int)q_val('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > ?', [$ip, $hourAgo]);
    if ($byIp >= RESET_MAX_PER_IP) return 'Too many reset requests from this connection. Try again in an hour.';

    $user = q_one('SELECT id, name, username, email FROM users
                   WHERE is_active = 1 AND (username = ? OR LOWER(email) = ?)
                   ORDER BY CASE WHEN username = ? THEN 0 ELSE 1 END LIMIT 1', [$identifier, $identifier, $identifier]);

    // Count every request against the IP, even ones that match no account.
    // Shared demo accounts never get reset links (one visitor could lock out the rest).
    if (!$user || !filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) || is_demo_account($user)) {
        q('INSERT INTO password_resets (user_id, token_hash, ip, created_at) VALUES (NULL, NULL, ?, ?)', [$ip, $now]);
        return null;
    }

    $byUser = (int)q_val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?', [$user['id'], $hourAgo]);
    if ($byUser >= RESET_MAX_PER_USER) {
        // Quietly do nothing more: the member already has recent links in their inbox.
        q('INSERT INTO password_resets (user_id, token_hash, ip, created_at) VALUES (NULL, NULL, ?, ?)', [$ip, $now]);
        return null;
    }

    $token = bin2hex(random_bytes(32));
    transaction(function () use ($user, $token, $ip, $now) {
        // A new link replaces any older one that hasn't been used.
        q('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL AND token_hash IS NOT NULL', [$now, $user['id']]);
        q('INSERT INTO password_resets (user_id, token_hash, ip, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$user['id'], hash('sha256', $token), $ip, $now, date('Y-m-d H:i:s', time() + RESET_TTL_MINUTES * 60)]);
    });
    // Old rows aren't needed once they can't count toward a limit or be used.
    q('DELETE FROM password_resets WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);

    [$subject, $body] = password_reset_email($user, $token);
    send_mail((string)$user['email'], (string)$user['name'], $subject, $body);
    return null;
}

function password_reset_email(array $user, string $token): array
{
    $app = config('app_name', 'BookTrack');
    return [
        "Reset your $app password",
        "Hi " . first_name((string)$user['name']) . ",\n\n"
        . "Someone asked to reset the password for your $app account (@{$user['username']}).\n"
        . "To choose a new password, open this link within " . RESET_TTL_MINUTES . " minutes:\n\n"
        . absolute_url('reset_password.php', ['token' => $token]) . "\n\n"
        . "The link works once. If you didn't ask for this, you can ignore this email:\n"
        . "your password stays the same.\n\n"
        . "— $app Library\n",
    ];
}

/** The reset row and its member for a token from a link, or null if it's unknown, used or expired. */
function find_password_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    password_resets_ensure_schema();
    $row = q_one('SELECT r.id, r.user_id, r.expires_at, u.name, u.username
                  FROM password_resets r JOIN users u ON u.id = r.user_id
                  WHERE r.token_hash = ? AND r.used_at IS NULL AND u.is_active = 1', [hash('sha256', $token)]);
    if (!$row || strtotime((string)$row['expires_at']) <= time()) return null;
    return $row;
}

/**
 * Set a new password from a reset link. Returns ['field' => 'message'] errors,
 * or [] when the password was changed.
 */
function complete_password_reset(string $token, string $password, string $confirm): array
{
    $errors = [];
    if (strlen($password) < 8) $errors['password'] = 'Use at least 8 characters.';
    elseif (strlen($password) > 72) $errors['password'] = 'Use 72 characters or fewer.';
    elseif ($password !== $confirm) $errors['confirm'] = 'The passwords don\'t match.';
    if ($errors) return $errors;

    $done = transaction(function () use ($token, $password) {
        $reset = find_password_reset($token);
        if (!$reset || is_demo_account((int)$reset['user_id'])) return null;
        $now = date('Y-m-d H:i:s');
        // Marking it used inside the transaction means two clicks can't both succeed.
        $claimed = q('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, $reset['id']])->rowCount();
        if (!$claimed) return null;
        q('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $reset['user_id']]);
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        login_attempts_sql('DELETE FROM login_attempts WHERE username = ?', [$reset['username']]);
        q('INSERT INTO activity_log (user_id, actor, action, details, created_at) VALUES (?, ?, ?, ?, ?)',
            [$reset['user_id'], $reset['username'], 'user.password_reset', $reset['name'] . ' (@' . $reset['username'] . ')', $now]);
        return $reset;
    });
    return $done ? [] : ['form' => 'This reset link has expired or was already used. Ask for a new one.'];
}
