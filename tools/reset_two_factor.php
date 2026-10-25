<?php
/**
 * Emergency: turn off two-step sign-in for one account, from the command line only.
 * For a librarian who lost both their phone and their recovery codes.
 * Anyone who can run this already has access to the server and the database.
 *
 *   php tools/reset_two_factor.php <username>
 *   C:\xampp\php\php.exe C:\xampp\htdocs\booktrack-v2\tools\reset_two_factor.php admin
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';

$username = strtolower(trim($argv[1] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Usage: php tools/reset_two_factor.php <username>\n");
    exit(2);
}
$user = q_one('SELECT id, name, username FROM users WHERE username = ?', [$username]);
if (!$user) {
    fwrite(STDERR, "No account with the username \"$username\".\n");
    exit(1);
}
if (!two_factor_enabled((int)$user['id'])) {
    echo "Two-step sign-in is already off for @{$user['username']}.\n";
    exit(0);
}
two_factor_disable((int)$user['id'], 'user.2fa_reset');
echo "Two-step sign-in is now off for {$user['name']} (@{$user['username']}). Sign in with the password and set it up again from Profile.\n";
