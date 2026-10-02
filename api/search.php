<?php
/** Instant search for the command palette: books for everyone, members too for librarians. */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$isAdmin = $user['role'] === 'admin';

$q = trim(input('q'));
if (mb_strlen($q) < 1) json_out(['ok' => true, 'books' => [], 'members' => []]);
$q = mb_substr($q, 0, 80);
$like = '%' . $q . '%';
$starts = $q . '%';

// Title matches first (prefix before substring), then author / category / ISBN.
$rows = q_all('SELECT ' . book_columns() . ' FROM ' . books_from()
    . ' WHERE b.title LIKE ? OR b.author LIKE ? OR b.category LIKE ? OR b.isbn LIKE ?'
    . ' ORDER BY CASE WHEN b.title LIKE ? THEN 0 WHEN b.title LIKE ? THEN 1 ELSE 2 END, b.title LIMIT 7',
    [$like, $like, $like, $like, $starts, $like]);

$books = array_map(function ($b) {
    $file = (string)($b['cover'] ?? '');
    $hasImg = $file !== '' && is_file(APP_ROOT . '/' . ltrim($file, '/'));
    return [
        'id'        => (int)$b['id'],
        'title'     => $b['title'],
        'author'    => $b['author'],
        'category'  => $b['category'],
        'url'       => url('book.php', ['id' => $b['id']]),
        'cover'     => $hasImg ? url($file) : null,
        'color'     => cover_color($b),
        'available' => (int)$b['available'],
        'copies'    => (int)$b['copies'],
    ];
}, $rows);

$members = [];
if ($isAdmin) {
    $members = array_map(fn($m) => [
        'name'     => $m['name'],
        'username' => $m['username'],
        'initials' => initials($m['name']),
        'url'      => url('admin/members.php', ['q' => $m['username']]),
        'active'   => (int)$m['is_active'] === 1,
    ], q_all("SELECT name, username, is_active FROM users WHERE role = 'member' AND (name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY name LIMIT 4", [$like, $like, $like]));
}

json_out([
    'ok'      => true,
    'books'   => $books,
    'members' => $members,
    'all'     => $isAdmin ? url('admin/books.php', ['q' => $q]) : url('discover.php', ['q' => $q]),
]);
