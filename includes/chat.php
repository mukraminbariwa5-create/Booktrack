<?php
/**
 * Member ↔ library chat.
 *
 * Every member has one ongoing conversation with the library. Each row in
 * chat_messages belongs to that conversation (member_id) and was sent either
 * by the member or by a librarian (sender). read_at is set when the other
 * side opens the conversation, which powers unread badges and "Seen".
 */

const CHAT_MAX_LENGTH = 2000;

/**
 * Creates the chat table on installs made before it existed, and carries the
 * old one-question/one-reply "messages" over into conversations (once).
 */
function chat_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->query('SELECT 1 FROM chat_messages LIMIT 1');
        return;
    } catch (PDOException $e) {
        // table is missing: create it below
    }

    // Plain CREATE TABLE (no IF NOT EXISTS): if two requests race, only the one that
    // actually creates the table copies the old messages, so nothing is duplicated.
    try {
    if (db_is_mysql()) {
        db()->exec("CREATE TABLE chat_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NOT NULL,
            sender ENUM('member','library') NOT NULL,
            sender_user_id INT UNSIGNED NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            CONSTRAINT fk_chat_member FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_chat_member (member_id, id),
            INDEX idx_chat_unread (sender, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        db()->exec("CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, sender TEXT NOT NULL CHECK (sender IN ('member','library')),
            sender_user_id INTEGER, body TEXT NOT NULL, created_at TEXT NOT NULL, read_at TEXT)");
    }
    } catch (PDOException $e) {
        return; // another request created it a moment ago
    }

    // Carry over the old messages table, if this install has one.
    try {
        $old = q_all('SELECT * FROM messages ORDER BY created_at, id');
    } catch (PDOException $e) {
        $old = [];
    }
    $admin = q_val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    foreach ($old as $m) {
        $body = trim($m['subject']) !== '' ? trim($m['subject']) . "\n" . $m['body'] : $m['body'];
        q('INSERT INTO chat_messages (member_id, sender, sender_user_id, body, created_at, read_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$m['user_id'], 'member', $m['user_id'], $body, $m['created_at'], (int)$m['is_read'] ? $m['created_at'] : null]);
        if ($m['reply'] !== null && $m['reply'] !== '') {
            $at = $m['replied_at'] ?: $m['created_at'];
            q('INSERT INTO chat_messages (member_id, sender, sender_user_id, body, created_at, read_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$m['user_id'], 'library', $admin, $m['reply'], $at, (int)$m['reply_seen'] ? $at : null]);
        }
    }
}

/** Unread messages from the library, for a member's nav badge. */
function chat_unread_for_member(int $memberId): int
{
    chat_ensure_schema();
    return (int)q_val("SELECT COUNT(*) FROM chat_messages WHERE member_id = ? AND sender = 'library' AND read_at IS NULL", [$memberId]);
}

/** Conversations with unread member messages, for the librarian's nav badge. */
function chat_unread_conversations(): int
{
    chat_ensure_schema();
    return (int)q_val("SELECT COUNT(DISTINCT member_id) FROM chat_messages WHERE sender = 'member' AND read_at IS NULL");
}

/** Send a message. $sender is 'member' or 'library'. Returns the new row. */
function chat_send(int $memberId, string $sender, int $senderUserId, string $body): array
{
    chat_ensure_schema();
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '') throw new DomainException('Write a message first.');
    if (mb_strlen($body) > CHAT_MAX_LENGTH) throw new DomainException('Messages can be at most ' . number_format(CHAT_MAX_LENGTH) . ' characters.');
    $member = q_one("SELECT id, name FROM users WHERE id = ? AND role = 'member'", [$memberId]);
    if (!$member) throw new DomainException('That member could not be found.');

    q('INSERT INTO chat_messages (member_id, sender, sender_user_id, body, created_at) VALUES (?, ?, ?, ?, ?)',
        [$memberId, $sender, $senderUserId, $body, date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    $preview = mb_strimwidth(preg_replace('/\s+/', ' ', $body), 0, 80, '…');
    log_activity($sender === 'member' ? 'message.sent' : 'message.replied', ($sender === 'member' ? 'From ' : 'To ') . $member['name'] . ': ' . $preview);
    return q_one('SELECT * FROM chat_messages WHERE id = ?', [$id]);
}

/** Messages in a conversation, oldest first; only those after $afterId when given. */
function chat_thread(int $memberId, int $afterId = 0, int $limit = 300): array
{
    chat_ensure_schema();
    $rows = q_all('SELECT * FROM chat_messages WHERE member_id = ? AND id > ? ORDER BY id DESC LIMIT ' . (int)$limit, [$memberId, $afterId]);
    return array_reverse($rows);
}

/** The viewer opened the conversation: mark the other side's messages as read. */
function chat_mark_read(int $memberId, string $viewer): void
{
    $other = $viewer === 'member' ? 'library' : 'member';
    q('UPDATE chat_messages SET read_at = ? WHERE member_id = ? AND sender = ? AND read_at IS NULL', [date('Y-m-d H:i:s'), $memberId, $other]);
}

/** Librarian inbox: one row per member with a conversation, newest first. */
function chat_conversations(string $search = '', bool $unreadOnly = false): array
{
    chat_ensure_schema();
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(u.name LIKE ? OR u.username LIKE ?)';
        array_push($params, "%$search%", "%$search%");
    }
    if ($unreadOnly) $where[] = 'COALESCE(un.n, 0) > 0';
    $sql = "SELECT u.id, u.name, u.username, u.is_active, m.body, m.sender, m.created_at, COALESCE(un.n, 0) AS unread
            FROM users u
            JOIN (SELECT member_id, MAX(id) AS last_id FROM chat_messages GROUP BY member_id) lm ON lm.member_id = u.id
            JOIN chat_messages m ON m.id = lm.last_id
            LEFT JOIN (SELECT member_id, COUNT(*) AS n FROM chat_messages WHERE sender = 'member' AND read_at IS NULL GROUP BY member_id) un ON un.member_id = u.id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY m.id DESC';
    return q_all($sql, $params);
}

/** Shape a row for JSON (the browser renders it with textContent, never as HTML). */
function chat_public(array $m): array
{
    return [
        'id' => (int)$m['id'],
        'sender' => $m['sender'],
        'body' => $m['body'],
        'time' => fmt_date($m['created_at'], 'g:i A'),
        'day' => substr($m['created_at'], 0, 10),
        'ts' => strtotime($m['created_at']),
        'iso' => date('c', strtotime($m['created_at'])),
        'day_label' => chat_day_label($m['created_at']),
        'read' => $m['read_at'] !== null,
    ];
}

function chat_day_label(string $datetime): string
{
    $d = substr($datetime, 0, 10);
    if ($d === today()) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return fmt_date($datetime, (substr($d, 0, 4) === date('Y') ? 'l, j F' : 'j F Y'));
}

/** Short "time" for inbox rows: 2:14 PM today, Mon this week, 28 Sep otherwise. */
function chat_short_time(string $datetime): string
{
    $d = substr($datetime, 0, 10);
    if ($d === today()) return fmt_date($datetime, 'g:i A');
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    if (strtotime($d) > strtotime('-6 days')) return fmt_date($datetime, 'D');
    return fmt_date($datetime, 'j M');
}
