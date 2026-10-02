<?php
/**
 * Upgrade test: an install made before chat existed (old "messages" table,
 * no chat_messages) must get its messages and replies carried into chats.
 *   php tests/chat_migration_test.php
 */
$db = sys_get_temp_dir() . '/booktrack_chat_migration.sqlite';
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/make_sqlite.php') . ' ' . escapeshellarg($db) . ' > ' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));

// Turn the fresh database back into an "old" one.
$pdo = new PDO('sqlite:' . $db);
$pdo->exec('DROP TABLE chat_messages');
$pdo->exec("CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL,
            reply TEXT, replied_at TEXT, is_read INTEGER NOT NULL DEFAULT 0, reply_seen INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)");
$pdo->exec("INSERT INTO messages (user_id, subject, body, reply, replied_at, is_read, reply_seen, created_at) VALUES
  (2, 'More copies please', 'Atomic Habits is always out.', 'Ordered one more!', '2026-09-20 10:00:00', 1, 1, '2026-09-19 09:00:00'),
  (3, 'Torn pages', 'Pages 40-42 are torn.', NULL, NULL, 0, 0, '2026-09-25 08:00:00')");
$pdo = null;

putenv('BOOKTRACK_SQLITE=' . $db);
require __DIR__ . '/../includes/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✔ ' : '  ✘ ') . $name . "\n"; }

echo "Chat upgrade from the old messages table\n";
$alice = chat_thread(2);
check('Alice: question and reply both carried over', count($alice) === 2);
check('Question keeps its subject as the first line', str_starts_with($alice[0]['body'], "More copies please\nAtomic Habits"));
check('Reply is from the library, at its original time', $alice[1]['sender'] === 'library' && $alice[1]['created_at'] === '2026-09-20 10:00:00');
check('Reply already seen stays read', $alice[1]['read_at'] !== null && chat_unread_for_member(2) === 0);
$brian = chat_thread(3);
check('Unanswered message carried over alone', count($brian) === 1 && $brian[0]['sender'] === 'member');
check('Unread message still counts as waiting', chat_unread_conversations() === 1);
chat_ensure_schema();
check('Running again does not duplicate anything', (int)q_val('SELECT COUNT(*) FROM chat_messages') === 3);
check('Old table is kept as a backup', (int)q_val('SELECT COUNT(*) FROM messages') === 2);

echo "\n$pass passed, $fail failed\n";
@unlink($db);
exit($fail ? 1 : 0);
