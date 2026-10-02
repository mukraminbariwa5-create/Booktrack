<?php
/**
 * Chat endpoint.
 *   GET  ?member_id=&after=   → new messages after an id (also marks the other side's as read)
 *   POST member_id, body      → send a message
 * Members can only use their own conversation; librarians can use any member's.
 * Returns JSON for fetch() calls; plain form posts are redirected back.
 */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$isAdmin = $user['role'] === 'admin';
$side = $isAdmin ? 'library' : 'member';
$memberId = $isAdmin ? input_int('member_id') : (int)$user['id'];

$back = $isAdmin ? url('admin/messages.php', ['member' => $memberId]) : url('messages.php');

/** Id of the newest message the viewer sent that the other side has read. */
$seenId = function () use ($memberId, $side): int {
    return (int)q_val('SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE member_id = ? AND sender = ? AND read_at IS NOT NULL', [$memberId, $side]);
};

if (is_post()) {
    verify_csrf();
    try {
        $msg = chat_send($memberId, $side, (int)$user['id'], (string)($_POST['body'] ?? ''));
    } catch (DomainException $ex) {
        if (wants_json()) json_out(['ok' => false, 'error' => $ex->getMessage()], 422);
        flash('error', $ex->getMessage());
        header('Location: ' . $back);
        exit;
    }
    if (wants_json()) json_out(['ok' => true, 'message' => chat_public($msg), 'seen_id' => $seenId()]);
    header('Location: ' . $back);
    exit;
}

if ($isAdmin && !q_val("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'member'", [$memberId])) {
    json_out(['ok' => false, 'error' => 'Conversation not found.'], 404);
}

$rows = chat_thread($memberId, input_int('after'));
chat_mark_read($memberId, $side);
json_out([
    'ok' => true,
    'messages' => array_map('chat_public', $rows),
    'seen_id' => $seenId(),
    'unread' => $isAdmin ? chat_unread_conversations() : chat_unread_for_member($memberId),
]);
