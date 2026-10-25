<?php
/**
 * Holds: a member can join the line for a book when every copy is out.
 *
 * waiting   — in line, ordered by when the hold was placed
 * ready     — a copy came back and is kept for this member until expires_at
 * fulfilled — the member borrowed it
 * cancelled — the member (or a librarian) took it back
 * expired   — the member didn't collect it in time; the copy goes to the next in line
 *
 * A ready hold reserves a copy, so it counts against availability exactly like
 * a loan does (see books_from() in library.php). The queue is brought up to
 * date once per request by refresh_holds(), so nothing depends on a cron job.
 */

const HOLD_OPEN = "('waiting','ready')";

function holds_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query('SELECT 1 FROM holds LIMIT 1');
        return;
    } catch (PDOException $e) {
        // table is missing on installs made before holds existed: create it
    }
    try {
        if (db_is_mysql()) {
            db()->exec("CREATE TABLE holds (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                book_id    INT UNSIGNED NOT NULL,
                status     ENUM('waiting','ready','fulfilled','cancelled','expired') NOT NULL DEFAULT 'waiting',
                created_at DATETIME NOT NULL,
                ready_at   DATETIME NULL,
                expires_at DATE NULL,
                notified_at DATETIME NULL,
                CONSTRAINT fk_holds_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_holds_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
                INDEX idx_holds_book_status (book_id, status, created_at),
                INDEX idx_holds_user_status (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            db()->exec("CREATE TABLE holds (id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
                status TEXT NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting','ready','fulfilled','cancelled','expired')),
                created_at TEXT NOT NULL, ready_at TEXT, expires_at TEXT, notified_at TEXT)");
        }
    } catch (PDOException $e) {
        // another request created it a moment ago
    }
}

/**
 * Bring the queue up to date: expire uncollected holds, then give every free
 * copy to the next member in line. Cheap when nobody is waiting.
 */
function refresh_holds(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    holds_ensure_schema();
    process_hold_queue();
}

/** Expire uncollected holds and hand free copies to the next in line (safe to call any time). */
function process_hold_queue(): void
{

    foreach (q_all("SELECT h.id, h.book_id, b.title, u.name FROM holds h JOIN books b ON b.id = h.book_id JOIN users u ON u.id = h.user_id
                    WHERE h.status = 'ready' AND h.expires_at < ?", [today()]) as $h) {
        q("UPDATE holds SET status = 'expired' WHERE id = ? AND status = 'ready'", [$h['id']]);
        log_activity('hold.expired', "{$h['name']} didn't collect “{$h['title']}” in time");
    }

    $books = q_all("SELECT DISTINCT book_id FROM holds WHERE status = 'waiting'");
    foreach ($books as $row) {
        $bookId = (int)$row['book_id'];
        $free = (int)q_val('SELECT copies FROM books WHERE id = ?', [$bookId])
            - (int)q_val('SELECT COUNT(*) FROM loans WHERE book_id = ? AND status IN ' . ACTIVE_LOAN, [$bookId])
            - (int)q_val("SELECT COUNT(*) FROM holds WHERE book_id = ? AND status = 'ready'", [$bookId]);
        if ($free <= 0) continue;
        $next = q_all("SELECT h.id, h.user_id, b.title, u.name FROM holds h JOIN books b ON b.id = h.book_id JOIN users u ON u.id = h.user_id
                       WHERE h.book_id = ? AND h.status = 'waiting' ORDER BY h.created_at, h.id LIMIT " . $free, [$bookId]);
        foreach ($next as $h) {
            $until = date('Y-m-d', strtotime('+' . hold_pickup_days() . ' days'));
            q("UPDATE holds SET status = 'ready', ready_at = ?, expires_at = ? WHERE id = ? AND status = 'waiting'",
                [date('Y-m-d H:i:s'), $until, $h['id']]);
            log_activity('hold.ready', "A copy of “{$h['title']}” is being kept for {$h['name']} until " . fmt_date($until, 'j M'));
            if (function_exists('notify_hold_ready')) notify_hold_ready((int)$h['id']);
        }
    }
}

function hold_pickup_days(): int
{
    return max(1, (int)config('library.hold_pickup_days', 3));
}

function max_holds(): int
{
    return max(1, (int)config('library.max_holds', 3));
}

/** The member's open hold on a book (waiting or ready), or null. */
function member_hold(int $userId, int $bookId): ?array
{
    return q_one('SELECT * FROM holds WHERE user_id = ? AND book_id = ? AND status IN ' . HOLD_OPEN, [$userId, $bookId]);
}

/** 1-based place in line for a waiting hold. */
function hold_position(array $hold): int
{
    return 1 + (int)q_val("SELECT COUNT(*) FROM holds WHERE book_id = ? AND status = 'waiting' AND (created_at < ? OR (created_at = ? AND id < ?))",
        [$hold['book_id'], $hold['created_at'], $hold['created_at'], $hold['id']]);
}

/** How many members are waiting for a book. */
function hold_queue_length(int $bookId): int
{
    return (int)q_val("SELECT COUNT(*) FROM holds WHERE book_id = ? AND status = 'waiting'", [$bookId]);
}

function place_hold(int $userId, int $bookId): int
{
    return transaction(function () use ($userId, $bookId) {
        $book = find_book($bookId);
        if (!$book) throw new DomainException('That book no longer exists.');
        $member = q_one('SELECT id, name, role, is_active FROM users WHERE id = ?', [$userId]);
        if (!$member || $member['role'] !== 'member' || !(int)$member['is_active']) throw new DomainException('Only members can place holds.');
        if ((int)$book['available'] > 0) throw new DomainException('A copy is on the shelf right now, so you can borrow it instead.');
        if (q_val('SELECT COUNT(*) FROM loans WHERE user_id = ? AND book_id = ? AND status IN ' . ACTIVE_LOAN, [$userId, $bookId])) {
            throw new DomainException('You already have this book.');
        }
        if (member_hold($userId, $bookId)) throw new DomainException('You\'re already in line for this book.');
        $open = (int)q_val('SELECT COUNT(*) FROM holds WHERE user_id = ? AND status IN ' . HOLD_OPEN, [$userId]);
        if ($open >= max_holds()) throw new DomainException('You can have up to ' . max_holds() . ' holds at a time. Cancel one to place another.');

        q("INSERT INTO holds (user_id, book_id, status, created_at) VALUES (?, ?, 'waiting', ?)", [$userId, $bookId, date('Y-m-d H:i:s')]);
        $id = (int)db()->lastInsertId();
        log_activity('hold.placed', "{$member['name']} joined the line for “{$book['title']}”");
        return $id;
    });
}

/** Cancel a hold. Members can cancel their own; librarians pass $userId = null. */
function cancel_hold(int $holdId, ?int $userId): void
{
    $hold = q_one('SELECT h.*, b.title, u.name FROM holds h JOIN books b ON b.id = h.book_id JOIN users u ON u.id = h.user_id WHERE h.id = ?', [$holdId]);
    if (!$hold || ($userId !== null && (int)$hold['user_id'] !== $userId)) throw new DomainException('Hold not found.');
    if (!in_array($hold['status'], ['waiting', 'ready'], true)) throw new DomainException('This hold is already closed.');
    q("UPDATE holds SET status = 'cancelled' WHERE id = ?", [$holdId]);
    log_activity('hold.cancelled', ($userId === null ? 'Librarian cancelled ' . $hold['name'] . '\'s' : $hold['name'] . ' cancelled their') . " hold on “{$hold['title']}”");
}

/** Open holds for a member: ready ones first (with pick-up date), then the line they're in. */
function member_holds(int $userId): array
{
    $rows = q_all("SELECT h.*, b.title, b.author, b.cover FROM holds h JOIN books b ON b.id = h.book_id
                   WHERE h.user_id = ? AND h.status IN " . HOLD_OPEN . " ORDER BY (h.status = 'ready') DESC, h.created_at", [$userId]);
    foreach ($rows as &$r) $r['position'] = $r['status'] === 'waiting' ? hold_position($r) : 0;
    return $rows;
}

function ready_hold_count(int $userId): int
{
    return (int)q_val("SELECT COUNT(*) FROM holds WHERE user_id = ? AND status = 'ready'", [$userId]);
}
