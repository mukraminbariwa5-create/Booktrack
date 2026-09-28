<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (is_post()) {
    verify_csrf();
    $msg = q_one('SELECT m.*, u.name AS member_name FROM messages m JOIN users u ON u.id = m.user_id WHERE m.id = ?', [input_int('id')]);
    if (!$msg) {
        flash('error', 'That message no longer exists.');
        back('admin/messages.php');
    }
    switch (input('action')) {
        case 'reply':
            $reply = input('reply');
            if ($reply === '') {
                flash('error', 'Write a reply before sending.');
                break;
            }
            q('UPDATE messages SET reply = ?, replied_at = ?, is_read = 1, reply_seen = 0 WHERE id = ?', [mb_substr($reply, 0, 4000), date('Y-m-d H:i:s'), $msg['id']]);
            log_activity('message.replied', $msg['subject']);
            flash('success', 'Reply sent to ' . $msg['member_name'] . '.');
            break;
        case 'read':
            q('UPDATE messages SET is_read = 1 - is_read WHERE id = ?', [$msg['id']]);
            break;
        case 'delete':
            q('DELETE FROM messages WHERE id = ?', [$msg['id']]);
            log_activity('message.deleted', $msg['subject']);
            flash('success', 'Message deleted.');
            break;
    }
    back('admin/messages.php');
}

$tabs = ['unread' => 'Unread', 'unanswered' => 'Awaiting reply', 'all' => 'All messages'];
$conds = ['unread' => 'm.is_read = 0', 'unanswered' => 'm.reply IS NULL', 'all' => '1 = 1'];
$tab = array_key_exists(input('tab'), $tabs) ? input('tab') : ((int)q_val('SELECT COUNT(*) FROM messages WHERE is_read = 0') ? 'unread' : 'all');
$counts = [];
foreach ($conds as $k => $c) $counts[$k] = (int)q_val("SELECT COUNT(*) FROM messages m WHERE $c");

$p = paginate($counts[$tab], 10);
$messages = q_all("SELECT m.*, u.name AS member_name, u.username FROM messages m JOIN users u ON u.id = m.user_id
                   WHERE {$conds[$tab]} ORDER BY m.created_at DESC LIMIT {$p['per']} OFFSET {$p['offset']}");

$title = 'Messages';
$active = 'messages';
require __DIR__ . '/../includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Messages</h1>
    <p>Questions, book requests and problem reports from members.</p>
  </div>
</header>

<section class="card card--flush">
  <nav class="tabs" style="padding:0 12px" aria-label="Message filters">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="<?= e(url('admin/messages.php', ['tab' => $k])) ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($messages): ?>
    <?php foreach ($messages as $m): ?>
      <article class="message-item <?= (int)$m['is_read'] ? '' : 'is-unread' ?>">
        <span class="avatar"><?= e(initials($m['member_name'])) ?></span>
        <div class="message-item__body">
          <div class="row row--wrap" style="gap:8px">
            <span class="message-item__subject"><?= e($m['subject']) ?></span>
            <span class="spacer"></span>
            <span class="small muted"><?= e($m['member_name']) ?> · <?= e(time_ago($m['created_at'])) ?></span>
          </div>
          <div class="thread">
            <div class="bubble"><p><?= nl2br(e($m['body'])) ?></p></div>
            <?php if ($m['reply'] !== null): ?>
              <div class="bubble bubble--reply">
                <div class="bubble__meta"><strong>Your reply</strong><span>·</span><span><?= e(time_ago($m['replied_at'])) ?></span><?= (int)$m['reply_seen'] ? '<span>· Seen</span>' : '' ?></div>
                <p><?= nl2br(e($m['reply'])) ?></p>
              </div>
            <?php endif; ?>
          </div>
          <details <?= $m['reply'] === null && !(int)$m['is_read'] ? 'open' : '' ?>>
            <summary class="small link" style="cursor:pointer;width:max-content"><?= $m['reply'] === null ? 'Reply' : 'Edit reply' ?></summary>
            <form method="post" class="stack" style="gap:10px;margin-top:10px;max-width:640px">
              <?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <label class="sr-only" for="reply-<?= (int)$m['id'] ?>">Reply to <?= e($m['member_name']) ?></label>
              <textarea class="textarea" id="reply-<?= (int)$m['id'] ?>" name="reply" rows="3" maxlength="4000" required placeholder="Write a reply to <?= e($m['member_name']) ?>…"><?= e($m['reply'] ?? '') ?></textarea>
              <div><button class="btn btn--sm" type="submit"><?= icon('send') ?> Send reply</button></div>
            </form>
          </details>
          <div class="row" style="gap:6px">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn--ghost btn--sm" type="submit"><?= (int)$m['is_read'] ? 'Mark as unread' : 'Mark as read' ?></button></form>
            <form method="post" data-confirm="This message and any reply will be permanently deleted." data-confirm-title="Delete message?" data-confirm-ok="Delete">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn--ghost btn--sm" type="submit"><?= icon('trash') ?> Delete</button></form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
    <?= pagination_links($p) ?>
  <?php else: ?>
    <div class="empty"><?= icon('inbox') ?><h3><?= $tab === 'all' ? 'No messages yet' : 'Inbox zero' ?></h3><p><?= $tab === 'all' ? 'When members send questions or requests, they\'ll appear here.' : 'Nothing waiting in this view.' ?></p></div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
