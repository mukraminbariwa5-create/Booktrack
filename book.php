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
// Recommendations: same category first, topped up with popular titles so the row is never half-empty.
$related = $book['category']
    ? q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' WHERE b.category = ? AND b.id <> ? ORDER BY b.created_at DESC LIMIT 8', [$book['category'], $book['id']])
    : [];
$relatedTitle = $related ? 'More in ' . $book['category'] : 'You might also like';
if (count($related) < 4) {
    $exclude = array_merge([(int)$book['id']], array_map('intval', array_column($related, 'id')));
    $more = q_all('SELECT ' . book_columns() . ' FROM ' . books_from()
        . ' LEFT JOIN (SELECT book_id, COUNT(*) AS n FROM loans GROUP BY book_id) pop ON pop.book_id = b.id'
        . ' WHERE b.id NOT IN (' . implode(',', $exclude) . ') ORDER BY COALESCE(pop.n, 0) DESC, b.created_at DESC LIMIT ' . (8 - count($related)));
    $related = array_merge($related, $more);
    $relatedTitle = 'You might also like';
}

$title = $book['title'];
$active = $isMember ? 'discover' : 'books';
require __DIR__ . '/includes/partials/header.php';
?>
<a class="back-link" href="<?= e(url($isMember ? 'discover.php' : 'admin/books.php')) ?>"><?= icon('arrow-left') ?> <span>Back to <?= $isMember ? 'catalogue' : 'books' ?></span></a>

<section class="book-hero">
  <?= cover_ambient($book) ?>
  <div class="book-hero__cover" data-tilt><?= cover_html($book) ?></div>
  <div class="stack" style="gap:20px">
    <div class="stack" style="gap:8px">
      <?php if ($book['category']): ?><a class="book-hero__category" href="<?= e(url('discover.php', ['category' => $book['category']])) ?>"><?= e($book['category']) ?></a><?php endif; ?>
      <h1><?= e($book['title']) ?></h1>
      <span class="book-hero__author">by <?= e($book['author']) ?></span>
    </div>

    <div class="row row--wrap">
      <?php if ((int)$book['available'] > 0): ?>
        <span class="badge badge--ok"><?= (int)$book['available'] ?> of <?= (int)$book['copies'] ?> available</span>
      <?php else: ?>
        <span class="badge badge--warn">All copies on loan<?= $nextFree ? ', back by ' . e(fmt_date($nextFree, 'j M')) : '' ?></span>
      <?php endif; ?>
    </div>

    <?php if ($isMember): ?>
    <div class="row row--wrap book-actions">
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
          <button class="btn btn--lg" type="submit"><?= icon('book') ?> Borrow for <?= (int)config('library.loan_days') ?> days</button>
        </form>
      <?php else: ?>
        <button class="btn btn--lg" type="button" disabled>Currently unavailable</button>
      <?php endif; ?>

      <form method="post" action="<?= e(url('api/favorite.php')) ?>" data-favorite>
        <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
        <button class="btn btn--outline btn--lg fav-toggle" type="submit" aria-pressed="<?= $fav ? 'true' : 'false' ?>" >
          <?= icon('heart') ?><span data-fav-label><?= $fav ? 'Saved to favorites' : 'Add to favorites' ?></span>
        </button>
      </form>

      <?php if ($book['pdf']): ?>
        <a class="btn btn--outline btn--lg" href="<?= e(url('read.php', ['id' => $book['id']])) ?>" target="_blank" rel="noopener"><?= icon('file') ?> Read e-book</a>
      <?php endif; ?>
      <button class="btn btn--outline btn--lg btn--share" type="button" data-share hidden><?= icon('share') ?> Share</button>
    </div>
    <?php else: ?>
      <div class="row"><a class="btn btn--ghost" href="<?= e(url('admin/books.php', ['edit' => $book['id']])) ?>"><?= icon('edit') ?> Edit book</a>
      <?php if ($book['pdf']): ?><a class="btn btn--ghost" href="<?= e(url('read.php', ['id' => $book['id']])) ?>" target="_blank" rel="noopener"><?= icon('file') ?> Open PDF</a><?php endif; ?></div>
    <?php endif; ?>

    <?php if ($book['description']): ?>
      <div class="prose" data-clamp><?= nl2br(e($book['description'])) ?></div>
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
<section class="stack" style="gap:20px;margin-top:16px">
  <div class="section-title m-section__head"><h2><?= e($relatedTitle) ?></h2><?php if (str_starts_with($relatedTitle, 'More in ')): ?><a class="link small" href="<?= e(url('discover.php', ['category' => $book['category']])) ?>">See all</a><?php endif; ?></div>
  <div class="shelf-row">
    <?php foreach ($related as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
