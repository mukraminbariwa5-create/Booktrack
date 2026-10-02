<?php
/**
 * Builds a SQLite copy of the database for the automated tests.
 * Usage: php tests/make_sqlite.php /path/to/test.sqlite
 * Table definitions mirror database/booktrack.sql; the demo INSERTs are read
 * from that file and their MySQL date functions translated.
 */
$path = $argv[1] ?? __DIR__ . '/test.sqlite';
@unlink($path);
$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(<<<SQL
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, username TEXT NOT NULL UNIQUE, email TEXT, phone TEXT,
  password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin','member')), is_active INTEGER NOT NULL DEFAULT 1,
  last_login_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE books (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, author TEXT NOT NULL, category TEXT, isbn TEXT, published_year INTEGER,
  description TEXT, cover TEXT, pdf TEXT, copies INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE loans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE, borrowed_at TEXT NOT NULL, due_at TEXT NOT NULL, returned_at TEXT,
  renewals INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'borrowed' CHECK (status IN ('borrowed','return_requested','returned')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE favorites (user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, book_id));
CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  sender TEXT NOT NULL CHECK (sender IN ('member','library')), sender_user_id INTEGER, body TEXT NOT NULL, created_at TEXT NOT NULL, read_at TEXT);
CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor TEXT NOT NULL, action TEXT NOT NULL, details TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE login_attempts (username TEXT NOT NULL, ip TEXT NOT NULL, attempted_at INTEGER NOT NULL);
SQL);

$sql = file_get_contents(__DIR__ . '/../database/booktrack.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$map = [
    '/DATE_SUB\(CURDATE\(\), INTERVAL (\d+) DAY\)/' => "date('now','localtime','-$1 days')",
    '/DATE_ADD\(CURDATE\(\), INTERVAL (\d+) DAY\)/' => "date('now','localtime','+$1 days')",
    '/DATE_SUB\(NOW\(\), INTERVAL (\d+) DAY\)/'     => "datetime('now','localtime','-$1 days')",
    '/DATE_SUB\(NOW\(\), INTERVAL (\d+) HOUR\)/'    => "datetime('now','localtime','-$1 hours')",
    '/DATE_SUB\(NOW\(\), INTERVAL (\d+) MINUTE\)/'  => "datetime('now','localtime','-$1 minutes')",
];
foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
    $stmt = trim($stmt);
    if (!str_starts_with($stmt, 'INSERT')) continue;
    $stmt = preg_replace(array_keys($map), array_values($map), $stmt);
    $pdo->exec($stmt);
}
echo "SQLite test database ready: $path\n";
