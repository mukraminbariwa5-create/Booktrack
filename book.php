<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$isMember = $user['role'] === 'member';

$book = find_book(input_int('id'));
if (!$book) {
    flash('error', 'That book could not be found.');
    redirect($isMember ? 'discover.php' : 'admin/books.php');
}

if (is_post() && $isMember) {
    verify_csrf();
    try {
        if (input('action') === 'borrow') {
            create_loan($uid, (int)$book['id']);
            flash('success', 'You borrowed “' . $book['title'] . '”. It\'s due in ' . config('library.loan_days') . ' days.');
        }
    } catch (DomainException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('book.php', ['id' => $book['id']]);
}

$myLoan = $isMember ? q_one('SELECT * FROM loans WHERE user_id = ? AND book_id = ? AND status IN ' . ACTIVE_LOAN, [$uid, $book['id']]) : null;
$fav = $isMember && is_favorite($uid, (int)$book['id']);
$timesBorrowed = (int)q_val('SELECT COUNT(*) FROM loans WHERE book_id = ?', [$book['id']]);
$nextFree = (int)$book['available'] <= 0
    ? q_val('SELECT MIN(due_at) FROM loans WHERE book_id = ? AND status IN ' . ACTIVE_LOAN, [$book['id']])
    : null;
$related = $book['category']
    ? q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' WHERE b.category = ? AND b.id <> ? ORDER BY b.created_at DESC LIMIT 6', [$book['category'], $book['id']])
    : [];

$title = $book['title'];
$active = $isMember ? 'discover' : 'books';
require __DIR__ . '/includes/partials/header.php';
?>
<a class="row small muted" href="<?= e(url($isMember ? 'discover.php' : 'admin/books.php')) ?>" style="gap:6px"><?= icon('arrow-left') ?> Back to <?= $isMember ? 'catalogue' : 'books' ?></a>

<section class="book-hero">
  <div class="book-hero__cover"><?= cover_html($book) ?></div>
  <div class="stack" style="gap:20px">
    <div class="stack" style="gap:8px">
      <?php if ($book['category']): ?><a class="eyebrow" href="<?= e(url('discover.php', ['category' => $book['category']])) ?>"><?= e($book['category']) ?></a><?php endif; ?>
      <h1><?= e($book['title']) ?></h1>
      <span class="book-hero__author">by <?= e($book['author']) ?></span>
    </div>

    <div class="row row--wrap">
      <?php if ((int)$book['available'] > 0): ?>
        <span class="badge badge--ok"><?= (int)$book['available'] ?> of <?= (int)$book['copies'] ?> available</span>
      <?php else: ?>
        <span class="badge badge--warn">All copies on loan<?= $nextFree ? ' · back by ' . e(fmt_date($nextFree, 'j M')) : '' ?></span>
      <?php endif; ?>
    </div>

    <?php if ($isMember): ?>
    <div class="row row--wrap">
      <?php if ($myLoan): ?>
        <div class="alert alert--info" style="flex:1;min-width:260px">
          <?= icon('book') ?>
          <span>You have this book.
            <?= $myLoan['status'] === 'return_requested' ? 'Your return is waiting for the librarian to check it in.' : e(due_label($myLoan['due_at'])) . ' (' . e(fmt_date($myLoan['due_at'])) . ').' ?>
            <a class="link" href="<?= e(url('loans.php')) ?>">Manage loans</a></span>
        </div>
      <?php elseif ((int)$book['available'] > 0): ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="borrow">
          <button class="btn" type="submit" style="height:46px;padding:0 24px"><?= icon('book') ?> Borrow for <?= (int)config('library.loan_days') ?> days</button>
        </form>
      <?php else: ?>
        <button class="btn" type="button" disabled style="height:46px">Currently unavailable</button>
      <?php endif; ?>

      <form method="post" action="<?= e(url('api/favorite.php')) ?>" data-favorite>
        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
        <button class="btn btn--ghost fav-toggle" type="submit" aria-pressed="<?= $fav ? 'true' : 'false' ?>" style="height:46px">
          <?= icon('heart') ?><span data-fav-label><?= $fav ? 'Saved to favorites' : 'Add to favorites' ?></span>
        </button>
      </form>

      <?php if ($book['pdf']): ?>
        <a class="btn btn--ghost" href="<?= e(url('read.php', ['id' => $book['id']])) ?>" target="_blank" rel="noopener" style="height:46px"><?= icon('file') ?> Read e-book</a>
      <?php endif; ?>
    </div>
    <?php else: ?>
      <div class="row"><a class="btn btn--ghost" href="<?= e(url('admin/books.php', ['edit' => $book['id']])) ?>"><?= icon('edit') ?> Edit book</a>
      <?php if ($book['pdf']): ?><a class="btn btn--ghost" href="<?= e(url('read.php', ['id' => $book['id']])) ?>" target="_blank" rel="noopener"><?= icon('file') ?> Open PDF</a><?php endif; ?></div>
    <?php endif; ?>

    <?php if ($book['description']): ?>
      <div class="prose"><?= nl2br(e($book['description'])) ?></div>
    <?php endif; ?>

    <dl class="meta-list">
      <div><dt>Author</dt><dd><?= e($book['author']) ?></dd></div>
      <div><dt>Category</dt><dd><?= e($book['category'] ?: '—') ?></dd></div>
      <div><dt>Published</dt><dd><?= e($book['published_year'] ?: '—') ?></dd></div>
      <div><dt>Copies</dt><dd><?= (int)$book['copies'] ?></dd></div>
      <div><dt>Times borrowed</dt><dd><?= $timesBorrowed ?></dd></div>
      <?php if ($book['isbn']): ?><div><dt>ISBN</dt><dd><?= e($book['isbn']) ?></dd></div><?php endif; ?>
    </dl>
  </div>
</section>

<?php if ($related && $isMember): ?>
<section class="stack" style="gap:20px;margin-top:12px">
  <div class="section-title"><h2>More in <?= e($book['category']) ?></h2></div>
  <div class="book-grid">
    <?php foreach ($related as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
