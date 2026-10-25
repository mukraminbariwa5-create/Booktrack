<?php
/**
 * Reminder emails: a book due in the next couple of days, a book that has
 * gone overdue, and a hold that's ready to collect.
 *
 * Each loan gets each kind of reminder at most once (reminder_log), so running
 * the sender again, from the librarian's button, the daily run or a scheduled
 * task, never sends duplicates.
 */

function reminders_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query('SELECT 1 FROM reminder_log LIMIT 1');
        return;
    } catch (PDOException $e) {
        // missing on older installs: create it
    }
    try {
        if (db_is_mysql()) {
            db()->exec("CREATE TABLE reminder_log (
                loan_id INT UNSIGNED NOT NULL,
                kind    VARCHAR(20) NOT NULL,
                sent_at DATETIME NOT NULL,
                PRIMARY KEY (loan_id, kind),
                CONSTRAINT fk_reminder_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            db()->exec("CREATE TABLE reminder_log (loan_id INTEGER NOT NULL REFERENCES loans(id) ON DELETE CASCADE,
                kind TEXT NOT NULL, sent_at TEXT NOT NULL, PRIMARY KEY (loan_id, kind))");
        }
    } catch (PDOException $e) {
        // created by another request
    }
}

function reminder_days_before(): int
{
    return max(1, (int)config('mail.remind_days_before', 2));
}

/**
 * Send every reminder that's due and hasn't been sent yet.
 * Returns ['due_soon' => n, 'overdue' => n].
 */
function send_due_reminders(): array
{
    reminders_ensure_schema();
    $sent = ['due_soon' => 0, 'overdue' => 0];
    $today = today();
    $soon = date('Y-m-d', strtotime('+' . reminder_days_before() . ' days'));

    $rows = q_all("SELECT l.id, l.due_at, l.renewals, b.title, b.author, u.name, u.email
                   FROM loans l JOIN books b ON b.id = l.book_id JOIN users u ON u.id = l.user_id
                   WHERE l.status = 'borrowed' AND l.due_at <= ? AND u.is_active = 1 AND u.email IS NOT NULL AND u.email <> ''
                   ORDER BY l.due_at", [$soon]);
    foreach ($rows as $l) {
        $kind = $l['due_at'] < $today ? 'overdue' : 'due_soon';
        if (q_val('SELECT COUNT(*) FROM reminder_log WHERE loan_id = ? AND kind = ?', [$l['id'], $kind])) continue;
        // claim the reminder first, so two runs at the same moment can't both send it
        try {
            q('INSERT INTO reminder_log (loan_id, kind, sent_at) VALUES (?, ?, ?)', [$l['id'], $kind, date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            continue;
        }
        [$subject, $body] = $kind === 'overdue' ? overdue_email($l) : due_soon_email($l);
        if (send_mail($l['email'], $l['name'], $subject, $body)) {
            $sent[$kind]++;
        } else {
            q('DELETE FROM reminder_log WHERE loan_id = ? AND kind = ?', [$l['id'], $kind]); // try again next run
        }
    }
    $total = $sent['due_soon'] + $sent['overdue'];
    if ($total) {
        log_activity('reminders.sent', plural($total, 'reminder email') . ': ' . $sent['due_soon'] . ' due soon, ' . $sent['overdue'] . ' overdue');
    }
    return $sent;
}

function first_name(string $name): string
{
    return preg_split('/\s+/', trim($name))[0] ?: $name;
}

function due_soon_email(array $l): array
{
    $days = days_until($l['due_at']);
    $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days');
    $renew = (int)$l['renewals'] < 1
        ? "Need more time? You can renew it once for 7 more days:\n" . absolute_url('loans.php')
        : "You've already renewed it once, so please bring it back by then.\n" . absolute_url('loans.php');
    return [
        "“{$l['title']}” is due back $when",
        "Hi " . first_name($l['name']) . ",\n\n"
        . "“{$l['title']}” by {$l['author']} is due back on " . fmt_date($l['due_at'], 'l, j F') . ".\n\n"
        . $renew . "\n\n"
        . "— " . config('app_name', 'BookTrack') . " Library\n",
    ];
}

function overdue_email(array $l): array
{
    return [
        "“{$l['title']}” is overdue",
        "Hi " . first_name($l['name']) . ",\n\n"
        . "“{$l['title']}” by {$l['author']} was due back on " . fmt_date($l['due_at'], 'l, j F') . ".\n\n"
        . "Please bring it to the library desk. You can't borrow other books until it's returned.\n"
        . fine_note($l)
        . "Once you've handed it in, press Return on My loans:\n" . absolute_url('loans.php') . "\n\n"
        . "— " . config('app_name', 'BookTrack') . " Library\n",
    ];
}

/** The late-fee line for an overdue email ('' when fines are off). */
function fine_note(array $l): string
{
    if (!fines_enabled()) return '';
    $so_far = fine_for_days(late_days((string)$l['due_at'], today()));
    $max = (float)config('fines.max_per_book', 0);
    return 'The late fee is ' . money((float)config('fines.per_day')) . ' a day' . ($max > 0 ? ' (at most ' . money($max) . ')' : '')
        . ($so_far > 0 ? ', ' . money($so_far) . ' so far' : '') . ". It stops growing when the book is checked in.\n\n";
}

/** Email the member whose hold just became ready (once per hold). */
function notify_hold_ready(int $holdId): void
{
    $h = q_one("SELECT h.id, h.book_id, h.expires_at, h.notified_at, b.title, b.author, u.name, u.email, u.is_active
                FROM holds h JOIN books b ON b.id = h.book_id JOIN users u ON u.id = h.user_id WHERE h.id = ? AND h.status = 'ready'", [$holdId]);
    if (!$h || $h['notified_at'] || !(int)$h['is_active'] || !$h['email']) return;
    q('UPDATE holds SET notified_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $holdId]);
    send_mail($h['email'], $h['name'], "Your hold is ready: “{$h['title']}”",
        "Hi " . first_name($h['name']) . ",\n\n"
        . "Good news: a copy of “{$h['title']}” by {$h['author']} is being kept for you until " . fmt_date($h['expires_at'], 'l, j F') . ".\n\n"
        . "Borrow it from the book page before then, or it goes to the next person in line:\n"
        . absolute_url('book.php', ['id' => $h['book_id']]) . "\n\n"
        . "— " . config('app_name', 'BookTrack') . " Library\n");
}

/**
 * Send the day's reminders on the first page view of each day, so reminders go
 * out even without a scheduled task. Records the date in storage/.
 */
function run_daily_reminders(): void
{
    if (config('mail.driver', 'log') === 'off' || !config('mail.daily_auto', true)) return;
    $dir = APP_ROOT . '/storage';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    $stamp = $dir . '/reminders-last-run.txt';
    $fh = @fopen($stamp, 'c+');
    if (!$fh) return;
    try {
        if (!flock($fh, LOCK_EX | LOCK_NB)) return;          // another request is on it
        $last = trim((string)stream_get_contents($fh));
        if ($last === today()) return;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, today());
        fflush($fh);
        send_due_reminders();
    } catch (Throwable $e) {
        // reminders must never break a page
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function reminders_last_run(): ?string
{
    $f = APP_ROOT . '/storage/reminders-last-run.txt';
    return is_file($f) ? (trim((string)file_get_contents($f)) ?: null) : null;
}
