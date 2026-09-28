<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');

$books = q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' JOIN favorites f ON f.book_id = b.id
                WHERE f.user_id = ? ORDER BY f.created_at DESC', [$user['id']]);

$title = 'Favorites';
$active = 'favorites';
require __DIR__ . '/includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Favorites</h1>
    <p>Books you've saved for later. <?= $books ? plural(count($books), 'book') . ' saved.' : '' ?></p>
  </div>
</header>

<?php if ($books): ?>
  <div class="book-grid">
    <?php $favForm = true; foreach ($books as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
  </div>
<?php else: ?>
  <div class="card empty">
    <?= icon('heart') ?>
    <h3>No favorites yet</h3>
    <p>Tap “Add to favorites” on any book to keep it here.</p>
    <a class="btn btn--sm" href="<?= e(url('discover.php')) ?>">Browse the catalogue</a>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
