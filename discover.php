<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');

$search   = input('q');
$category = input('category');
$sort     = input('sort', 'new');
$onlyAvailable = input('available') === '1';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.category LIKE ? OR b.isbn LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($category !== '') {
    $where[] = 'b.category = ?';
    $params[] = $category;
}
if ($onlyAvailable) $where[] = '(b.copies - COALESCE(al.active, 0)) > 0';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orders = ['new' => 'b.created_at DESC, b.id DESC', 'title' => 'b.title ASC', 'author' => 'b.author ASC, b.title ASC'];
$orderSql = $orders[$sort] ?? $orders['new'];

$total = (int)q_val('SELECT COUNT(*) FROM ' . books_from() . " $whereSql", $params);
$p = paginate($total, 18);
$books = q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . " $whereSql ORDER BY $orderSql LIMIT {$p['per']} OFFSET {$p['offset']}", $params);

$loans = member_active_loans((int)$user['id']);
$cats = categories();
$filtering = $search !== '' || $category !== '' || $onlyAvailable;

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', $user['name'])[0];

$title = 'Discover';
$active = 'discover';
require __DIR__ . '/includes/partials/header.php';

$chipUrl = fn(string $c) => url('discover.php', array_filter(['category' => $c, 'q' => $search, 'sort' => $sort !== 'new' ? $sort : '', 'available' => $onlyAvailable ? '1' : '']));
?>
<header class="page-head">
  <div class="page-head__title">
    <span class="eyebrow"><?= e(date('l, j F')) ?></span>
    <h1><?= e("$greeting, $firstName") ?></h1>
  </div>
  <form class="search" method="get" role="search" action="<?= e(url('discover.php')) ?>">
    <?= icon('search') ?>
    <label class="sr-only" for="q">Search books</label>
    <input id="q" type="search" name="q" value="<?= e($search) ?>" placeholder="Search title, author or category">
    <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
  </form>
</header>

<?php if ($loans && !$filtering && $p['page'] === 1): ?>
<section aria-labelledby="loans-h" class="stack">
  <div class="section-title">
    <h2 id="loans-h" class="sr-only">Your loans</h2>
  </div>
  <div class="loan-list">
    <?php foreach (array_slice($loans, 0, 3) as $loan): ?>
      <?php $showActions = true; include __DIR__ . '/includes/partials/loan_card.php'; ?>
    <?php endforeach; ?>
  </div>
  <?php if (count($loans) > 3): ?><a class="link small" href="<?= e(url('loans.php')) ?>">See all <?= count($loans) ?> loans</a><?php endif; ?>
</section>
<?php endif; ?>

<section class="stack" style="gap:20px" aria-labelledby="shelf-h">
  <div class="section-title">
    <h2 id="shelf-h"><?= $search !== '' ? 'Results for “' . e($search) . '”' : ($category !== '' ? e($category) : 'On the shelves') ?></h2>
    <form method="get" class="row row--wrap" action="<?= e(url('discover.php')) ?>">
      <?php foreach (['q' => $search, 'category' => $category] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
      <label class="row small muted" style="gap:8px;cursor:pointer">
        <input type="checkbox" name="available" value="1" <?= $onlyAvailable ? 'checked' : '' ?> data-autosubmit> Available now
      </label>
      <label class="sr-only" for="sort">Sort by</label>
      <select class="select" id="sort" name="sort" data-autosubmit style="width:auto;min-height:38px;padding-top:6px;padding-bottom:6px;font-size:14px">
        <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Newest</option>
        <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title A–Z</option>
        <option value="author" <?= $sort === 'author' ? 'selected' : '' ?>>Author A–Z</option>
      </select>
      <noscript><button class="btn btn--ghost btn--sm">Apply</button></noscript>
    </form>
  </div>

  <nav class="chips" aria-label="Categories">
    <a class="chip" href="<?= e($chipUrl('')) ?>" aria-current="<?= $category === '' ? 'true' : 'false' ?>">All</a>
    <?php foreach ($cats as $c => $n): ?>
      <a class="chip" href="<?= e($chipUrl($c)) ?>" aria-current="<?= $category === $c ? 'true' : 'false' ?>"><?= e($c) ?> <small><?= (int)$n ?></small></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($books): ?>
    <div class="book-grid">
      <?php foreach ($books as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
    </div>
    <?= pagination_links($p) ?>
  <?php else: ?>
    <div class="empty card">
      <?= icon('search') ?>
      <h3>No books found</h3>
      <p>Try a different search or category.</p>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('discover.php')) ?>">Clear filters</a>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
