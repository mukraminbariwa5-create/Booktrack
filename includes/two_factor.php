<?php
/**
 * Two-step sign-in with an authenticator app (TOTP, RFC 6238): Google Authenticator,
 * Microsoft Authenticator, Authy, 1Password and the like.
 *
 * - After the password is checked, the account is only "pending" until a 6-digit code
 *   (or a one-time recovery code) is entered on two_factor.php, within 5 minutes.
 * - Codes are accepted for the current 30-second step and one step either side
 *   (clock drift), and each step can be used only once, so an overheard code can't be replayed.
 * - 5 wrong codes in 5 minutes end the attempt; the password must be entered again.
 * - 10 recovery codes are shown once when it's turned on. Only their SHA-256 hashes are
 *   stored, and each works once.
 * - The shared secret itself has to be readable to check codes, so it's stored as is;
 *   see SECURITY.md.
 */

const TOTP_STEP = 30;
const TOTP_DIGITS = 6;
const TWO_FACTOR_PENDING_TTL = 300;
const TWO_FACTOR_MAX_TRIES = 5;
const RECOVERY_CODE_COUNT = 10;

function two_factor_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query('SELECT 1 FROM two_factor LIMIT 1');
        return;
    } catch (PDOException $e) {
        // missing on older installs: create it
    }
    try {
        if (db_is_mysql()) {
            db()->exec("CREATE TABLE two_factor (
                user_id        INT UNSIGNED PRIMARY KEY,
                secret         VARCHAR(64) NOT NULL,
                last_step      BIGINT NOT NULL DEFAULT 0,
                recovery_codes TEXT NOT NULL,
                enabled_at     DATETIME NOT NULL,
                CONSTRAINT fk_two_factor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            db()->exec("CREATE TABLE two_factor (user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                secret TEXT NOT NULL, last_step INTEGER NOT NULL DEFAULT 0, recovery_codes TEXT NOT NULL, enabled_at TEXT NOT NULL)");
        }
    } catch (PDOException $e) {
        // created by another request
    }
}

// ---------------------------------------------------------------- TOTP

function base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[\s=-]+/', '', $text));
    $bits = '';
    foreach (str_split($text) as $ch) {
        $i = strpos($alphabet, $ch);
        if ($i === false) throw new InvalidArgumentException('Not a base32 string.');
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));   // 160 bits, as RFC 4226 recommends
}

/** The code for one 30-second step. */
function totp_code(string $secret, int $step, int $digits = TOTP_DIGITS): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $o = ord($hash[19]) & 0x0F;
    $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * The step a code matches (current time ± one step), or null.
 * Steps at or before $notAfter are refused, so a used code can't be replayed.
 */
function totp_match(string $secret, string $code, int $notAfter = 0, ?int $now = null): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) return null;
    $step = intdiv($now ?? time(), TOTP_STEP);
    foreach ([0, -1, 1] as $d) {
        $s = $step + $d;
        if ($s > $notAfter && hash_equals(totp_code($secret, $s), $code)) return $s;
    }
    return null;
}

/** otpauth:// link for the QR code. The username is shortened so the QR stays small. */
function totp_uri(string $secret, string $username): string
{
    $issuer = preg_replace('/[^A-Za-z0-9 ._-]/', '', (string)config('app_name', 'BookTrack')) ?: 'BookTrack';
    $label = rawurlencode($issuer) . ':' . rawurlencode(mb_substr($username, 0, 20));
    return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
}

// ---------------------------------------------------------------- recovery codes

function recovery_code_normalize(string $code): string
{
    return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

/** New plain-text codes like "k7qp-3mzx" (40 bits each). */
function recovery_codes_new(): array
{
    $codes = [];
    while (count($codes) < RECOVERY_CODE_COUNT) {
        $c = strtolower(substr(base32_encode(random_bytes(5)), 0, 8));
        $codes[$c] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return array_values($codes);
}

function recovery_codes_hash(array $codes): string
{
    return json_encode(array_map(fn($c) => hash('sha256', recovery_code_normalize($c)), $codes));
}

// ---------------------------------------------------------------- per user

function two_factor_for(int $userId): ?array
{
    two_factor_ensure_schema();
    return q_one('SELECT * FROM two_factor WHERE user_id = ?', [$userId]);
}

function two_factor_enabled(int $userId): bool
{
    return two_factor_for($userId) !== null;
}

function recovery_codes_left(int $userId): int
{
    $tf = two_factor_for($userId);
    return $tf ? count(json_decode((string)$tf['recovery_codes'], true) ?: []) : 0;
}

/**
 * Turn it on after the member proves their app works. Returns the recovery codes
 * to show once. Throws DomainException when the code is wrong.
 */
function two_factor_enable(int $userId, string $secret, string $code): array
{
    two_factor_ensure_schema();
    if (two_factor_enabled($userId)) throw new DomainException('Two-step sign-in is already on.');
    $step = totp_match($secret, $code);
    if ($step === null) throw new DomainException('That code didn\'t match. Check the time on your phone is set automatically, then try the newest code.');
    $codes = recovery_codes_new();
    q('INSERT INTO two_factor (user_id, secret, last_step, recovery_codes, enabled_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $secret, $step, recovery_codes_hash($codes), date('Y-m-d H:i:s')]);
    log_activity('user.2fa_enabled', two_factor_who($userId));
    return $codes;
}

function two_factor_disable(int $userId, string $why = 'user.2fa_disabled'): void
{
    two_factor_ensure_schema();
    if (q('DELETE FROM two_factor WHERE user_id = ?', [$userId])->rowCount()) {
        log_activity($why, two_factor_who($userId));
    }
}

function two_factor_new_recovery_codes(int $userId): array
{
    $codes = recovery_codes_new();
    q('UPDATE two_factor SET recovery_codes = ? WHERE user_id = ?', [recovery_codes_hash($codes), $userId]);
    log_activity('user.2fa_recovery_codes', two_factor_who($userId));
    return $codes;
}

function two_factor_who(int $userId): string
{
    $u = q_one('SELECT name, username FROM users WHERE id = ?', [$userId]);
    return $u ? $u['name'] . ' (@' . $u['username'] . ')' : 'user #' . $userId;
}

/**
 * Check a 6-digit code or a recovery code for a user.
 * Returns 'totp', 'recovery' or null. Marks the step / recovery code as used.
 */
function two_factor_verify(int $userId, string $input): ?string
{
    return transaction(function () use ($userId, $input) {
        $tf = q_one('SELECT * FROM two_factor WHERE user_id = ?' . for_update(), [$userId]);
        if (!$tf) return null;
        $step = totp_match((string)$tf['secret'], $input, (int)$tf['last_step']);
        if ($step !== null) {
            q('UPDATE two_factor SET last_step = ? WHERE user_id = ? AND last_step < ?', [$step, $userId, $step]);
            return 'totp';
        }
        $norm = recovery_code_normalize($input);
        if (strlen($norm) !== 8) return null;
        $hash = hash('sha256', $norm);
        $left = json_decode((string)$tf['recovery_codes'], true) ?: [];
        foreach ($left as $i => $h) {
            if (hash_equals($h, $hash)) {
                unset($left[$i]);
                q('UPDATE two_factor SET recovery_codes = ? WHERE user_id = ?', [json_encode(array_values($left)), $userId]);
                return 'recovery';
            }
        }
        return null;
    });
}

// ---------------------------------------------------------------- the sign-in step

/** Password was right; hold the sign-in until the code is entered. */
function two_factor_begin(int $userId): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    unset($_SESSION['user_id']);
    $_SESSION['2fa_pending'] = ['uid' => $userId, 'at' => time(), 'tries' => 0];
}

/** The user id waiting for a code, or null (also clears an expired attempt). */
function two_factor_pending(): ?int
{
    $p = $_SESSION['2fa_pending'] ?? null;
    if (!$p) return null;
    if (time() - (int)$p['at'] > TWO_FACTOR_PENDING_TTL) {
        unset($_SESSION['2fa_pending']);
        return null;
    }
    return (int)$p['uid'];
}

function two_factor_cancel(): void
{
    unset($_SESSION['2fa_pending']);
}

/**
 * Finish signing in with a code. Returns ['ok' => bool, 'error' => ?string, 'method' => ?string, 'restart' => bool].
 */
function two_factor_complete(string $input): array
{
    $uid = two_factor_pending();
    if (!$uid) return ['ok' => false, 'restart' => true, 'error' => 'That took too long. Sign in again.', 'method' => null];

    $key = '#2fa:' . $uid;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    $recent = (int)login_attempts_sql('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempted_at > ?', [$key, time() - LOGIN_WINDOW])->fetchColumn();
    if ($recent >= TWO_FACTOR_MAX_TRIES) {
        two_factor_cancel();
        return ['ok' => false, 'restart' => true, 'error' => 'Too many wrong codes. Wait a few minutes, then sign in again.', 'method' => null];
    }

    $method = two_factor_verify($uid, $input);
    if (!$method) {
        login_attempts_sql('INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)', [$key, $ip, time()]);
        $left = TWO_FACTOR_MAX_TRIES - $recent - 1;
        if ($left <= 0) {
            two_factor_cancel();
            return ['ok' => false, 'restart' => true, 'error' => 'Too many wrong codes. Wait a few minutes, then sign in again.', 'method' => null];
        }
        return ['ok' => false, 'restart' => false, 'method' => null,
            'error' => 'That code didn\'t work. ' . ($left === 1 ? 'One more try' : "$left more tries") . ' before you need to sign in again.'];
    }

    two_factor_cancel();
    login_attempts_sql('DELETE FROM login_attempts WHERE username = ?', [$key]);
    sign_in_as($uid);
    if ($method === 'recovery') log_activity('user.2fa_recovery_used', two_factor_who($uid));
    return ['ok' => true, 'restart' => false, 'error' => null, 'method' => $method];
}
