<?php
/**
 * Late fees.
 *
 * config.php → 'fines':
 *   per_day       charged for each day a book is late (after grace_days)
 *   max_per_book  the most one late book can cost
 *   grace_days    late days that are free
 *   block_at      unpaid total at which borrowing is paused (0 = never)
 *
 * A fine grows while a book is overdue and is fixed when the book is checked in,
 * so it counts the days until the book is actually back at the desk. Librarians
 * mark fines paid (at the desk) or waive them, and every change is logged.
 * The fine row keeps the book's title, so the record survives if the loan is deleted.
 */

function fines_enabled(): bool
{
    return (bool)config('fines.enabled', true) && (float)config('fines.per_day', 0) > 0;
}

function fines_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query('SELECT 1 FROM fines LIMIT 1');
        return;
    } catch (PDOException $e) {
        // missing on older installs: create it
    }
    try {
        if (db_is_mysql()) {
            db()->exec("CREATE TABLE fines (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                loan_id     INT UNSIGNED NULL,
                user_id     INT UNSIGNED NOT NULL,
                book_title  VARCHAR(255) NOT NULL,
                days_late   INT UNSIGNED NOT NULL,
                amount      DECIMAL(8,2) NOT NULL,
                status      VARCHAR(10) NOT NULL DEFAULT 'unpaid',
                created_at  DATETIME NOT NULL,
                settled_at  DATETIME NULL,
                settled_by  INT UNSIGNED NULL,
                note        VARCHAR(255) NULL,
                UNIQUE KEY uq_fines_loan (loan_id),
                INDEX idx_fines_user_status (user_id, status),
                INDEX idx_fines_status (status, created_at),
                CONSTRAINT fk_fines_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE SET NULL,
                CONSTRAINT fk_fines_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_fines_settled_by FOREIGN KEY (settled_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            db()->exec("CREATE TABLE fines (id INTEGER PRIMARY KEY AUTOINCREMENT,
                loan_id INTEGER NULL UNIQUE REFERENCES loans(id) ON DELETE SET NULL,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                book_title TEXT NOT NULL, days_late INTEGER NOT NULL, amount REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'unpaid' CHECK (status IN ('unpaid','paid','waived')),
                created_at TEXT NOT NULL, settled_at TEXT NULL,
                settled_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, note TEXT NULL)");
        }
    } catch (PDOException $e) {
        // created by another request
    }
}

/** "₱1,250" or "₱12.50" */
function money(float $amount): string
{
    $sym = (string)config('fines.currency', '₱');
    return $sym . number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);
}

/** Chargeable late days for a loan that was (or will be) back on $endDate. */
function late_days(string $dueAt, string $endDate): int
{
    $late = (int)floor((strtotime($endDate) - strtotime($dueAt)) / 86400);
    return max(0, $late - (int)config('fines.grace_days', 0));
}

function fine_for_days(int $days): float
{
    if ($days <= 0 || !fines_enabled()) return 0.0;
    $amount = $days * (float)config('fines.per_day');
    $max = (float)config('fines.max_per_book', 0);
    return round($max > 0 ? min($amount, $max) : $amount, 2);
}

/** What an overdue book would cost if it came back today (0 when it isn't late). */
function accruing_fine(array $loan): float
{
    if ($loan['status'] === 'returned') return 0.0;
    return fine_for_days(late_days((string)$loan['due_at'], today()));
}

/** Record the fine for a loan that's just been checked in late. Returns the amount (0 if none). */
function charge_fine(array $loan, string $returnedOn): float
{
    if (!fines_enabled()) return 0.0;
    fines_ensure_schema();
    $days = late_days((string)$loan['due_at'], $returnedOn);
    $amount = fine_for_days($days);
    if ($amount <= 0) return 0.0;
    if (q_val('SELECT COUNT(*) FROM fines WHERE loan_id = ?', [$loan['id']])) return 0.0;
    q("INSERT INTO fines (loan_id, user_id, book_title, days_late, amount, status, created_at) VALUES (?, ?, ?, ?, ?, 'unpaid', ?)",
        [$loan['id'], $loan['user_id'], $loan['title'], $days, $amount, date('Y-m-d H:i:s')]);
    log_activity('fine.charged', money($amount) . " to {$loan['member_name']} for “{$loan['title']}” (" . plural($days, 'day') . ' late)');
    return $amount;
}

function unpaid_fines_total(int $userId): float
{
    fines_ensure_schema();
    return (float)q_val("SELECT COALESCE(SUM(amount), 0) FROM fines WHERE user_id = ? AND status = 'unpaid'", [$userId]);
}

/** A member's fines, unpaid first, newest first. */
function member_fines(int $userId, bool $unpaidOnly = false): array
{
    fines_ensure_schema();
    return q_all("SELECT f.*, l.book_id, l.due_at, l.returned_at FROM fines f LEFT JOIN loans l ON l.id = f.loan_id
                  WHERE f.user_id = ?" . ($unpaidOnly ? " AND f.status = 'unpaid'" : '') . "
                  ORDER BY (f.status = 'unpaid') DESC, f.created_at DESC, f.id DESC", [$userId]);
}

/** Overdue books still out, with what each would cost today. */
function member_accruing_fines(int $userId): array
{
    if (!fines_enabled()) return [];
    $out = [];
    foreach (member_active_loans($userId) as $l) {
        $amount = accruing_fine($l);
        if ($amount > 0) $out[] = $l + ['fine' => $amount, 'days_late' => late_days((string)$l['due_at'], today())];
    }
    return $out;
}

/** Librarian marks a fine paid or waived. */
function settle_fine(int $fineId, string $how, int $byUserId, string $note = ''): array
{
    if (!in_array($how, ['paid', 'waived'], true)) throw new DomainException('Choose paid or waived.');
    fines_ensure_schema();
    return transaction(function () use ($fineId, $how, $byUserId, $note) {
        $f = q_one('SELECT f.*, u.name AS member_name FROM fines f JOIN users u ON u.id = f.user_id WHERE f.id = ?' . for_update(), [$fineId]);
        if (!$f) throw new DomainException('Fine not found.');
        if ($f['status'] !== 'unpaid') throw new DomainException('This fine was already ' . $f['status'] . '.');
        $note = mb_substr(trim($note), 0, 255);
        q('UPDATE fines SET status = ?, settled_at = ?, settled_by = ?, note = ? WHERE id = ?',
            [$how, date('Y-m-d H:i:s'), $byUserId, $note !== '' ? $note : null, $fineId]);
        log_activity('fine.' . $how, money((float)$f['amount']) . " from {$f['member_name']} for “{$f['book_title']}”" . ($note !== '' ? " ($note)" : ''));
        return $f;
    });
}

/** Unpaid fines across the library: [count, total]. */
function unpaid_fines_summary(): array
{
    fines_ensure_schema();
    $r = q_one("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM fines WHERE status = 'unpaid'");
    return [(int)$r['n'], (float)$r['total']];
}

function fine_badge(array $f): string
{
    return [
        'unpaid' => '<span class="badge badge--danger">Unpaid</span>',
        'paid'   => '<span class="badge badge--ok">Paid</span>',
        'waived' => '<span class="badge badge--muted">Waived</span>',
    ][$f['status']] ?? '';
}
