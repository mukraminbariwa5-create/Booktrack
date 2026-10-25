<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');
$uid = (int)$user['id'];

if (is_post()) {
    verify_csrf();
    try {
        $loanId = input_int('loan_id');
        if (input('action') === 'return') {
            request_return($loanId, $uid);
            $wasOverdue = (string)q_val('SELECT due_at FROM loans WHERE id = ?', [$loanId]) < today();
            flash('success', 'Return requested. Drop the book at the desk and the librarian will check it in.',
                $wasOverdue ? null : ['url' => url('loans.php'), 'fields' => ['action' => 'cancel_return', 'loan_id' => $loanId]]);
        } elseif (input('action') === 'cancel_return') {
            cancel_return($loanId, $uid);
            flash('success', 'Return cancelled. The book stays on your loans.');
        } elseif (input('action') === 'cancel_hold') {
            cancel_hold(input_int('hold_id'), $uid);
            flash('success', 'Hold cancelled.');
        } elseif (input('action') === 'renew') {
            $due = renew_loan($loanId, $uid);
            flash('success', 'Renewed. Your new due date is ' . fmt_date($due) . '.');
        }
    } catch (DomainException $ex) {
        flash('error', $ex->getMessage());
    }
    back('loans.php');
}

$loans = member_active_loans($uid);
$history = q_all("SELECT l.*, b.title, b.author, b.cover, (SELECT rating FROM reviews r WHERE r.user_id = l.user_id AND r.book_id = l.book_id) AS my_rating FROM loans l JOIN books b ON b.id = l.book_id
                  WHERE l.user_id = ? AND l.status = 'returned' ORDER BY l.returned_at DESC, l.id DESC LIMIT 50", [$uid]);
$max = (int)config('library.max_active_loans');
$block = borrow_block_reason($uid);
$reading = array_values(array_filter($loans, fn($l) => $l['status'] === 'borrowed'));
$returning = array_values(array_filter($loans, fn($l) => $l['status'] === 'return_requested'));
$lateCount = count(array_filter($reading, fn($l) => $l['due_at'] < today()));
$freeSlots = max(0, $max - count($loans));
$holds = member_holds($uid);
$fines = member_fines($uid);
$accruing = member_accruing_fines($uid);
$owed = array_sum(array_map(fn($f) => $f['status'] === 'unpaid' ? (float)$f['amount'] : 0, $fines));
$settled = array_values(array_filter($fines, fn($f) => $f['status'] !== 'unpaid'));
$unpaid = array_values(array_filter($fines, fn($f) => $f['status'] === 'unpaid'));

$title = 'My loans';
$active = 'loans';
require __DIR__ . '/includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>My loans</h1>
    <p><?php
      $parts = [];
      if ($reading || !$returning) $parts[] = plural(count($reading), 'book') . ' on loan';
      if ($returning) $parts[] = ($reading ? count($returning) : plural(count($returning), 'book')) . ' waiting for check-in';
      echo e(implode(', ', $parts)) . '. ';
      if (!($block && in_array($block['code'], ['overdue', 'fines'], true))) echo $freeSlots ? 'You can borrow ' . e(plural($freeSlots, 'more book')) . '.' : 'You\'ve reached the limit of ' . $max . ' books.';
    ?> Loans last <?= (int)config('library.loan_days') ?> days and can be renewed once.</p>
  </div>
  <a class="btn btn--ghost" href="<?= e(url('discover.php')) ?>"><?= icon('compass') ?> Find a book</a>
</header>

<?php if ($block && $block['code'] === 'overdue'): ?>
  <div class="alert alert--error" role="status"><?= icon('clock') ?>
    <span><strong>Borrowing is paused.</strong> <?= e($block['message']) ?> Press <strong>Return</strong> on the overdue book and you can borrow again right away.</span></div>
<?php elseif ($block && $block['code'] === 'fines'): ?>
  <div class="alert alert--error" role="status"><?= icon('alert') ?>
    <span><strong>Borrowing is paused.</strong> <?= e($block['message']) ?></span></div>
<?php endif; ?>

<?php if ($reading): ?>
  <section class="stack" aria-labelledby="reading-h">
    <div class="section-title"><h2 id="reading-h">Reading now</h2><?php if ($lateCount): ?><span class="badge badge--danger"><?= $lateCount ?> overdue</span><?php endif; ?></div>
    <div class="loan-list">
      <?php foreach ($reading as $loan): $showActions = true; include __DIR__ . '/includes/partials/loan_card.php'; endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($returning): ?>
  <section class="stack" aria-labelledby="returning-h">
    <div class="section-title">
      <h2 id="returning-h">Waiting for check-in</h2>
    </div>
    <p class="muted small" style="margin:-6px 0 0">Hand these to the librarian. Each one moves to your reading history once it's checked in, and until then it still counts toward your limit.</p>
    <div class="loan-list">
      <?php foreach ($returning as $loan): $showActions = true; include __DIR__ . '/includes/partials/loan_card.php'; endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($holds): ?>
  <section class="stack" aria-labelledby="holds-h">
    <div class="section-title"><h2 id="holds-h">Holds</h2></div>
    <div class="loan-list">
      <?php foreach ($holds as $h): $ready = $h['status'] === 'ready'; ?>
        <article class="loan-card" data-book="<?= (int)$h['book_id'] ?>">
          <a href="<?= e(url('book.php', ['id' => $h['book_id']])) ?>" tabindex="-1" aria-hidden="true"><?= cover_html(['title' => $h['title'], 'author' => $h['author'], 'cover' => $h['cover']], 'cover-md') ?></a>
          <div class="loan-card__body">
            <span class="loan-card__due <?= $ready ? 'is-soon' : '' ?>"><?= $ready ? 'Ready until ' . e(fmt_date($h['expires_at'], 'j M')) : ($h['position'] === 1 ? 'Next in line' : '#' . $h['position'] . ' in line') ?></span>
            <a class="loan-card__title" href="<?= e(url('book.php', ['id' => $h['book_id']])) ?>"><?= e($h['title']) ?></a>
            <span class="small muted"><?= $ready ? 'A copy is kept for you at the desk.' : 'Placed ' . e(fmt_date($h['created_at'], 'j M')) . '. We\'ll email you when it\'s your turn.' ?></span>
          </div>
          <div class="loan-card__actions">
            <?php if ($ready): ?>
              <form method="post" action="<?= e(url('book.php', ['id' => $h['book_id']])) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="borrow">
                <button class="btn btn--sm" type="submit" style="width:100%">Borrow</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('loans.php')) ?>" data-confirm="<?= $ready ? 'The copy kept for you goes to the next person in line.' : 'You\'ll lose your place in line.' ?>" data-confirm-title="Cancel this hold?" data-confirm-ok="Cancel hold">
              <?= csrf_field() ?><input type="hidden" name="action" value="cancel_hold"><input type="hidden" name="hold_id" value="<?= (int)$h['id'] ?>">
              <button class="btn btn--ghost btn--sm" type="submit" style="width:100%">Cancel</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($unpaid || $accruing || $settled): ?>
  <section class="stack" aria-labelledby="fines-h" id="fines">
    <div class="section-title">
      <h2 id="fines-h">Late fees</h2>
      <?php if ($owed > 0): ?><span class="badge badge--danger"><?= e(money($owed)) ?> to pay</span><?php endif; ?>
    </div>
    <p class="muted small" style="margin:-6px 0 0">Late books cost <?= e(money((float)config('fines.per_day'))) ?> a day<?= (float)config('fines.max_per_book') > 0 ? ', at most ' . e(money((float)config('fines.max_per_book'))) . ' per book' : '' ?>. A fee stops growing when the book is checked in. Pay at the library desk.</p>
    <div class="card card--flush table-wrap">
      <table class="table table--stack fines-table">
        <thead><tr><th>Book</th><th>Late by</th><th>Status</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($accruing as $a): ?>
          <tr>
            <td><a class="cell-book" href="<?= e(url('book.php', ['id' => $a['book_id']])) ?>"><?= cover_html($a, 'cover-sm') ?><span><strong><?= e($a['title']) ?></strong><span class="muted small">Was due <?= e(fmt_date($a['due_at'], 'j M')) ?></span></span></a></td>
            <td class="nowrap" data-label="Late by"><?= e(plural((int)$a['days_late'], 'day')) ?></td>
            <td><span class="badge badge--warn"><?= $a['status'] === 'return_requested' ? 'Waiting for check-in' : 'Still growing' ?></span></td>
            <td class="num nowrap" data-label="So far"><?= e(money((float)$a['fine'])) ?> <span class="muted small">so far</span></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach (array_merge($unpaid, $settled) as $f): ?>
          <tr>
            <td><?php if ($f['book_id']): ?><a class="cell-book cell-book--plain" href="<?= e(url('book.php', ['id' => $f['book_id']])) ?>"><span><strong><?= e($f['book_title']) ?></strong><span class="muted small">Returned <?= e(fmt_date($f['returned_at'] ?: $f['created_at'], 'j M Y')) ?></span></span></a>
              <?php else: ?><span><strong><?= e($f['book_title']) ?></strong></span><?php endif; ?></td>
            <td class="nowrap" data-label="Late by"><?= e(plural((int)$f['days_late'], 'day')) ?></td>
            <td><?= fine_badge($f) ?><?php if ($f['status'] !== 'unpaid' && $f['settled_at']): ?> <span class="small muted nowrap"><?= e(fmt_date($f['settled_at'], 'j M')) ?></span><?php endif; ?></td>
            <td class="num nowrap" data-label="Amount"><?= $f['status'] === 'waived' ? '<s class="muted">' . e(money((float)$f['amount'])) . '</s>' : e(money((float)$f['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if (!$loans && !$holds): ?>
  <div class="card empty">
    <?= empty_art('shelf') ?>
    <h3>No books on loan</h3>
    <p>You can borrow up to <?= $max ?> books at a time, each for <?= (int)config('library.loan_days') ?> days.</p>
    <a class="btn btn--sm" href="<?= e(url('discover.php')) ?>">Browse the catalogue</a>
  </div>
<?php endif; ?>

<section class="stack" id="history">
  <div class="section-title"><h2>Reading history</h2></div>
  <?php if ($history): ?>
  <div class="card card--flush table-wrap">
    <table class="table table--stack">
      <thead><tr><th>Book</th><th>Borrowed</th><th>Returned</th><th>Status</th><th>Your rating</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><a class="cell-book" href="<?= e(url('book.php', ['id' => $h['book_id']])) ?>"><?= cover_html($h, 'cover-sm') ?><span><strong><?= e($h['title']) ?></strong><span class="muted small"><?= e($h['author']) ?></span></span></a></td>
          <td class="nowrap" data-label="Borrowed"><?= e(fmt_date($h['borrowed_at'])) ?></td>
          <td class="nowrap" data-label="Returned"><?= e(fmt_date($h['returned_at'])) ?></td>
          <td><?= $h['returned_at'] > $h['due_at'] ? '<span class="badge badge--warn">Returned late</span>' : '<span class="badge badge--ok">On time</span>' ?></td>
          <td data-label="Your rating"><?= $h['my_rating'] ? '<a href="' . e(url('book.php', ['id' => $h['book_id']])) . '#reviews">' . stars_html((float)$h['my_rating'], 'stars stars--sm') . '</a>' : '<a class="link small" href="' . e(url('book.php', ['id' => $h['book_id']])) . '#reviews">Rate it</a>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="muted">Books you've returned will be listed here.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
