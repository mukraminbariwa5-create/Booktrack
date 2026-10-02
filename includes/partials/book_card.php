<?php
/** One book in a grid. Expects $book (with `available`), optional $favForm (bool). */
$avail = (int)$book['available'];
?>
<article class="book-card" data-book="<?= (int)$book['id'] ?>">
  <a class="book-card__cover" href="<?= e(url('book.php', ['id' => $book['id']])) ?>" aria-label="<?= e($book['title']) ?>">
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
  </a>
  <?php if ($avail > 0): ?>
    <span class="status"><?= (int)$book['copies'] > 1 ? $avail . ' of ' . (int)$book['copies'] . ' available' : 'Available' ?></span>
  <?php else: ?>
    <span class="status status--out">On loan</span>
  <?php endif; ?>
</article>
