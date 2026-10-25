<?php
/**
 * The desk: scan a member's library card (or type the number or a name) to see
 * everything for that member in one place: holds to hand out, books on loan to
 * check in, and lending a book.
 */
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

if (is_post()) {
    verify_csrf();
    $memberId = input_int('member_id');
    try {
        $action = input('action');
        if ($action === 'return') {
            $loan = find_loan(input_int('loan_id'));
            if (!$loan || (int)$loan['user_id'] !== $memberId) throw new DomainException('Loan not found.');
            $fee = complete_return((int)$loan['id']);
            flash('success', '“' . $loan['title'] . '” is checked in.' . ($fee > 0 ? ' It was late, so a ' . money($fee) . ' fee was added.' : ''));
        } elseif ($action === '2fa_off') {
            // Done at the desk, where the librarian can check who the member is in person.
            if (!q_val("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'member'", [$memberId])) throw new DomainException('Member not found.');
            two_factor_disable($memberId, 'user.2fa_reset');
            flash('success', 'Two-step sign-in is off for this member. They can sign in with their password and set it up again.');
        } elseif ($action === 'fine_paid' || $action === 'fine_waive') {
            $fine = q_one('SELECT id, user_id FROM fines WHERE id = ?', [input_int('fine_id')]);
            if (!$fine || (int)$fine['user_id'] !== $memberId) throw new DomainException('Fine not found.');
            $f = settle_fine((int)$fine['id'], $action === 'fine_paid' ? 'paid' : 'waived', (int)$user['id'], input('note'));
            flash('success', money((float)$f['amount']) . ' fee ' . ($action === 'fine_paid' ? 'marked paid.' : 'waived.'));
        } elseif ($action === 'lend') {
            $bookId = input_int('book_id');
            if (!$bookId) throw new DomainException('Choose a book to lend.');
            create_loan($memberId, $bookId, null, input('override') !== '1');
            $title = q_val('SELECT title FROM books WHERE id = ?', [$bookId]);
            flash('success', '“' . $title . '” is lent, due ' . fmt_date(date('Y-m-d', strtotime('+' . (int)config('library.loan_days') . ' days')), 'j M') . '.');
        }
    } catch (DomainException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/desk.php', ['card' => card_number($memberId)]);
}

$query = input('card');
$member = $query !== '' ? member_by_card($query) : null;
$matches = [];
if ($query !== '' && !$member) {
    $like = '%' . $query . '%';
    $matches = q_all("SELECT id, name, username, is_active FROM users WHERE role = 'member' AND (name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY name LIMIT 8", [$like, $like, $like]);
    if (count($matches) === 1) redirect('admin/desk.php', ['card' => card_number((int)$matches[0]['id'])]);
}

if ($member) {
    $mid = (int)$member['id'];
    $loans = member_active_loans($mid);
    $ready = q_all("SELECT h.*, b.title, b.author, b.cover FROM holds h JOIN books b ON b.id = h.book_id WHERE h.user_id = ? AND h.status = 'ready' ORDER BY h.expires_at", [$mid]);
    $waiting = q_all("SELECT h.*, b.title FROM holds h JOIN books b ON b.id = h.book_id WHERE h.user_id = ? AND h.status = 'waiting' ORDER BY h.created_at", [$mid]);
    $block = borrow_block_reason($mid);
    $unpaidFines = member_fines($mid, true);
    $owed = array_sum(array_map(fn($f) => (float)$f['amount'], $unpaidFines));
    $held = array_map('intval', array_column($loans, 'book_id'));
    $lendable = array_values(array_filter(
        q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' WHERE (b.copies - COALESCE(al.active, 0)) > 0 ORDER BY b.title'),
        fn($b) => !in_array((int)$b['id'], $held, true)
    ));
}

$title = 'Desk';
$active = 'desk';
require __DIR__ . '/../includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Desk</h1>
    <p>Scan a member's library card to see their loans and holds, check books in and lend new ones.</p>
  </div>
</header>

<form class="desk-scan" method="get" role="search" data-desk-form>
  <label class="search" style="flex:1">
    <?= icon('search') ?><span class="sr-only">Library card number or member name</span>
    <input type="search" name="card" value="<?= e($query) ?>" autocomplete="off" spellcheck="false" placeholder="Scan a card, or type BT-000002 or a name" <?= $member ? '' : 'autofocus' ?> data-desk-input>
  </label>
  <button class="btn btn--outline" type="button" data-scan hidden><?= icon('camera') ?> Scan with camera</button>
  <button class="btn" type="submit">Find</button>
</form>

<?php if ($query !== '' && !$member && !$matches): ?>
  <div class="card empty"><?= empty_art('members') ?><h2 class="empty__title">No member found</h2><p>Check the card number, or search by name, username or email.</p></div>
<?php elseif ($matches): ?>
  <section class="card card--flush" aria-label="Matching members">
    <ul class="desk-matches">
      <?php foreach ($matches as $m): ?>
        <li><a href="<?= e(url('admin/desk.php', ['card' => card_number((int)$m['id'])])) ?>">
          <span class="avatar"><?= e(initials($m['name'])) ?></span>
          <span><strong><?= e($m['name']) ?></strong><span class="small muted">@<?= e($m['username']) ?>, <?= e(card_number((int)$m['id'])) ?><?= (int)$m['is_active'] ? '' : ', deactivated' ?></span></span>
          <?= icon('chevron-right') ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php elseif ($member): ?>
  <section class="card desk-member" aria-label="Member">
    <span class="avatar avatar--lg"><?= e(initials($member['name'])) ?></span>
    <div class="desk-member__who">
      <h2><?= e($member['name']) ?></h2>
      <p class="small muted">@<?= e($member['username']) ?>, card <?= e(card_number($mid)) ?><?= $member['email'] ? ', ' . e($member['email']) : '' ?></p>
      <div class="row row--wrap" style="gap:8px">
        <?php if (!(int)$member['is_active']): ?><span class="badge badge--danger">Deactivated</span><?php endif; ?>
        <?php if (two_factor_enabled($mid)): ?><span class="badge badge--info">Two-step on</span><?php endif; ?>
        <?php if ($block && $block['code'] === 'overdue'): ?><span class="badge badge--danger">Has overdue books</span><?php endif; ?>
        <?php if ($owed > 0): ?><span class="badge <?= $block && $block['code'] === 'fines' ? 'badge--danger' : 'badge--warn' ?>"><?= e(money($owed)) ?> unpaid fees</span><?php endif; ?>
        <?php if ($block && $block['code'] === 'limit'): ?><span class="badge badge--warn">At the loan limit</span><?php endif; ?>
        <?php if (!$block && (int)$member['is_active']): ?><span class="badge badge--ok">Can borrow</span><?php endif; ?>
      </div>
    </div>
    <div class="desk-member__actions">
      <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/messages.php', ['member' => $mid])) ?>"><?= icon('message') ?> Message</a>
      <?php if (two_factor_enabled($mid)): ?>
        <form method="post" data-confirm="Only do this after checking it's really <?= e($member['name']) ?>, for example with an ID. They'll sign in with just their password until they set it up again." data-confirm-title="Turn off two-step sign-in?" data-confirm-ok="Turn it off">
          <?= csrf_field() ?><input type="hidden" name="action" value="2fa_off"><input type="hidden" name="member_id" value="<?= $mid ?>">
          <button class="btn btn--ghost btn--sm" type="submit" title="For a member who lost their phone and recovery codes"><?= icon('shield') ?> Turn off two-step</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <div class="grid-2 desk-grid">
    <div class="stack">
      <?php if ($ready): ?>
      <section class="card stack" aria-labelledby="ready-h">
        <div class="card__head" style="margin:0"><h2 id="ready-h">Holds to hand out</h2></div>
        <ul class="desk-list">
          <?php foreach ($ready as $h): ?>
            <li>
              <?= cover_html($h, 'cover-sm') ?>
              <span class="desk-list__text"><strong><?= e($h['title']) ?></strong><span class="small muted">Kept until <?= e(fmt_date($h['expires_at'], 'j M')) ?></span></span>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="lend"><input type="hidden" name="member_id" value="<?= $mid ?>"><input type="hidden" name="book_id" value="<?= (int)$h['book_id'] ?>">
                <button class="btn btn--sm" type="submit"><?= icon('book') ?> Lend now</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <section class="card stack" aria-labelledby="onloan-h">
        <div class="card__head" style="margin:0"><h2 id="onloan-h">On loan</h2><span class="small muted"><?= count($loans) ?> of <?= (int)config('library.max_active_loans') ?></span></div>
        <?php if ($loans): ?>
          <ul class="desk-list">
            <?php foreach ($loans as $l): $state = loan_state($l); ?>
              <li>
                <?= cover_html($l, 'cover-sm') ?>
                <span class="desk-list__text"><strong><?= e($l['title']) ?></strong>
                  <span class="small <?= $state === 'overdue' ? 'is-late' : 'muted' ?>"><?= $l['status'] === 'return_requested' ? 'Member asked to return it' : e(due_label($l['due_at'])) ?><?= ($fee = accruing_fine($l)) > 0 ? ', fee ' . e(money($fee)) . ' so far' : '' ?></span></span>
                <form method="post">
                  <?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="member_id" value="<?= $mid ?>"><input type="hidden" name="loan_id" value="<?= (int)$l['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit"><?= icon('check') ?> Check in</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="muted small" style="margin:0">No books on loan.</p>
        <?php endif; ?>
      </section>

      <?php if ($unpaidFines): ?>
      <section class="card stack" aria-labelledby="fines-h">
        <div class="card__head" style="margin:0"><h2 id="fines-h">Late fees</h2><span class="small muted"><?= e(money($owed)) ?> unpaid</span></div>
        <ul class="desk-list">
          <?php foreach ($unpaidFines as $f): ?>
            <li>
              <span class="desk-list__text"><strong><?= e($f['book_title']) ?></strong><span class="small muted"><?= e(plural((int)$f['days_late'], 'day')) ?> late, <?= e(money((float)$f['amount'])) ?></span></span>
              <div class="actions">
                <form method="post">
                  <?= csrf_field() ?><input type="hidden" name="action" value="fine_paid"><input type="hidden" name="member_id" value="<?= $mid ?>"><input type="hidden" name="fine_id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn--sm" type="submit"><?= icon('check') ?> Paid</button>
                </form>
                <form method="post" data-confirm="<?= e($member['name']) ?> won't owe <?= e(money((float)$f['amount'])) ?> for “<?= e($f['book_title']) ?>”. This is logged." data-confirm-title="Waive this fee?" data-confirm-ok="Waive fee">
                  <?= csrf_field() ?><input type="hidden" name="action" value="fine_waive"><input type="hidden" name="member_id" value="<?= $mid ?>"><input type="hidden" name="fine_id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit">Waive</button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <?php if ($waiting): ?>
        <p class="small muted" style="margin:0">In line for: <?= e(implode(', ', array_map(fn($w) => '“' . $w['title'] . '” (#' . hold_position($w) . ')', $waiting))) ?>.</p>
      <?php endif; ?>
    </div>

    <section class="card stack" aria-labelledby="lend-h">
      <div class="card__head" style="margin:0"><h2 id="lend-h">Lend a book</h2></div>
      <?php if ($block): ?><div class="alert alert--warn"><?= icon('alert') ?><span><?= e($block['message']) ?></span></div><?php endif; ?>
      <form class="stack" method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="lend"><input type="hidden" name="member_id" value="<?= $mid ?>">
        <div class="field">
          <label for="lend-book">Book on the shelf</label>
          <select class="select" id="lend-book" name="book_id" required>
            <option value="">Choose a book…</option>
            <?php foreach ($lendable as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['title']) ?>, <?= e($b['author']) ?> (<?= (int)$b['available'] ?> left)</option><?php endforeach; ?>
          </select>
        </div>
        <?php if ($block): ?><label class="row small" style="gap:8px"><input type="checkbox" name="override" value="1"> Lend anyway (skip the member's limits)</label><?php endif; ?>
        <div><button class="btn" type="submit"><?= icon('book') ?> Lend for <?= (int)config('library.loan_days') ?> days</button></div>
      </form>
    </section>
  </div>
<?php else: ?>
  <div class="card empty desk-idle">
    <?= empty_art('members') ?>
    <h2 class="empty__title">Ready to scan</h2>
    <p>Members find their card on their Profile page. A USB barcode scanner types the number straight into the box above. On a phone, use <strong>Scan with camera</strong>.</p>
  </div>
<?php endif; ?>

<dialog class="modal scan-dialog" id="scan-dialog" aria-labelledby="scan-h">
  <div class="modal__head"><h2 id="scan-h">Scan a library card</h2><button class="btn btn--ghost btn--icon btn--sm" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal__body stack">
    <video class="scan-video" playsinline muted></video>
    <p class="small muted" data-scan-status style="margin:0">Point the camera at the QR code on the member's card.</p>
  </div>
</dialog>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
