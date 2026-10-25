<?php
/** One book in a grid. Expects $book (with `available`), optional $favForm (bool) and $heldIds (book ids the member has on loan). */
$avail = (int)$book['available'];
?>
<article class="book-card" data-book="<?= (int)$book['id'] ?>">
  <a class="book-card__cover" href="<?= e(url('book.php', ['id' => $book['id']])) ?>" tabindex="-1" aria-hidden="true">
    <?= cover_html($book) ?>
  </a>
  <?php if (!empty($favForm)): ?>
    <form method="post" action="<?= e(url('api/favorite.php')) ?>" data-favorite="remove-card">
      <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
      <button class="book-card__fav" type="submit" aria-label="Remove from favorites" title="Remove from favorites" aria-pressed="true" ><?= icon('heart') ?></button>
    </form>
  <?php endif; ?>
  <a class="book-card__meta" href="<?= e(url('book.php', ['id' => $book['id']])) ?>">
    <span class="book-card__title"><?= e($book['title']) ?></span>
    <span class="book-card__author"><?= e($book['author']) ?></span>
    <?php if (!empty($book['reviews'])): ?>
      <span class="book-card__rating" aria-label="Rated <?= e(rating_label((float)$book['rating'])) ?> out of 5 by <?= plural((int)$book['reviews'], 'member') ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.8l2.5 5.3 5.8.7-4.3 4 1.1 5.7L10 14.7l-5.1 2.8 1.1-5.7-4.3-4 5.8-.7z"/></svg><?= e(rating_label((float)$book['rating'])) ?></span>
    <?php endif; ?>
  </a>
  <?php if (!empty($heldIds) && in_array((int)$book['id'], $heldIds, true)): ?>
    <span class="status status--mine">On your loans</span>
  <?php elseif ($avail > 0): ?>
    <span class="status"><?= (int)$book['copies'] > 1 ? $avail . ' of ' . (int)$book['copies'] . ' available' : 'Available' ?></span>
  <?php else: ?>
    <span class="status status--out">On loan</span>
  <?php endif; ?>
</article>
