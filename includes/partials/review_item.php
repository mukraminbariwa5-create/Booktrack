<?php
/**
 * One review in a list. Expects $rv (review row with `name`), $canModerate (librarian),
 * $bookId and $returnTo ('book' or 'list') for the remove form.
 */
?>
<li class="review">
  <div class="review__head">
    <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials($rv['name'])) ?></span>
    <strong class="review__name"><?= e(reviewer_name($rv['name'])) ?></strong>
    <?php if (!empty($canModerate)): ?>
      <form class="review__mod" method="post" action="<?= e(url('reviews.php', ['book' => $bookId])) ?>" data-confirm="This removes <?= e(reviewer_name($rv['name'])) ?>'s rating and review." data-confirm-title="Remove this review?" data-confirm-ok="Remove">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove_review"><input type="hidden" name="review_id" value="<?= (int)$rv['id'] ?>"><input type="hidden" name="return" value="<?= e($returnTo ?? 'book') ?>">
        <button class="btn btn--ghost btn--sm" type="submit">Remove</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="review__meta">
    <?= stars_html((float)$rv['rating'], 'stars stars--sm') ?>
    <time datetime="<?= e(substr($rv['updated_at'], 0, 10)) ?>"><?= e(fmt_date($rv['updated_at'], 'j M Y')) ?></time>
  </div>
  <?php if ($rv['body']): ?>
    <p class="review__text" data-clamp-lines><?= nl2br(e($rv['body'])) ?></p>
  <?php endif; ?>
</li>
