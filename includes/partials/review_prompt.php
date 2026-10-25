<?php
/**
 * The member's own spot in the ratings section: "Rate this book" with five
 * tappable stars, their existing review with Edit, or why they can't rate yet.
 * Expects $book, $isMember, $canReview, $myReview, $returnTo.
 * Without JavaScript the stars and Edit go to the reviews page's inline form.
 */
$writeUrl = fn(array $extra = []) => url('reviews.php', ['book' => $book['id'], 'write' => 1] + $extra) . '#write';
?>
<?php if ($isMember && $myReview): ?>
  <div class="my-review">
    <div class="my-review__head">
      <span class="my-review__label">Your review</span>
      <a class="btn btn--ghost btn--sm" href="<?= e($writeUrl()) ?>" data-open="review-dialog"><?= icon('edit') ?> Edit</a>
    </div>
    <div class="review__meta">
      <?= stars_html((float)$myReview['rating'], 'stars stars--sm') ?>
      <time datetime="<?= e(substr($myReview['updated_at'], 0, 10)) ?>"><?= e(fmt_date($myReview['updated_at'], 'j M Y')) ?></time>
    </div>
    <?php if ($myReview['body']): ?><p class="review__text" data-clamp-lines><?= nl2br(e($myReview['body'])) ?></p><?php endif; ?>
  </div>
<?php elseif ($isMember && $canReview): ?>
  <form class="rate-row" method="get" action="<?= e(url('reviews.php')) ?>#write" data-rate-start>
    <input type="hidden" name="book" value="<?= (int)$book['id'] ?>"><input type="hidden" name="write" value="1">
    <span class="rate-row__text"><strong>Rate this book</strong><span class="small muted">You borrowed it. Tell other members what you thought.</span></span>
    <span class="rate-row__stars" role="group" aria-label="Rate this book">
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <button type="submit" name="rating" value="<?= $i ?>" aria-label="<?= plural($i, 'star') ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.8l2.5 5.3 5.8.7-4.3 4 1.1 5.7L10 14.7l-5.1 2.8 1.1-5.7-4.3-4 5.8-.7z"/></svg></button>
      <?php endfor; ?>
    </span>
  </form>
<?php elseif ($isMember): ?>
  <p class="rate-note small muted">Members who've borrowed this book can rate it.</p>
<?php endif; ?>
