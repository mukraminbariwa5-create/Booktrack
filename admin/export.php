<?php
/**
 * CSV downloads for librarians: loans (any Circulation tab), late fees, members and books.
 * Uses the same filters as the page the button sits on, so "Export" gives exactly
 * what's on screen, without the page limit.
 */
require __DIR__ . '/../includes/bootstrap.php';
require_role('admin');

$type = input('type');
$search = input('q');
$like = "%$search%";
$today = today();

if ($type === 'loans') {
    $tabs = loan_filter_tabs();
    $tab = array_key_exists(input('tab'), $tabs) ? input('tab') : 'active';
    [$label, $cond] = $tabs[$tab];
    $where = [$cond];
    $params = str_contains($cond, '?') ? [$today] : [];
    if ($search !== '') {
        $where[] = '(b.title LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $rows = q_all('SELECT b.title, b.author, b.isbn, u.name AS member, u.username, u.email, l.borrowed_at, l.due_at, l.returned_at, l.renewals, l.status
                   FROM loans l JOIN books b ON b.id = l.book_id JOIN users u ON u.id = l.user_id
                   WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . ($tab === 'history' ? 'l.returned_at DESC' : 'l.due_at ASC'), $params);
    $head = ['Book', 'Author', 'ISBN', 'Member', 'Username', 'Email', 'Borrowed', 'Due', 'Returned', 'Renewed', 'Status', 'Days overdue'];
    $out = array_map(function ($r) use ($today) {
        $late = $r['status'] !== 'returned' && $r['due_at'] < $today ? -days_until($r['due_at']) : 0;
        $status = ['borrowed' => $late ? 'Overdue' : 'On loan', 'return_requested' => 'Return requested', 'returned' => 'Returned'][$r['status']] ?? $r['status'];
        return [$r['title'], $r['author'], $r['isbn'], $r['member'], $r['username'], $r['email'], substr((string)$r['borrowed_at'], 0, 10),
                $r['due_at'], $r['returned_at'] ? substr((string)$r['returned_at'], 0, 10) : '', (int)$r['renewals'] ? 'Yes' : 'No', $status, $late ?: ''];
    }, $rows);
    $name = 'loans-' . strtolower(str_replace(' ', '-', $label));
} elseif ($type === 'fines') {
    $where = $search !== '' ? ' WHERE (f.book_title LIKE ? OR u.name LIKE ? OR u.username LIKE ?)' : '';
    $rows = q_all("SELECT f.*, u.name AS member, u.username, l.due_at, l.returned_at, s.username AS settled_by_name
                   FROM fines f JOIN users u ON u.id = f.user_id LEFT JOIN loans l ON l.id = f.loan_id LEFT JOIN users s ON s.id = f.settled_by
                   $where ORDER BY f.created_at DESC, f.id DESC", $search !== '' ? [$like, $like, $like] : []);
    $head = ['Book', 'Member', 'Username', 'Card number', 'Due', 'Checked in', 'Days late', 'Amount', 'Status', 'Settled', 'Settled by', 'Note'];
    $out = array_map(fn($r) => [
        $r['book_title'], $r['member'], $r['username'], card_number((int)$r['user_id']), $r['due_at'], $r['returned_at'],
        (int)$r['days_late'], number_format((float)$r['amount'], 2, '.', ''), ucfirst($r['status']),
        $r['settled_at'] ? substr((string)$r['settled_at'], 0, 10) : '', $r['settled_by_name'], $r['note'],
    ], $rows);
    $name = 'late-fees';
} elseif ($type === 'members') {
    $where = ["u.role = 'member'"];
    $params = [$today];
    if ($search !== '') {
        $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (input('status') === 'active') $where[] = 'u.is_active = 1';
    if (input('status') === 'inactive') $where[] = 'u.is_active = 0';
    $rows = q_all("SELECT u.id, u.name, u.username, u.email, u.phone, u.is_active, u.created_at, u.last_login_at,
                (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.status IN " . ACTIVE_LOAN . ") AS active_loans,
                (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.status = 'borrowed' AND l.due_at < ?) AS overdue,
                (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id) AS total_loans
              FROM users u WHERE " . implode(' AND ', $where) . ' ORDER BY u.name', $params);
    $head = ['Card number', 'Name', 'Username', 'Email', 'Phone', 'Status', 'On loan', 'Overdue', 'Total loans', 'Joined', 'Last sign-in'];
    $out = array_map(fn($r) => [
        card_number((int)$r['id']), $r['name'], $r['username'], $r['email'], $r['phone'], (int)$r['is_active'] ? 'Active' : 'Deactivated',
        (int)$r['active_loans'], (int)$r['overdue'], (int)$r['total_loans'], substr((string)$r['created_at'], 0, 10),
        $r['last_login_at'] ? substr((string)$r['last_login_at'], 0, 16) : 'Never',
    ], $rows);
    $name = 'members';
} elseif ($type === 'books') {
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if (input('category') !== '') { $where[] = 'b.category = ?'; $params[] = input('category'); }
    $rows = q_all('SELECT ' . book_columns() . ', (SELECT COUNT(*) FROM loans lx WHERE lx.book_id = b.id) AS times FROM ' . books_from()
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY b.title', $params);
    $head = ['Title', 'Author', 'Category', 'ISBN', 'Published', 'Copies', 'Available', 'Times borrowed', 'Average rating', 'Reviews', 'Added'];
    $out = array_map(fn($r) => [
        $r['title'], $r['author'], $r['category'], $r['isbn'], $r['published_year'], (int)$r['copies'], max(0, (int)$r['available']),
        (int)$r['times'], (int)$r['reviews'] ? number_format((float)$r['rating'], 1) : '', (int)$r['reviews'], substr((string)$r['created_at'], 0, 10),
    ], $rows);
    $name = 'books';
} else {
    http_response_code(404);
    exit('Unknown export.');
}

log_activity('export.downloaded', ucfirst($name) . ' (' . plural(count($out), 'row') . ')');
csv_download('booktrack-' . $name . '-' . date('Y-m-d') . '.csv', $head, $out);
