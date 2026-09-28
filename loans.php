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
            flash('success', 'Return requested. Drop the book at the desk and the librarian will check it in.');
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
$history = q_all("SELECT l.*, b.title, b.author, b.cover FROM loans l JOIN books b ON b.id = l.book_id
                  WHERE l.user_id = ? AND l.status = 'returned' ORDER BY l.returned_at DESC, l.id DESC LIMIT 50", [$uid]);
$max = (int)config('library.max_active_loans');

$title = 'My loans';
$active = 'loans';
require __DIR__ . '/includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>My loans</h1>
    <p>You have <?= plural(count($loans), 'book') ?> out of a maximum of <?= $max ?>. Loans last <?= (int)config('library.loan_days') ?> days and can be renewed once.</p>
  </div>
  <a class="btn btn--ghost" href="<?= e(url('discover.php')) ?>"><?= icon('compass') ?> Find a book</a>
</header>

<?php if ($loans): ?>
  <div class="loan-list">
    <?php foreach ($loans as $loan): $showActions = true; include __DIR__ . '/includes/partials/loan_card.php'; endforeach; ?>
  </div>
<?php else: ?>
  <div class="card empty">
    <?= icon('book') ?>
    <h3>No books on loan</h3>
    <p>When you borrow a book it will show up here with its due date.</p>
    <a class="btn btn--sm" href="<?= e(url('discover.php')) ?>">Browse the catalogue</a>
  </div>
<?php endif; ?>

<section class="stack">
  <div class="section-title"><h2>Reading history</h2></div>
  <?php if ($history): ?>
  <div class="card card--flush table-wrap">
    <table class="table">
      <thead><tr><th>Book</th><th>Borrowed</th><th>Returned</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><a class="cell-book" href="<?= e(url('book.php', ['id' => $h['book_id']])) ?>"><?= cover_html($h, 'cover-sm') ?><span><strong><?= e($h['title']) ?></strong><span class="muted small"><?= e($h['author']) ?></span></span></a></td>
          <td class="nowrap"><?= e(fmt_date($h['borrowed_at'])) ?></td>
          <td class="nowrap"><?= e(fmt_date($h['returned_at'])) ?></td>
          <td><?= $h['returned_at'] > $h['due_at'] ? '<span class="badge badge--warn">Returned late</span>' : '<span class="badge badge--ok">On time</span>' ?></td>
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
