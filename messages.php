<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');
$uid = (int)$user['id'];

$errors = [];
$old = ['subject' => '', 'body' => ''];
if (is_post()) {
    verify_csrf();
    $old = ['subject' => input('subject'), 'body' => input('body')];
    if ($old['subject'] === '') $errors[] = 'Add a subject.';
    if (mb_strlen($old['subject']) > 160) $errors[] = 'Keep the subject under 160 characters.';
    if ($old['body'] === '') $errors[] = 'Write a message.';
    if (mb_strlen($old['body']) > 4000) $errors[] = 'Keep the message under 4,000 characters.';
    if (!$errors) {
        q('INSERT INTO messages (user_id, subject, body, created_at) VALUES (?, ?, ?, ?)', [$uid, $old['subject'], $old['body'], date('Y-m-d H:i:s')]);
        log_activity('message.sent', $old['subject']);
        flash('success', 'Message sent. The librarian will reply here.');
        redirect('messages.php');
    }
}

$messages = q_all('SELECT * FROM messages WHERE user_id = ? ORDER BY created_at DESC', [$uid]);
// Opening this page marks replies as seen (after we've rendered which ones were new).
$newReplyIds = array_column(array_filter($messages, fn($m) => $m['reply'] !== null && !(int)$m['reply_seen']), 'id');
if ($newReplyIds) q('UPDATE messages SET reply_seen = 1 WHERE user_id = ? AND reply IS NOT NULL', [$uid]);

$title = 'Messages';
$active = 'messages';
require __DIR__ . '/includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Messages</h1>
    <p>Ask the librarian a question, request a book or report a problem.</p>
  </div>
  <button class="btn" type="button" data-open="compose" data-reset><?= icon('plus') ?> New message</button>
</header>

<?php if ($messages): ?>
  <div class="card card--flush">
    <?php foreach ($messages as $m): $isNew = in_array($m['id'], $newReplyIds, false); ?>
      <article class="message-item <?= $isNew ? 'is-unread' : '' ?>">
        <span class="avatar"><?= e(initials($user['name'])) ?></span>
        <div class="message-item__body">
          <div class="row row--wrap" style="gap:8px">
            <span class="message-item__subject"><?= e($m['subject']) ?></span>
            <span class="spacer"></span>
            <?= $m['reply'] !== null ? '<span class="badge badge--ok">Answered</span>' : '<span class="badge badge--muted">Waiting for reply</span>' ?>
          </div>
          <div class="thread">
            <div class="bubble">
              <div class="bubble__meta"><span>You</span><span>·</span><span><?= e(time_ago($m['created_at'])) ?></span></div>
              <p><?= nl2br(e($m['body'])) ?></p>
            </div>
            <?php if ($m['reply'] !== null): ?>
              <div class="bubble bubble--reply">
                <div class="bubble__meta"><strong>Librarian</strong><span>·</span><span><?= e(time_ago($m['replied_at'])) ?></span><?= $isNew ? '<span class="badge badge--warn badge--plain">New</span>' : '' ?></div>
                <p><?= nl2br(e($m['reply'])) ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card empty">
    <?= icon('message') ?>
    <h3>No messages yet</h3>
    <p>Questions and requests you send to the librarian will appear here with their replies.</p>
  </div>
<?php endif; ?>

<dialog class="modal" id="compose" <?= $errors ? 'data-autoopen' : '' ?> aria-labelledby="compose-h">
  <form method="post">
    <?= csrf_field() ?>
    <div class="modal__head"><h2 id="compose-h">Message the librarian</h2><button class="btn btn--ghost btn--icon btn--sm" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal__body">
      <?php if ($errors): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>
      <div class="field"><label for="subject">Subject</label><input class="input" id="subject" name="subject" maxlength="160" required value="<?= e($old['subject']) ?>" placeholder="e.g. Request a book, report a damaged copy"></div>
      <div class="field"><label for="body">Message</label><textarea class="textarea" id="body" name="body" maxlength="4000" required rows="6"><?= e($old['body']) ?></textarea></div>
    </div>
    <div class="modal__foot"><button class="btn btn--ghost" type="button" data-close>Cancel</button><button class="btn" type="submit"><?= icon('send') ?> Send message</button></div>
  </form>
</dialog>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
