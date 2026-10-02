<?php
/**
 * One conversation: header slot, scrolling message list and the composer.
 * Expects:
 *   $thread    rows from chat_thread()
 *   $mine      'member' or 'library' (which side the viewer is on)
 *   $memberId  conversation id (the member)
 *   $chatHead  HTML for the header (already escaped)
 *   $emptyHint text for an empty conversation
 *   $quick     list of quick-start phrases (optional)
 *   $backUrl   mobile back link (optional, librarian inbox)
 */
$lastMineId = 0;
foreach ($thread as $m) if ($m['sender'] === $mine) $lastMineId = (int)$m['id'];
$lastId = $thread ? (int)end($thread)['id'] : 0;
?>
<section class="chat" data-chat
         data-member="<?= (int)$memberId ?>"
         data-mine="<?= e($mine) ?>"
         data-after="<?= $lastId ?>"
         data-endpoint="<?= e(url('api/chat.php')) ?>"
         aria-label="Conversation">
  <header class="chat__head">
    <?php if (!empty($backUrl)): ?><a class="btn btn--ghost btn--icon btn--sm chat__back" href="<?= e($backUrl) ?>" aria-label="Back to all conversations"><?= icon('arrow-left') ?></a><?php endif; ?>
    <?= $chatHead ?>
  </header>

  <div class="chat__scroll" data-chat-scroll tabindex="0" aria-label="Messages">
    <ol class="chat__list" data-chat-list aria-live="polite">
      <?php
      $prev = null;
      foreach ($thread as $i => $m):
          $day = substr($m['created_at'], 0, 10);
          $ts = strtotime($m['created_at']);
          $next = $thread[$i + 1] ?? null;
          $groupEnd = !$next || $next['sender'] !== $m['sender'] || strtotime($next['created_at']) - $ts > 300 || substr($next['created_at'], 0, 10) !== $day;
          $groupStart = !$prev || $prev['sender'] !== $m['sender'] || $ts - strtotime($prev['created_at']) > 300 || substr($prev['created_at'], 0, 10) !== $day;
          if (!$prev || substr($prev['created_at'], 0, 10) !== $day): ?>
            <li class="chat__day" data-day="<?= e($day) ?>"><span><?= e(chat_day_label($m['created_at'])) ?></span></li>
          <?php endif; ?>
          <li class="msg <?= $m['sender'] === $mine ? 'msg--mine' : 'msg--theirs' ?> <?= $groupStart ? 'is-first' : '' ?> <?= $groupEnd ? 'is-last' : '' ?>"
              data-id="<?= (int)$m['id'] ?>" data-sender="<?= e($m['sender']) ?>" data-ts="<?= $ts ?>" data-day="<?= e($day) ?>">
            <div class="msg__bubble"><?= e($m['body']) ?></div>
            <div class="msg__meta"><time datetime="<?= e(date('c', $ts)) ?>"><?= e(fmt_date($m['created_at'], 'g:i A')) ?></time><?php if ((int)$m['id'] === $lastMineId && $m['read_at'] !== null): ?><span class="msg__seen" data-seen> · Seen</span><?php endif; ?></div>
          </li>
      <?php $prev = $m; endforeach; ?>
    </ol>
    <div class="chat__empty" data-chat-empty <?= $thread ? 'hidden' : '' ?>>
      <?= empty_art('chat') ?>
      <p><?= e($emptyHint) ?></p>
    </div>
  </div>

  <form class="chat__composer" method="post" action="<?= e(url('api/chat.php')) ?>" data-chat-form>
    <?= csrf_field() ?>
    <input type="hidden" name="member_id" value="<?= (int)$memberId ?>">
    <?php if (!empty($quick)): ?>
      <div class="chat__quick" data-chat-quick>
        <?php foreach ($quick as $q): ?><button class="chip chip--sm" type="button" data-quick="<?= e($q) ?>"><?= e($q) ?></button><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="chat__input">
      <label class="sr-only" for="chat-body">Message</label>
      <textarea id="chat-body" name="body" rows="1" maxlength="<?= CHAT_MAX_LENGTH ?>" placeholder="Write a message…" required data-chat-input></textarea>
      <button class="btn btn--icon chat__send" type="submit" aria-label="Send message" title="Send (Enter)"><?= icon('send') ?></button>
    </div>
    <p class="chat__hint">Enter to send, Shift + Enter for a new line</p>
  </form>
</section>
