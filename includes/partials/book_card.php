<?php
/** One book in a grid. Expects $book (with `available`), optional $favForm (bool). */
$avail = (int)$book['available'];
?>
<article class="book-card">
  <a class="book-card__cover" href="<?= e(url('book.php', ['id' => $book['id']])) ?>" aria-label="<?= e($book['title']) ?>">
    <?= cover_html($book) ?>
  </a>
  <?php if (!empty($favForm)): ?>
    <form method="post" action="<?= e(url('api/favorite.php')) ?>" data-favorite="remove-card">
      <?= csrf_field() ?><input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
      <button class="book-card__fav" type="submit" aria-label="Remove from favorites" title="Remove from favorites" aria-pressed="true" style="border:0;cursor:pointer"><?= icon('heart') ?></button>
    </form>
  <?php endif; ?>
  <a class="book-card__meta" href="<?= e(url('book.php', ['id' => $book['id']])) ?>">
    <span class="book-card__title"><?= e($book['title']) ?></span>
    <span class="book-card__author"><?= e($book['author']) ?></span>
  </a>
  <?php if ($avail > 0): ?>
    <span class="badge badge--ok">Available<?= (int)$book['copies'] > 1 ? ' · ' . $avail . ' of ' . (int)$book['copies'] : '' ?></span>
  <?php else: ?>
    <span class="badge badge--warn">On loan</span>
  <?php endif; ?>
</article>
