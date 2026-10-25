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
        } elseif (input('action') === 'hold') {
            place_hold($uid, (int)$book['id']);
            $pos = hold_position(member_hold($uid, (int)$book['id']));
            flash('success', $pos === 1
                ? 'You\'re first in line for “' . $book['title'] . '”. We\'ll email you when a copy is kept for you.'
                : 'You\'re #' . $pos . ' in line for “' . $book['title'] . '”. We\'ll email you when a copy is kept for you.');
        } elseif (input('action') === 'cancel_hold') {
            $h = member_hold($uid, (int)$book['id']);
            if ($h) cancel_hold((int)$h['id'], $uid);
            flash('success', 'You left the line for “' . $book['title'] . '”.');
        }
    } catch (DomainException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('book.php', ['id' => $book['id']]);
}

$myLoan = $isMember ? q_one('SELECT * FROM loans WHERE user_id = ? AND book_id = ? AND status IN ' . ACTIVE_LOAN, [$uid, $book['id']]) : null;
$block = $isMember && !$myLoan ? borrow_block_reason($uid) : null;
$myHold = $isMember && !$myLoan ? member_hold($uid, (int)$book['id']) : null;
$holdReady = $myHold && $myHold['status'] === 'ready';
$canBorrow = (int)$book['available'] > 0 || $holdReady;   // a ready hold keeps a copy for this member
$queue = hold_queue_length((int)$book['id']);
$heldIds = $isMember ? array_map('intval', array_column(member_active_loans($uid), 'book_id')) : [];
$fav = $isMember && is_favorite($uid, (int)$book['id']);
$summary = rating_summary((int)$book['id']);
$myReview = $isMember ? member_review($uid, (int)$book['id']) : null;
$canReview = $isMember && can_review($uid, (int)$book['id']);
// the book page shows a few reviews; "See all" opens the full, filterable list
$topReviews = book_reviews((int)$book['id'], 0, 'recent', 3, 0, $myReview ? $uid : null);
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
      <?php if ((int)$book['reviews'] > 0): ?>
        <a class="book-hero__rating" href="#reviews"><?= stars_html((float)$book['rating']) ?><span><strong><?= e(rating_label((float)$book['rating'])) ?></strong> from <?= plural((int)$book['reviews'], 'review') ?></span></a>
      <?php endif; ?>
    </div>

    <div class="row row--wrap">
      <?php if ((int)$book['available'] > 0): ?>
        <span class="badge badge--ok"><?= (int)$book['available'] ?> of <?= (int)$book['copies'] ?> available</span>
      <?php else: ?>
        <span class="badge badge--warn">All copies on loan<?= $nextFree ? ', back by ' . e(fmt_date($nextFree, 'j M')) : '' ?></span>
        <?php if ($queue && !$myHold): ?><span class="badge badge--muted"><?= $queue === 1 ? '1 person' : $queue . ' people' ?> waiting</span><?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if ($isMember): ?>
    <?php if ($myLoan): ?>
      <div class="alert alert--info" role="status">
        <?= icon('book') ?>
        <span><strong>You have this book.</strong>
          <?= $myLoan['status'] === 'return_requested' ? 'Your return is waiting for the librarian to check it in.' : e(due_label($myLoan['due_at'])) . ' (' . e(fmt_date($myLoan['due_at'])) . ').' ?></span>
      </div>
    <?php elseif ($holdReady): ?>
      <div class="alert alert--warn" role="status">
        <?= icon('bell') ?>
        <span><strong>Your hold is ready.</strong> A copy is kept for you until <?= e(fmt_date($myHold['expires_at'], 'l, j M')) ?>.<?= $block ? ' ' . e($block['message']) : ' Borrow it before then, or it goes to the next person in line.' ?></span>
      </div>
    <?php elseif ($myHold): $pos = hold_position($myHold); ?>
      <div class="alert alert--info" role="status">
        <?= icon('clock') ?>
        <span><strong><?= $pos === 1 ? 'You\'re next in line.' : 'You\'re #' . $pos . ' in line.' ?></strong> We'll email you and keep a copy for <?= plural(hold_pickup_days(), 'day') ?> when one comes back.</span>
      </div>
    <?php elseif ($block && $canBorrow): ?>
      <div class="alert alert--error" role="status">
        <?= icon('alert') ?>
        <span><strong><?= $block['code'] === 'overdue' ? 'Borrowing is paused.' : 'Loan limit reached.' ?></strong> <?= e($block['message']) ?></span>
      </div>
    <?php endif; ?>
    <div class="row row--wrap book-actions">
      <?php if ($myLoan || ($block && $canBorrow)): ?>
        <a class="btn btn--lg btn--outline" href="<?= e(url('loans.php')) ?>"><?= icon('book') ?> Go to My loans</a>
      <?php elseif ($myHold && !$holdReady): ?>
        <form method="post" data-confirm="You'll lose your place in line for “<?= e($book['title']) ?>”." data-confirm-title="Leave the line?" data-confirm-ok="Leave the line">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel_hold">
          <button class="btn btn--lg btn--outline" type="submit"><?= icon('x') ?> Leave the line</button>
        </form>
      <?php elseif ($canBorrow): ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="borrow">
          <button class="btn btn--lg" type="submit"><?= icon('book') ?> Borrow for <?= (int)config('library.loan_days') ?> days</button>
        </form>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="hold">
          <button class="btn btn--lg" type="submit"><?= icon('clock') ?> Place a hold</button>
        </form>
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

<section class="ratings" id="reviews" aria-labelledby="reviews-h">
  <div class="section-title">
    <h2 id="reviews-h">Ratings and reviews</h2>
    <?php if ($summary['total']): ?><a class="link small" href="<?= e(url('reviews.php', ['book' => $book['id']])) ?>">See all <?= (int)$summary['total'] ?></a><?php endif; ?>
  </div>
  <div class="ratings__grid <?= $summary['total'] ? '' : 'ratings__grid--empty' ?>">
    <?php if ($summary['total']): ?>
    <div class="ratings__summary">
      <?php $starUrl = fn(int $st) => url('reviews.php', ['book' => $book['id'], 'stars' => $st]); $activeStars = 0; include __DIR__ . '/includes/partials/rating_summary.php'; ?>
    </div>
    <?php endif; ?>
    <div class="ratings__main">
      <?php $returnTo = 'book'; include __DIR__ . '/includes/partials/review_prompt.php'; ?>
      <?php if ($topReviews): ?>
        <ul class="review-list">
          <?php foreach ($topReviews as $rv): $canModerate = !$isMember; $bookId = (int)$book['id']; include __DIR__ . '/includes/partials/review_item.php'; endforeach; ?>
        </ul>
      <?php elseif (!$summary['total']): ?>
        <p class="rate-note small muted">No ratings yet<?= $canReview ? '. Yours would be the first.' : '.' ?></p>
      <?php endif; ?>
      <?php if ($summary['total'] > count($topReviews) + ($myReview ? 1 : 0)): ?>
        <a class="btn btn--outline ratings__all" href="<?= e(url('reviews.php', ['book' => $book['id']])) ?>">See all <?= plural((int)$summary['total'], 'review') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php if ($canReview) { $returnTo = 'book'; $asDialog = true; $presetRating = null; include __DIR__ . '/includes/partials/review_composer.php'; } ?>

<?php if ($related && $isMember): ?>
<section class="stack" style="gap:20px;margin-top:16px">
  <div class="section-title m-section__head"><h2><?= e($relatedTitle) ?></h2><?php if (str_starts_with($relatedTitle, 'More in ')): ?><a class="link small" href="<?= e(url('discover.php', ['category' => $book['category']])) ?>">See all</a><?php endif; ?></div>
  <div class="shelf-row">
    <?php foreach ($related as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
