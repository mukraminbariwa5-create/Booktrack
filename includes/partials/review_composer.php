<?php
/**
 * Write or edit a review. Expects $book, $myReview (or null) and $returnTo ('book' or 'list').
 * $asDialog = true renders it as a modal (a bottom sheet on phones), opened by
 * [data-open="review-dialog"]; false renders it inline (the no-JavaScript path).
 */
$r = $myReview;
$asDialog = $asDialog ?? true;
$preset = (int)($presetRating ?? ($r['rating'] ?? 0));
$heading = $r ? 'Edit your review' : 'Rate this book';
?>
<?php if ($asDialog): ?><dialog class="modal modal--sheet" id="review-dialog" aria-labelledby="review-h"><?php else: ?><section class="card review-inline" id="write" aria-labelledby="review-h"><?php endif; ?>
  <form method="post" action="<?= e(url('reviews.php', ['book' => $book['id']])) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="return" value="<?= e($returnTo) ?>">
    <div class="modal__head">
      <h2 id="review-h"><?= e($heading) ?></h2>
      <?php if ($asDialog): ?><button class="btn btn--ghost btn--icon btn--sm" type="button" data-close aria-label="Close"><?= icon('x') ?></button><?php endif; ?>
    </div>
    <div class="modal__body">
      <div class="composer__book">
        <?= cover_html($book, 'cover-sm') ?>
        <span><strong><?= e($book['title']) ?></strong><span class="small muted"><?= e($book['author']) ?></span></span>
      </div>
      <fieldset class="rate">
        <legend>Your rating</legend>
        <div class="rate__stars">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <label><input type="radio" name="rating" value="<?= $i ?>" <?= $preset === $i ? 'checked' : '' ?> required><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.8l2.5 5.3 5.8.7-4.3 4 1.1 5.7L10 14.7l-5.1 2.8 1.1-5.7-4.3-4 5.8-.7z"/></svg><span class="sr-only"><?= plural($i, 'star') ?></span></label>
          <?php endfor; ?>
        </div>
        <span class="rate__word small muted" data-rate-word aria-hidden="true"></span>
      </fieldset>
      <div class="field">
        <label for="review-body-<?= $asDialog ? 'd' : 'i' ?>">Review <span class="muted">(optional)</span></label>
        <textarea class="textarea" id="review-body-<?= $asDialog ? 'd' : 'i' ?>" name="body" rows="4" maxlength="<?= REVIEW_MAX_LENGTH ?>" placeholder="What did you like or not like? Who would enjoy it?" data-count="review-count-<?= $asDialog ? 'd' : 'i' ?>"><?= e($r['body'] ?? '') ?></textarea>
        <span class="hint" id="review-count-<?= $asDialog ? 'd' : 'i' ?>">Shown with your first name and initial. Up to <?= REVIEW_MAX_LENGTH ?> characters.</span>
      </div>
    </div>
    <div class="modal__foot">
      <?php if ($r): ?><button class="btn btn--ghost composer__delete" type="submit" form="delete-review-<?= $asDialog ? 'd' : 'i' ?>">Delete review</button><?php endif; ?>
      <?php if ($asDialog): ?><button class="btn btn--ghost" type="button" data-close>Cancel</button><?php else: ?><a class="btn btn--ghost" href="<?= e(url('reviews.php', ['book' => $book['id']])) ?>">Cancel</a><?php endif; ?>
      <button class="btn" type="submit"><?= $r ? 'Save changes' : 'Post' ?></button>
    </div>
  </form>
  <?php if ($r): ?>
    <form id="delete-review-<?= $asDialog ? 'd' : 'i' ?>" method="post" action="<?= e(url('reviews.php', ['book' => $book['id']])) ?>" data-confirm="Your rating and review of “<?= e($book['title']) ?>” will be removed." data-confirm-title="Delete your review?" data-confirm-ok="Delete review">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_review"><input type="hidden" name="return" value="<?= e($returnTo) ?>">
    </form>
  <?php endif; ?>
<?php if ($asDialog): ?></dialog><?php else: ?></section><?php endif; ?>
