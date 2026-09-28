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
check('Loan limit (3) is enforced', throws(fn() => create_loan(2, 9), 'up to 3'));
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

echo "\n$pass passed, $fail failed\n";
@unlink($db);
exit($fail ? 1 : 0);
