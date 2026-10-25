<?php
/**
 * Library rules: availability, borrowing, renewals and returns.
 * All circulation changes go through these functions so the rules live in one place.
 *
 * Availability is always calculated from the loans table
 * (copies minus active loans), so book status can never drift out of sync.
 */

const ACTIVE_LOAN = "('borrowed','return_requested')";

/**
 * SELECT … FROM clause for books with an `available` column.
 * A copy is unavailable while it's on loan or kept for a member whose hold is ready.
 */
/** Circulation's loan filters: key => [label, SQL condition on loans l ('?' is today's date)]. */
function loan_filter_tabs(): array
{
    return [
        'active'   => ['On loan', "l.status IN ('borrowed','return_requested')"],
        'requests' => ['Return requests', "l.status = 'return_requested'"],
        'overdue'  => ['Overdue', "l.status = 'borrowed' AND l.due_at < ?"],
        'history'  => ['Returned', "l.status = 'returned'"],
    ];
}

function books_from(): string
{
    return "books b LEFT JOIN (SELECT book_id, COUNT(*) AS active FROM ("
        . "SELECT book_id FROM loans WHERE status IN " . ACTIVE_LOAN
        . " UNION ALL SELECT book_id FROM holds WHERE status = 'ready'"
        . ") x GROUP BY book_id) al ON al.book_id = b.id"
        . " LEFT JOIN (SELECT book_id, AVG(rating) AS avg_rating, COUNT(*) AS n_reviews FROM reviews GROUP BY book_id) rv ON rv.book_id = b.id";
}

function book_columns(): string
{
    return 'b.id, b.title, b.author, b.category, b.isbn, b.published_year, b.description, b.cover, b.pdf, b.copies, b.created_at, '
        . '(b.copies - COALESCE(al.active, 0)) AS available, COALESCE(rv.avg_rating, 0) AS rating, COALESCE(rv.n_reviews, 0) AS reviews';
}

function find_book(int $id): ?array
{
    return q_one('SELECT ' . book_columns() . ' FROM ' . books_from() . ' WHERE b.id = ?', [$id]);
}

function categories(): array
{
    return array_column(q_all("SELECT category, COUNT(*) AS n FROM books WHERE category IS NOT NULL AND category <> '' GROUP BY category ORDER BY category"), 'n', 'category');
}

function active_loan_count(int $userId): int
{
    return (int)q_val('SELECT COUNT(*) FROM loans WHERE user_id = ? AND status IN ' . ACTIVE_LOAN, [$userId]);
}

function has_overdue(int $userId): bool
{
    return (bool)q_val("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'borrowed' AND due_at < ?", [$userId, today()]);
}

/**
 * Why a member can't borrow right now, or null when they can.
 * Returns ['code' => overdue|limit, 'message' => readable text, 'short' => button label].
 * Pages use this to explain the block up front instead of showing a Borrow
 * button that only fails after it's clicked.
 */
function borrow_block_reason(int $userId): ?array
{
    $overdue = (int)q_val("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'borrowed' AND due_at < ?", [$userId, today()]);
    if ($overdue) {
        return ['code' => 'overdue', 'short' => 'Return overdue books first',
            'message' => 'You have ' . plural($overdue, 'overdue book') . '. Return ' . ($overdue === 1 ? 'it' : 'them')
                . ' before borrowing another.'];
    }
    $blockAt = (float)config('fines.block_at', 0);
    if (fines_enabled() && $blockAt > 0 && ($owed = unpaid_fines_total($userId)) >= $blockAt) {
        return ['code' => 'fines', 'short' => 'Pay fines first',
            'message' => 'You have ' . money($owed) . ' in unpaid late fees. Pay at the library desk to borrow again.'];
    }
    $max = (int)config('library.max_active_loans');
    if (active_loan_count($userId) >= $max) {
        return ['code' => 'limit', 'short' => 'Loan limit reached',
            'message' => "You already have $max books on loan, the most you can hold at once. Return one to borrow this."];
    }
    return null;
}

/** Display status for a loan row: borrowed | overdue | return_requested | returned */
function loan_state(array $loan): string
{
    if ($loan['status'] === 'borrowed' && $loan['due_at'] < today()) return 'overdue';
    return $loan['status'];
}

function loan_badge(array $loan): string
{
    $state = loan_state($loan);
    $labels = [
        'borrowed' => ['On loan', 'badge--info'],
        'overdue' => ['Overdue', 'badge--danger'],
        'return_requested' => ['Return requested', 'badge--warn'],
        'returned' => ['Returned', 'badge--muted'],
    ];
    [$label, $cls] = $labels[$state] ?? [$state, 'badge--muted'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/**
 * Create a loan. Used by members (self-service) and admins (issue at the desk).
 * Throws DomainException with a readable message when a rule is broken.
 */
function create_loan(int $userId, int $bookId, ?string $dueAt = null, bool $enforceLimits = true): int
{
    return transaction(function () use ($userId, $bookId, $dueAt, $enforceLimits) {
        $book = q_one('SELECT id, title, copies FROM books WHERE id = ?' . for_update(), [$bookId]);
        if (!$book) throw new DomainException('That book no longer exists.');

        $member = q_one("SELECT id, name, role, is_active FROM users WHERE id = ?", [$userId]);
        if (!$member || $member['role'] !== 'member' || !(int)$member['is_active']) {
            throw new DomainException('Loans can only be made for active members.');
        }

        $active = (int)q_val('SELECT COUNT(*) FROM loans WHERE book_id = ? AND status IN ' . ACTIVE_LOAN, [$bookId]);
        // copies kept for other members whose holds are ready
        $reserved = (int)q_val("SELECT COUNT(*) FROM holds WHERE book_id = ? AND status = 'ready' AND user_id <> ?", [$bookId, $userId]);
        if ($active >= (int)$book['copies']) throw new DomainException('All copies of “' . $book['title'] . '” are currently on loan.');
        if ($active + $reserved >= (int)$book['copies']) throw new DomainException('The free copy of “' . $book['title'] . '” is being kept for a member who placed a hold.');

        $already = q_val('SELECT COUNT(*) FROM loans WHERE book_id = ? AND user_id = ? AND status IN ' . ACTIVE_LOAN, [$bookId, $userId]);
        if ($already) throw new DomainException('This member already has a copy of “' . $book['title'] . '”.');

        if ($enforceLimits && ($reason = borrow_block_reason($userId))) {
            throw new DomainException($reason['message']);
        }

        $borrowed = today();
        $due = $dueAt ?: date('Y-m-d', strtotime('+' . (int)config('library.loan_days') . ' days'));
        if ($due < $borrowed) throw new DomainException('The due date can\'t be in the past.');

        q("INSERT INTO loans (user_id, book_id, borrowed_at, due_at, status) VALUES (?, ?, ?, ?, 'borrowed')",
            [$userId, $bookId, $borrowed, $due]);
        $id = (int)db()->lastInsertId();
        // borrowing the book closes the member's own hold on it
        q("UPDATE holds SET status = 'fulfilled' WHERE user_id = ? AND book_id = ? AND status IN " . HOLD_OPEN, [$userId, $bookId]);
        log_activity('loan.created', "{$member['name']} borrowed “{$book['title']}”, due " . fmt_date($due, 'j M'));
        return $id;
    });
}

/** Google Calendar "add event" link for a loan's due date (all-day). */
function google_calendar_url(array $loan): string
{
    $due = str_replace('-', '', $loan['due_at']);
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action'  => 'TEMPLATE',
        'text'    => 'Return “' . $loan['title'] . '” to the library',
        'dates'   => $due . '/' . date('Ymd', strtotime($loan['due_at'] . ' +1 day')),
        'details' => '“' . $loan['title'] . '” is due back today. Manage your loans: ' . absolute_url('loans.php'),
    ]);
}

/** A member's library card number, e.g. BT-000002 (printed on the card and in its QR code). */
function card_number(int $userId): string
{
    return 'BT-' . str_pad((string)$userId, 6, '0', STR_PAD_LEFT);
}

/** The member a scanned or typed card number belongs to ("BT-000002", "bt000002" or "2"). */
function member_by_card(string $input): ?array
{
    if (!preg_match('/^\s*(?:BT[-\s]?)?0*(\d{1,9})\s*$/i', $input, $m)) return null;
    return q_one("SELECT id, name, username, email, phone, is_active, created_at FROM users WHERE id = ? AND role = 'member'", [(int)$m[1]]);
}

function find_loan(int $id): ?array
{
    return q_one('SELECT l.*, b.title, b.author, b.cover, u.name AS member_name, u.username
                  FROM loans l JOIN books b ON b.id = l.book_id JOIN users u ON u.id = l.user_id WHERE l.id = ?', [$id]);
}

/** Member asks to return a book; the librarian confirms when it's back on the shelf. */
function request_return(int $loanId, int $userId): void
{
    $loan = find_loan($loanId);
    if (!$loan || (int)$loan['user_id'] !== $userId) throw new DomainException('Loan not found.');
    if ($loan['status'] !== 'borrowed') throw new DomainException('A return has already been requested for this book.');
    q("UPDATE loans SET status = 'return_requested' WHERE id = ?", [$loanId]);
    log_activity('loan.return_requested', "{$loan['member_name']} asked to return “{$loan['title']}”");
}

/** Take back a return request (the Undo after "Return"), as long as it hasn't been checked in. */
function cancel_return(int $loanId, int $userId): void
{
    $loan = find_loan($loanId);
    if (!$loan || (int)$loan['user_id'] !== $userId) throw new DomainException('Loan not found.');
    if ($loan['status'] !== 'return_requested') throw new DomainException('The librarian has already checked this book in.');
    // Requesting a return lifts the overdue block, so keeping an overdue book
    // after all would let a member borrow more while still holding it.
    if ($loan['due_at'] < today()) throw new DomainException('This book is overdue, so the return can\'t be cancelled. Please bring it to the desk.');
    q("UPDATE loans SET status = 'borrowed' WHERE id = ?", [$loanId]);
    log_activity('loan.return_cancelled', "{$loan['member_name']} kept “{$loan['title']}” after all");
}

/**
 * What needs attention, for the reminders bell. Each item:
 * ['tone' => late|soon|info, 'icon', 'title', 'text', 'url'].
 */
function reminders_for(array $user): array
{
    $items = [];
    $soonDays = (int)config('library.due_soon_days');
    if ($user['role'] === 'admin') {
        $requests = q_all("SELECT l.id, b.title, u.name FROM loans l JOIN books b ON b.id = l.book_id JOIN users u ON u.id = l.user_id
                           WHERE l.status = 'return_requested' ORDER BY l.id DESC LIMIT 5");
        foreach ($requests as $r) {
            $items[] = ['tone' => 'info', 'icon' => 'swap', 'title' => 'Ready to check in',
                'text' => $r['name'] . ' is returning “' . $r['title'] . '”', 'url' => url('admin/loans.php', ['tab' => 'requests'])];
        }
        $overdue = (int)q_val("SELECT COUNT(*) FROM loans WHERE status = 'borrowed' AND due_at < ?", [today()]);
        if ($overdue) $items[] = ['tone' => 'late', 'icon' => 'clock', 'title' => plural($overdue, 'overdue book'),
            'text' => 'Follow up with the members who have them', 'url' => url('admin/loans.php', ['tab' => 'overdue'])];
        $waiting = chat_unread_conversations();
        if ($waiting) $items[] = ['tone' => 'info', 'icon' => 'message', 'title' => plural($waiting, 'member') . ' waiting for a reply',
            'text' => 'Open the inbox to answer', 'url' => url('admin/messages.php')];
        return $items;
    }

    foreach (member_active_loans((int)$user['id']) as $l) {
        if ($l['status'] !== 'borrowed') continue;
        $d = days_until($l['due_at']);
        if ($d < 0) {
            $items[] = ['tone' => 'late', 'icon' => 'clock', 'title' => 'Overdue by ' . plural(-$d, 'day'),
                'text' => '“' . $l['title'] . '” was due ' . fmt_date($l['due_at'], 'j M'), 'url' => url('loans.php')];
        } elseif ($d <= $soonDays) {
            $items[] = ['tone' => 'soon', 'icon' => 'book', 'title' => $d === 0 ? 'Due today' : 'Due in ' . plural($d, 'day'),
                'text' => '“' . $l['title'] . '”' . ((int)$l['renewals'] < 1 ? ', you can still renew it' : ''), 'url' => url('loans.php')];
        }
    }
    foreach (q_all("SELECT h.expires_at, b.title FROM holds h JOIN books b ON b.id = h.book_id WHERE h.user_id = ? AND h.status = 'ready' ORDER BY h.expires_at", [(int)$user['id']]) as $h) {
        $items[] = ['tone' => 'soon', 'icon' => 'book', 'title' => 'Your hold is ready',
            'text' => '“' . $h['title'] . '” is kept for you until ' . fmt_date($h['expires_at'], 'j M'), 'url' => url('loans.php')];
    }
    if (fines_enabled() && ($owed = unpaid_fines_total((int)$user['id'])) > 0) {
        $items[] = ['tone' => 'late', 'icon' => 'alert', 'title' => money($owed) . ' in late fees',
            'text' => 'Pay at the library desk', 'url' => url('loans.php') . '#fines'];
    }
    $unread = unread_replies((int)$user['id']);
    if ($unread) $items[] = ['tone' => 'info', 'icon' => 'message', 'title' => plural($unread, 'new message'),
        'text' => 'The librarian replied to you', 'url' => url('messages.php')];
    return $items;
}

/** Members can renew once, as long as the loan isn't overdue. */
function renew_loan(int $loanId, int $userId): string
{
    $loan = find_loan($loanId);
    if (!$loan || (int)$loan['user_id'] !== $userId) throw new DomainException('Loan not found.');
    if ($loan['status'] !== 'borrowed') throw new DomainException('Only active loans can be renewed.');
    if ($loan['due_at'] < today()) throw new DomainException('Overdue loans can\'t be renewed. Please return the book.');
    if ((int)$loan['renewals'] >= 1) throw new DomainException('This loan has already been renewed once.');
    $due = date('Y-m-d', strtotime($loan['due_at'] . ' +7 days'));
    q('UPDATE loans SET due_at = ?, renewals = renewals + 1 WHERE id = ?', [$due, $loanId]);
    log_activity('loan.renewed', "{$loan['member_name']} renewed “{$loan['title']}”, now due " . fmt_date($due, 'j M'));
    return $due;
}

/**
 * Librarian marks a loan as returned (book back on the shelf).
 * Returns the late fee charged for it (0 when it came back on time).
 */
function complete_return(int $loanId): float
{
    $fine = transaction(function () use ($loanId) {
        $loan = find_loan($loanId);
        if (!$loan) throw new DomainException('Loan not found.');
        if ($loan['status'] === 'returned') throw new DomainException('This book was already returned.');
        $claimed = q("UPDATE loans SET status = 'returned', returned_at = ? WHERE id = ? AND status <> 'returned'", [today(), $loanId])->rowCount();
        if (!$claimed) throw new DomainException('This book was already returned.');
        log_activity('loan.returned', "“{$loan['title']}” checked in from {$loan['member_name']}");
        return charge_fine($loan, today());
    });
    process_hold_queue(); // the copy goes straight to the next member in line, if any
    return $fine;
}

function is_favorite(int $userId, int $bookId): bool
{
    return (bool)q_val('SELECT COUNT(*) FROM favorites WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);
}

function toggle_favorite(int $userId, int $bookId): bool
{
    if (is_favorite($userId, $bookId)) {
        q('DELETE FROM favorites WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);
        return false;
    }
    if (!q_val('SELECT COUNT(*) FROM books WHERE id = ?', [$bookId])) throw new DomainException('Book not found.');
    q('INSERT INTO favorites (user_id, book_id) VALUES (?, ?)', [$userId, $bookId]);
    return true;
}

function member_active_loans(int $userId): array
{
    return q_all('SELECT l.*, b.title, b.author, b.cover FROM loans l JOIN books b ON b.id = l.book_id
                  WHERE l.user_id = ? AND l.status IN ' . ACTIVE_LOAN . ' ORDER BY l.due_at', [$userId]);
}

function unread_replies(int $userId): int
{
    return chat_unread_for_member($userId);
}
