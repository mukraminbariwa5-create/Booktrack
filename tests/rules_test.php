<?php
/**
 * Library rule tests — run from the project folder:
 *   php tests/rules_test.php
 * Uses a throwaway SQLite database built from database/booktrack.sql.
 */
$db = sys_get_temp_dir() . '/booktrack_rules_test.sqlite';
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/make_sqlite.php') . ' ' . escapeshellarg($db) . ' > ' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
putenv('BOOKTRACK_SQLITE=' . $db);
require __DIR__ . '/../includes/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ✔ " : "  ✘ ") . $name . "\n";
}
function throws(callable $fn, string $contains = ''): bool {
    try { $fn(); return false; } catch (DomainException $e) { return $contains === '' || str_contains($e->getMessage(), $contains); }
}

echo "Availability\n";
check('Seeded 48 Laws: 2 copies, 1 on loan → 1 available', (int)find_book(1)['available'] === 1);
check('How to Talk to Anyone: 1 copy on loan → 0 available', (int)find_book(3)['available'] === 0);

echo "Borrowing rules\n";
check('Cannot borrow a book with no free copies', throws(fn() => create_loan(5, 3), 'currently on loan'));
check('Cannot hold two copies of the same book', throws(fn() => create_loan(2, 1), 'already has a copy'));
check('Member with an overdue loan is blocked', throws(fn() => create_loan(3, 7), 'overdue'));
check('Admins cannot be given loans', throws(fn() => create_loan(1, 7), 'active members'));
$id = create_loan(2, 7);
check('Alice can borrow Atomic Habits', $id > 0);
check('Availability drops after borrowing', (int)find_book(7)['available'] === 1);
$loan = find_loan($id);
check('Due date is loan_days from today', $loan['due_at'] === date('Y-m-d', strtotime('+' . config('library.loan_days') . ' days')));
check('Loan limit (3) is enforced', throws(fn() => create_loan(2, 9), 'already have 3 books'));
check('Admin override skips the loan limit', create_loan(2, 9, null, false) > 0);

echo "Renewals & returns\n";
$due = renew_loan($id, 2);
check('Renew adds 7 days', $due === date('Y-m-d', strtotime($loan['due_at'] . ' +7 days')));
check('Second renewal is refused', throws(fn() => renew_loan($id, 2), 'already been renewed'));
check('Other members cannot touch the loan', throws(fn() => request_return($id, 3), 'not found'));
request_return($id, 2);
check('Return request sets status', find_loan($id)['status'] === 'return_requested');
check('Requested book still counts as on loan', (int)find_book(7)['available'] === 1);
complete_return($id);
check('Check-in marks returned with today\'s date', find_loan($id)['status'] === 'returned' && find_loan($id)['returned_at'] === today());
check('Copy is available again', (int)find_book(7)['available'] === 2);
check('Double check-in is refused', throws(fn() => complete_return($id), 'already returned'));
check('Overdue loans cannot be renewed', throws(fn() => renew_loan(3, 3), 'Overdue'));

echo "Favorites & auth\n";
check('Toggle favorite on', toggle_favorite(3, 1) === true);
check('Toggle favorite off', toggle_favorite(3, 1) === false);
$hash = q_val("SELECT password_hash FROM users WHERE username = 'admin'");
check('Seeded admin password works', password_verify('admin123', $hash));
check('Seeded member password works', password_verify('member123', q_val("SELECT password_hash FROM users WHERE username = 'alice'")));
check('Activity log records actions', (int)q_val("SELECT COUNT(*) FROM activity_log WHERE action = 'loan.returned'") >= 1);

echo "Sign-in protection & validation\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ($i = 0; $i < 5; $i++) attempt_login('carla', 'wrong');
check('5 failed sign-ins lock the account', str_contains((string)attempt_login('carla', 'member123'), 'Too many'));
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
check('Lockout follows the username, not the browser or IP', str_contains((string)attempt_login('carla', 'member123'), 'Too many'));
check('Other accounts are unaffected', attempt_login('diego', 'member123') === null);
check('Length limits are enforced server-side', length_errors(['title' => str_repeat('x', 201)], ['title' => [200, 'Title']]) === ['Title can be at most 200 characters.']);

echo "Holds\n";
$mailDir = sys_get_temp_dir() . '/booktrack_test_mail_' . getmypid();
$GLOBALS['__config']['mail']['driver'] = 'log';
$GLOBALS['__config']['mail']['dir'] = $mailDir;
$mails = fn(string $contains = '') => count(array_filter(glob($mailDir . '/*.eml') ?: [], fn($f) => $contains === '' || str_contains(file_get_contents($f), $contains)));
$issued = create_loan(5, 16, null, false);                       // Diego takes the only copy of 1984
check('No holds while a copy is on the shelf', throws(fn() => place_hold(4, 11), 'on the shelf'));
$h1 = place_hold(4, 16);
$h2 = place_hold(2, 16);
check('First hold is first in line', hold_position(member_hold(4, 16)) === 1);
check('Second hold is second in line', hold_position(member_hold(2, 16)) === 2);
check('Cannot join the same line twice', throws(fn() => place_hold(4, 16), 'already in line'));
check('The borrower cannot hold their own book', throws(fn() => place_hold(5, 16), 'already have'));
check('Members cannot cancel someone else\'s hold', throws(fn() => cancel_hold($h1, 2), 'not found'));
complete_return($issued);
check('A returned copy goes to the first in line', q_val('SELECT status FROM holds WHERE id = ?', [$h1]) === 'ready');
check('A kept copy is not shown as available', (int)find_book(16)['available'] === 0);
check('Others cannot borrow a copy kept for someone', throws(fn() => create_loan(2, 16, null, false), 'kept for a member'));
check('Hold-ready email is sent once', $mails('Your hold is ready') === 1);
$carla = create_loan(4, 16, null, false);
check('Borrowing fulfils the hold', q_val('SELECT status FROM holds WHERE id = ?', [$h1]) === 'fulfilled');
complete_return($carla);
check('Next in line gets the following copy', q_val('SELECT status FROM holds WHERE id = ?', [$h2]) === 'ready');
q("UPDATE holds SET expires_at = ? WHERE id = ?", [date('Y-m-d', strtotime('-1 day')), $h2]);
process_hold_queue();
check('Uncollected holds expire', q_val('SELECT status FROM holds WHERE id = ?', [$h2]) === 'expired');
check('An expired hold frees the copy', (int)find_book(16)['available'] === 1);
$GLOBALS['__config']['library']['max_holds'] = 1;
check('Hold limit is enforced', throws(fn() => place_hold(4, 3), 'up to 1 hold'));

echo "Reminder emails\n";
$first = send_due_reminders();
check('Overdue reminders are sent', $first['overdue'] >= 1 && $mails('was due back on') === $first['overdue']);
$second = send_due_reminders();
check('Running again sends nothing new', $second['due_soon'] + $second['overdue'] === 0);
q("UPDATE loans SET due_at = ? WHERE id = 1", [date('Y-m-d', strtotime('+1 day'))]);
$third = send_due_reminders();
check('A loan due tomorrow gets a "due soon" email', $third['due_soon'] === 1 && $mails('is due back on') === 1);
check('Emails link back to the site', $mails('loans.php') >= 1);
array_map('unlink', glob($mailDir . '/*') ?: []);
@rmdir($mailDir);

echo "Ratings & reviews\n";
check('Seeded ratings average into the book', abs((float)find_book(7)['rating'] - 4.5) < 0.01 && (int)find_book(7)['reviews'] === 2);
check('Members can\'t review a book they never borrowed', throws(fn() => save_review(2, 12, 4, ''), 'once you\'ve borrowed'));
check('Ratings must be 1 to 5', throws(fn() => save_review(2, 1, 6, ''), '1 to 5'));
check('Reviews have a length limit', throws(fn() => save_review(2, 1, 4, str_repeat('a', REVIEW_MAX_LENGTH + 1)), 'at most'));
check('A first review is created', save_review(2, 1, 4, '  Sharp and cynical.  ') === true);
check('Saving again updates instead of duplicating', save_review(2, 1, 2, '') === false && (int)q_val('SELECT COUNT(*) FROM reviews WHERE user_id = 2 AND book_id = 1') === 1);
check('An empty review keeps just the rating', member_review(2, 1)['body'] === null && (int)member_review(2, 1)['rating'] === 2);
check('Members can\'t delete someone else\'s review', throws(fn() => delete_review((int)member_review(4, 1)['id'], 2), 'not found'));
delete_review((int)member_review(2, 1)['id'], null);
check('Librarians can remove a review', member_review(2, 1) === null);
check('Reviewer names show first name and initial', reviewer_name('Alice Santos') === 'Alice S.');

echo "Late fees\n";
$cfg = &$GLOBALS['__config']['fines'];
$cfg = ['enabled' => true, 'currency' => '₱', 'per_day' => 5, 'max_per_book' => 200, 'grace_days' => 0, 'block_at' => 100];
check('Seeded fees: Carla owes ₱40, Diego\'s ₱10 is paid', unpaid_fines_total(4) === 40.0 && unpaid_fines_total(5) === 0.0);
check('A day\'s fee is per_day', fine_for_days(1) === 5.0 && fine_for_days(0) === 0.0);
check('Fees stop at max_per_book', fine_for_days(100) === 200.0);
$cfg['grace_days'] = 2;
check('Grace days are free', late_days(date('Y-m-d', strtotime('-3 days')), today()) === 1);
$cfg['grace_days'] = 0;
$brianLoan = (int)q_val("SELECT id FROM loans WHERE user_id = 3 AND status = 'borrowed' AND due_at < ?", [today()]);
check('An overdue book shows its fee so far (6 days = ₱30)', accruing_fine(find_loan($brianLoan)) === 30.0);
check('Checking in a late book charges the fee', complete_return($brianLoan) === 30.0 && unpaid_fines_total(3) === 30.0);
check('The fee keeps the book title', q_val('SELECT book_title FROM fines WHERE loan_id = ?', [$brianLoan]) === 'Rich Dad Poor Dad');
check('Each loan is charged once', charge_fine(find_loan($brianLoan), today()) === 0.0 && unpaid_fines_total(3) === 30.0);
$onTime = create_loan(5, 12);
check('On-time returns cost nothing', complete_return($onTime) === 0.0 && unpaid_fines_total(5) === 0.0);
q('UPDATE fines SET amount = 120 WHERE loan_id = ?', [$brianLoan]);
$b = borrow_block_reason(3);
check('Unpaid fees at block_at pause borrowing', $b !== null && $b['code'] === 'fines' && str_contains($b['message'], '₱120'));
check('…and creating a loan is refused', throws(fn() => create_loan(3, 12), 'unpaid late fees'));
$fid = (int)q_val('SELECT id FROM fines WHERE loan_id = ?', [$brianLoan]);
settle_fine($fid, 'paid', 1);
check('Marking paid clears the debt and the block', unpaid_fines_total(3) === 0.0 && (borrow_block_reason(3)['code'] ?? null) !== 'fines');
check('A settled fee can\'t be settled again', throws(fn() => settle_fine($fid, 'waived', 1), 'already paid'));
check('Only paid or waived are allowed', throws(fn() => settle_fine((int)q_val('SELECT id FROM fines WHERE user_id = 4'), 'refunded', 1), 'paid or waived'));
settle_fine((int)q_val('SELECT id FROM fines WHERE user_id = 4'), 'waived', 1, 'First time');
check('Waiving clears it and keeps the note', unpaid_fines_total(4) === 0.0 && q_val("SELECT note FROM fines WHERE user_id = 4") === 'First time');
check('Paying and waiving are logged', (int)q_val("SELECT COUNT(*) FROM activity_log WHERE action IN ('fine.paid','fine.waived','fine.charged')") === 3);
q('DELETE FROM loans WHERE id = ?', [$brianLoan]);
check('The fee record survives if the loan is deleted', (int)q_val("SELECT COUNT(*) FROM fines WHERE book_title = 'Rich Dad Poor Dad' AND loan_id IS NULL") === 1);
$cfg['enabled'] = false;
check('Turning fines off stops new fees', fine_for_days(10) === 0.0);
$cfg['enabled'] = true;
unset($cfg);

echo "Two-step sign-in\n";
$rfc = base32_encode('12345678901234567890');   // RFC 6238 appendix B test key (SHA-1)
$vectors = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];
check('Codes match the RFC 6238 test vectors', array_reduce(array_keys($vectors), fn($ok, $t) => $ok && totp_code($rfc, intdiv($t, 30), 8) === $vectors[$t], true));
check('Base32 round-trips', base32_decode(base32_encode("\x00\xffBookTrack\x10")) === "\x00\xffBookTrack\x10");
$secret = totp_new_secret();
check('New secrets are 160-bit', strlen(base32_decode($secret)) === 20);
$now = 1_800_000_000; $step = intdiv($now, 30);
check('The current code is accepted', totp_match($secret, totp_code($secret, $step), 0, $now) === $step);
check('Codes from 30 s either side are accepted', totp_match($secret, totp_code($secret, $step - 1), 0, $now) === $step - 1 && totp_match($secret, totp_code($secret, $step + 1), 0, $now) === $step + 1);
check('Older codes are refused', totp_match($secret, totp_code($secret, $step - 2), 0, $now) === null);
check('A used step can\'t be replayed', totp_match($secret, totp_code($secret, $step), $step, $now) === null);
check('Spaces in "123 456" are fine, letters are not', totp_match($secret, substr_replace(totp_code($secret, $step), ' ', 3, 0), 0, $now) === $step && totp_match($secret, 'abcdef', 0, $now) === null);
check('The QR link fits the QR generator', strlen(totp_uri($secret, str_repeat('x', 60))) <= 104 && str_starts_with(totp_uri($secret, 'alice'), 'otpauth://totp/BookTrack:alice?secret='));
check('Turning on needs a correct code', throws(fn() => two_factor_enable(2, $secret, '000000'), 'didn\'t match') && !two_factor_enabled(2));
$codes = two_factor_enable(2, $secret, totp_code($secret, intdiv(time(), 30)));
check('Turning on gives 10 recovery codes', count($codes) === 10 && count(array_unique($codes)) === 10 && preg_match('/^[a-z2-7]{4}-[a-z2-7]{4}$/', $codes[0]));
check('Only hashes of recovery codes are stored', !str_contains((string)q_val('SELECT recovery_codes FROM two_factor WHERE user_id = 2'), str_replace('-', '', $codes[0])));
check('The code used to turn it on can\'t be reused', two_factor_verify(2, totp_code($secret, intdiv(time(), 30))) === null);
$_SESSION = [];
attempt_login('alice', 'member123');
check('With it on, the password alone doesn\'t sign in', empty($_SESSION['user_id']) && two_factor_pending() === 2);
$r = two_factor_complete('111111');
check('A wrong code is refused and counted', !$r['ok'] && !$r['restart'] && str_contains($r['error'], '4 more tries'));
$r = two_factor_complete(strtoupper(str_replace('-', ' ', $codes[3])));
check('A recovery code signs in (any case, spaces)', $r['ok'] && $r['method'] === 'recovery' && ($_SESSION['user_id'] ?? null) === 2 && recovery_codes_left(2) === 9);
$_SESSION = [];
attempt_login('alice', 'member123');
check('Each recovery code works once', !two_factor_complete($codes[3])['ok']);
for ($i = 0; $i < 4; $i++) $r = two_factor_complete('222222');
check('5 wrong codes end the attempt', $r['restart'] && two_factor_pending() === null);
$_SESSION = ['2fa_pending' => ['uid' => 2, 'at' => time() - 301, 'tries' => 0]];
check('A pending sign-in expires after 5 minutes', two_factor_pending() === null);
check('New recovery codes replace the old ones', ($new = two_factor_new_recovery_codes(2)) && two_factor_verify(2, $codes[0]) === null && two_factor_verify(2, $new[0]) === 'recovery');
two_factor_disable(2);
$_SESSION = [];
attempt_login('alice', 'member123');
check('Turned off, the password signs in again', ($_SESSION['user_id'] ?? null) === 2 && two_factor_pending() === null);
check('Turning on and off is logged', (int)q_val("SELECT COUNT(*) FROM activity_log WHERE action IN ('user.2fa_enabled','user.2fa_disabled','user.2fa_recovery_used','user.2fa_recovery_codes')") === 4);
login_attempts_sql("DELETE FROM login_attempts WHERE username LIKE '#2fa:%'");
$_SESSION = [];

echo "Public demo mode\n";
check('Off by default: nothing is protected', !demo_mode() && !is_demo_account('alice'));
$GLOBALS['__config']['demo'] = ['enabled' => true, 'reset_daily' => false, 'accounts' => ['admin', 'alice', 'brian', 'carla', 'diego', 'ella']];
check('Demo accounts are recognised by name, id or row', is_demo_account('alice') && is_demo_account(2) && is_demo_account(['username' => 'ADMIN']) && !is_demo_account('someone-new'));
check('Changing a demo account is refused with a clear message', throws(fn() => demo_guard('alice', 'password'), 'shared demo account'));
$demoMail = sys_get_temp_dir() . '/booktrack_demo_mail_' . getmypid();
$GLOBALS['__config']['mail']['dir'] = $demoMail;
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
request_password_reset('alice');
check('Demo accounts get no reset links', !glob($demoMail . '/*.eml'));
$_FILES['cover_file'] = ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10];
check('Uploads are refused in demo mode', (function () { try { store_cover('cover_file'); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'turned off in the public demo'); } })());
unset($_FILES['cover_file']);
$GLOBALS['__config']['demo']['enabled'] = false;
array_map('unlink', glob($demoMail . '/*') ?: []); @rmdir($demoMail);

echo "Password reset by email\n";
$mailDir = sys_get_temp_dir() . '/booktrack_reset_mail_' . getmypid();
$GLOBALS['__config']['mail']['dir'] = $mailDir;
// The link in the email sent since the last call (several can land in the same second).
$seenMail = [];
$lastLink = function () use ($mailDir, &$seenMail): ?string {
    $new = array_diff(glob($mailDir . '/*.eml') ?: [], $seenMail);
    $seenMail = array_merge($seenMail, $new);
    if (count($new) !== 1) return null;
    preg_match('/reset_password\.php\?token=([a-f0-9]{64})/', (string)file_get_contents(reset($new)), $m);
    return $m[1] ?? null;
};
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
check('Unknown accounts get the same answer (no account probing)', request_password_reset('nobody-here') === null && !glob($mailDir . '/*.eml'));
check('A reset email is sent for a username', request_password_reset('alice') === null && ($t1 = $lastLink()) !== null);
check('Only a hash of the token is stored', (int)q_val('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$t1]) === 0
    && (int)q_val('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [hash('sha256', $t1)]) === 1);
check('Email addresses work too, in any case', request_password_reset('ALICE@example.com') === null && ($t2 = $lastLink()) !== $t1);
check('A newer link cancels the older one', find_password_reset($t1) === null && find_password_reset($t2) !== null);
check('Short passwords are refused', isset(complete_password_reset($t2, 'short', 'short')['password']));
check('Mismatched passwords are refused', isset(complete_password_reset($t2, 'new-pass-123', 'new-pass-124')['confirm']));
check('A valid link sets the new password', complete_password_reset($t2, 'new-pass-123', 'new-pass-123') === []
    && password_verify('new-pass-123', (string)q_val('SELECT password_hash FROM users WHERE username = ?', ['alice'])));
check('A link only works once', isset(complete_password_reset($t2, 'other-pass-1', 'other-pass-1')['form']));
check('The reset is in the activity log', (int)q_val("SELECT COUNT(*) FROM activity_log WHERE action = 'user.password_reset'") === 1);
q('UPDATE password_resets SET created_at = ?', [date('Y-m-d H:i:s', time() - 7200)]);
request_password_reset('brian'); $t3 = $lastLink();
q('UPDATE password_resets SET expires_at = ? WHERE token_hash = ?', [date('Y-m-d H:i:s', time() - 1), hash('sha256', $t3)]);
check('Expired links are refused', find_password_reset($t3) === null);
check('Garbage tokens are refused', find_password_reset('not-a-token') === null && find_password_reset(str_repeat('a', 64)) === null);
$before = count(glob($mailDir . '/*.eml') ?: []);
for ($i = 0; $i < 5; $i++) request_password_reset('carla');
check('At most 3 emails per account per hour', count(glob($mailDir . '/*.eml') ?: []) - $before === 3);
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
for ($i = 0; $i < 10; $i++) request_password_reset('nobody-' . $i);
check('Too many requests from one connection are refused', request_password_reset('diego') !== null);
q("UPDATE users SET is_active = 0 WHERE username = 'ella'");
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$before = count(glob($mailDir . '/*.eml') ?: []);
check('Deactivated accounts get no email', request_password_reset('ella') === null && count(glob($mailDir . '/*.eml') ?: []) === $before);
q("UPDATE users SET is_active = 1 WHERE username = 'ella'");
array_map('unlink', glob($mailDir . '/*') ?: []);
@rmdir($mailDir);

echo "\n$pass passed, $fail failed\n";
@unlink($db);
exit($fail ? 1 : 0);
