<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

$search = input('q');
$filter = input('filter') === 'unread' ? 'unread' : 'all';
$selectedId = input_int('member');


// The member whose chat is open (may have no messages yet: "start a conversation").
$member = $selectedId
    ? q_one("SELECT id, name, username, email, phone, is_active, created_at FROM users WHERE id = ? AND role = 'member'", [$selectedId])
    : null;
if ($selectedId && !$member) {
    flash('error', 'That member could not be found.');
    redirect('admin/messages.php');
}

$thread = [];
if ($member) {
    $thread = chat_thread((int)$member['id']);
    chat_mark_read((int)$member['id'], 'library');
    $loanCount = active_loan_count((int)$member['id']);
    $overdueCount = (int)q_val("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'borrowed' AND due_at < ?", [$member['id'], today()]);
}

// Listed after marking the open chat as read, so its unread badge clears straight away.
$conversations = chat_conversations($search, $filter === 'unread');
$unreadTotal = chat_unread_conversations();

// Members without a conversation yet, for "New conversation".
$startable = q_all("SELECT u.id, u.name, u.username FROM users u WHERE u.role = 'member' AND u.is_active = 1
                    AND NOT EXISTS (SELECT 1 FROM chat_messages c WHERE c.member_id = u.id) ORDER BY u.name");

$title = 'Messages';
$active = 'messages';
$bodyClass = 'page-chat';
require __DIR__ . '/../includes/partials/header.php';

$listUrl = fn(array $extra = []) => url('admin/messages.php', array_filter($extra + ['q' => $search, 'filter' => $filter === 'unread' ? 'unread' : '']));
?>
<h1 class="sr-only">Messages</h1>
<div class="inbox <?= $member ? 'has-open' : '' ?>">
  <aside class="inbox__list" aria-label="Conversations">
    <div class="inbox__top">
      <div class="row" style="justify-content:space-between">
        <h2>Messages</h2>
        <button class="btn btn--sm" type="button" data-open="new-chat"><?= icon('plus') ?> New</button>
      </div>
      <form method="get" class="search search--sm" role="search">
        <?= icon('search') ?>
        <label class="sr-only" for="conv-q">Search conversations</label>
        <input id="conv-q" type="search" name="q" autocomplete="off" spellcheck="false" value="<?= e($search) ?>" placeholder="Search members" data-filter-list="#conv-list">
        <?php if ($filter === 'unread'): ?><input type="hidden" name="filter" value="unread"><?php endif; ?>
      </form>
      <nav class="seg" aria-label="Filter conversations">
        <a href="<?= e(url('admin/messages.php', array_filter(['q' => $search]))) ?>" <?= $filter === 'all' ? 'aria-current="page"' : '' ?>>All</a>
        <a href="<?= e(url('admin/messages.php', array_filter(['q' => $search, 'filter' => 'unread']))) ?>" <?= $filter === 'unread' ? 'aria-current="page"' : '' ?>>Unread<?php if ($unreadTotal): ?> <span class="count"><?= $unreadTotal ?></span><?php endif; ?></a>
      </nav>
    </div>

    <?php if ($conversations): ?>
      <ul class="conv-list" id="conv-list">
        <?php foreach ($conversations as $c): $open = $member && (int)$member['id'] === (int)$c['id']; ?>
          <li data-filter-text="<?= e(mb_strtolower($c['name'] . ' ' . $c['username'])) ?>">
            <a class="conv <?= (int)$c['unread'] ? 'is-unread' : '' ?>" href="<?= e($listUrl(['member' => $c['id']])) ?>" <?= $open ? 'aria-current="true"' : '' ?>>
              <span class="avatar"><?= e(initials($c['name'])) ?></span>
              <span class="conv__body">
                <span class="conv__row">
                  <span class="conv__name"><?= e($c['name']) ?></span>
                  <time class="conv__time" datetime="<?= e(date('c', strtotime($c['created_at']))) ?>"><?= e(chat_short_time($c['created_at'])) ?></time>
                </span>
                <span class="conv__row">
                  <span class="conv__preview"><?= $c['sender'] === 'library' ? '<span class="conv__you">You: </span>' : '' ?><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $c['body']), 0, 90, '…')) ?></span>
                  <?php if ((int)$c['unread']): ?><span class="conv__badge" aria-label="<?= (int)$c['unread'] ?> unread"><?= (int)$c['unread'] ?></span><?php endif; ?>
                </span>
                <span class="conv__user">@<?= e($c['username']) ?><?= (int)$c['is_active'] ? '' : ', deactivated' ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="inbox__nomatch small muted" data-filter-empty hidden>No members match your search.</p>
    <?php else: ?>
      <div class="empty empty--compact">
        <?= icon('inbox') ?>
        <p><?= $search !== '' ? 'No conversations match “' . e($search) . '”.' : ($filter === 'unread' ? 'You\'re all caught up. No unread messages.' : 'No conversations yet. When members write to the library, they appear here.') ?></p>
      </div>
    <?php endif; ?>
  </aside>

  <div class="inbox__chat">
    <?php if ($member):
        $mine = 'library';
        $memberId = (int)$member['id'];
        $backUrl = $listUrl();
        $badges = [];
        if ($overdueCount) $badges[] = '<span class="badge badge--danger">' . $overdueCount . ' overdue</span>';
        if ($loanCount) $badges[] = '<span class="badge badge--info">' . plural($loanCount, 'book') . ' on loan</span>';
        if (!(int)$member['is_active']) $badges[] = '<span class="badge badge--muted">Deactivated</span>';
        $chatHead = '<span class="chat__avatar avatar">' . e(initials($member['name'])) . '</span>'
            . '<div class="chat__who"><strong>' . e($member['name']) . '</strong>'
            . '<span>@' . e($member['username']) . ($member['email'] ? ', ' . e($member['email']) : '') . '</span></div>'
            . '<div class="chat__badges">' . implode('', $badges) . '</div>'
            . '<a class="btn btn--ghost btn--sm chat__action" href="' . e(url('admin/loans.php', ['q' => $member['username'], 'tab' => 'active'])) . '">' . icon('swap') . ' Loans</a>';
        $emptyHint = 'No messages with ' . $member['name'] . ' yet. Write the first one below.';
        $quick = [
            'Your book is ready to pick up at the desk.',
            'Thanks for letting us know. We\'ll look into it.',
            'Friendly reminder: your book is overdue. Please return it soon.',
        ];
        include __DIR__ . '/../includes/partials/chat_view.php';
    else: ?>
      <div class="chat chat--placeholder">
        <div class="empty">
          <?= empty_art('chat') ?>
          <h3>Choose a conversation</h3>
          <p><?= $unreadTotal ? plural($unreadTotal, 'member') . ' waiting for a reply.' : 'Pick a member on the left, or start a new conversation.' ?></p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<dialog class="modal modal--sm" id="new-chat" aria-labelledby="new-chat-h">
  <form method="get" action="<?= e(url('admin/messages.php')) ?>">
    <div class="modal__head"><h2 id="new-chat-h">New conversation</h2><button class="btn btn--ghost btn--icon btn--sm" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal__body">
      <div class="field">
        <label for="new-chat-member">Member</label>
        <select class="select" id="new-chat-member" name="member" required>
          <option value="">Choose a member…</option>
          <?php foreach ($startable as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?> (@<?= e($m['username']) ?>)</option><?php endforeach; ?>
          <?php if ($conversations): ?>
            <optgroup label="Already chatting">
              <?php foreach ($conversations as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (@<?= e($c['username']) ?>)</option><?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </div>
    </div>
    <div class="modal__foot"><button class="btn btn--ghost" type="button" data-close>Cancel</button><button class="btn" type="submit">Open chat</button></div>
  </form>
</dialog>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
