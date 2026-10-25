<?php
/**
 * All ratings and reviews for one book: the summary, filters by star level,
 * sorting and pages of 10, so a book with hundreds of reviews stays readable.
 * Also handles posting, editing and deleting reviews.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];
$isMember = $user['role'] === 'member';

$book = find_book(input_int('book'));
if (!$book) {
    flash('error', 'That book could not be found.');
    redirect($isMember ? 'discover.php' : 'admin/books.php');
}
$bookId = (int)$book['id'];

if (is_post()) {
    verify_csrf();
    $back = input('return') === 'list' ? url('reviews.php', ['book' => $bookId]) : url('book.php', ['id' => $bookId]) . '#reviews';
    try {
        $action = input('action');
        if ($isMember && $action === 'review') {
            $new = save_review($uid, $bookId, input_int('rating'), (string)($_POST['body'] ?? ''));
            flash('success', $new ? 'Thanks! Your review is posted.' : 'Your review is updated.');
        } elseif ($isMember && $action === 'delete_review') {
            $mine = member_review($uid, $bookId);
            if ($mine) delete_review((int)$mine['id'], $uid);
            flash('success', 'Your review is deleted.');
        } elseif (!$isMember && $action === 'remove_review') {
            delete_review(input_int('review_id'), null);
            flash('success', 'Review removed.');
        }
    } catch (DomainException $ex) {
        flash('error', $ex->getMessage());
    }
    redirect($back);
}

$summary = rating_summary($bookId);
$stars = input_int('stars');
$stars = $stars >= 1 && $stars <= 5 ? $stars : 0;
$sort = array_key_exists(input('sort'), REVIEW_SORTS) ? input('sort') : 'recent';
$myReview = $isMember ? member_review($uid, $bookId) : null;
$canReview = $isMember && can_review($uid, $bookId);
$writing = $canReview && input('write') === '1';

$total = count_reviews($bookId, $stars) - ($myReview && (!$stars || (int)$myReview['rating'] === $stars) ? 1 : 0);
$p = paginate(max(0, $total), 10);
$reviews = book_reviews($bookId, $stars, $sort, $p['per'], $p['offset'], $myReview ? $uid : null);
$filterUrl = fn(array $q) => url('reviews.php', array_filter(['book' => $bookId] + $q + ['stars' => $stars ?: null, 'sort' => $sort !== 'recent' ? $sort : null]));

$title = 'Reviews of ' . $book['title'];
$active = $isMember ? 'discover' : 'books';
require __DIR__ . '/includes/partials/header.php';
?>
<a class="back-link" href="<?= e(url('book.php', ['id' => $bookId])) ?>"><?= icon('arrow-left') ?> <span>Back to the book</span></a>

<header class="page-head reviews-head">
  <div class="reviews-head__book">
    <?= cover_html($book, 'cover-sm') ?>
    <div class="page-head__title">
      <h1>Ratings and reviews</h1>
      <p><?= e($book['title']) ?>, by <?= e($book['author']) ?></p>
    </div>
  </div>
</header>

<div class="reviews-layout">
  <aside class="reviews-layout__side stack">
    <?php if ($summary['total']): $starUrl = fn(int $s) => $filterUrl(['stars' => $s, 'page' => null]); $activeStars = $stars; include __DIR__ . '/includes/partials/rating_summary.php'; endif; ?>
    <?php if (!$writing): $returnTo = 'list'; include __DIR__ . '/includes/partials/review_prompt.php'; endif; ?>
  </aside>

  <section class="reviews-layout__main stack" aria-labelledby="list-h">
    <?php if ($writing): $returnTo = 'list'; $asDialog = false; $presetRating = input_int('rating') ?: null; include __DIR__ . '/includes/partials/review_composer.php'; endif; ?>

    <h2 id="list-h" class="sr-only">Reviews</h2>
    <?php if ($summary['total']): ?>
    <div class="reviews-toolbar">
      <nav class="chips chips--scroll" aria-label="Filter by rating">
        <a class="chip" href="<?= e($filterUrl(['stars' => null, 'page' => null])) ?>" aria-current="<?= $stars ? 'false' : 'true' ?>">All <small><?= $summary['total'] ?></small></a>
        <?php foreach ($summary['counts'] as $s => $n): if (!$n) continue; ?>
          <a class="chip" href="<?= e($filterUrl(['stars' => $s, 'page' => null])) ?>" aria-current="<?= $stars === $s ? 'true' : 'false' ?>"><?= $s ?> <span aria-hidden="true">★</span><span class="sr-only"><?= $s === 1 ? 'star' : 'stars' ?></span> <small><?= $n ?></small></a>
        <?php endforeach; ?>
      </nav>
      <form method="get" class="reviews-toolbar__sort">
        <input type="hidden" name="book" value="<?= $bookId ?>"><?php if ($stars): ?><input type="hidden" name="stars" value="<?= $stars ?>"><?php endif; ?>
        <label class="sr-only" for="sort">Sort reviews</label>
        <select class="select select--pill" id="sort" name="sort" data-autosubmit>
          <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Most recent</option>
          <option value="high" <?= $sort === 'high' ? 'selected' : '' ?>>Highest rated</option>
          <option value="low" <?= $sort === 'low' ? 'selected' : '' ?>>Lowest rated</option>
        </select>
        <noscript><button class="btn btn--ghost btn--sm">Sort</button></noscript>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($reviews): ?>
      <ul class="review-list">
        <?php foreach ($reviews as $rv): $canModerate = !$isMember; $returnTo = 'list'; include __DIR__ . '/includes/partials/review_item.php'; endforeach; ?>
      </ul>
      <?= pagination_links($p) ?>
    <?php else: ?>
      <div class="empty">
        <?= empty_art('heart') ?>
        <h3><?= $stars ? 'No ' . plural($stars, 'star') . ' reviews' : 'No reviews yet' ?></h3>
        <p><?= $stars ? 'Try another rating.' : 'Members who borrow this book can rate it here.' ?></p>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($canReview && !$writing): $returnTo = 'list'; $asDialog = true; $presetRating = null; include __DIR__ . '/includes/partials/review_composer.php'; endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
