<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');

$search   = input('q');
$category = input('category');
$sort     = input('sort', 'new');
$onlyAvailable = input('available') === '1';
$browseAll = input('view') === 'all';

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

$orders = ['new' => 'b.created_at DESC, b.id DESC', 'title' => 'b.title ASC', 'author' => 'b.author ASC, b.title ASC',
           'rating' => 'COALESCE(rv.avg_rating, 0) DESC, COALESCE(rv.n_reviews, 0) DESC, b.title ASC'];
$orderSql = $orders[$sort] ?? $orders['new'];

$total = (int)q_val('SELECT COUNT(*) FROM ' . books_from() . " $whereSql", $params);
$p = paginate($total, 18);
$books = q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . " $whereSql ORDER BY $orderSql LIMIT {$p['per']} OFFSET {$p['offset']}", $params);

$loans = member_active_loans((int)$user['id']);
$cats = categories();
$filtering = $search !== '' || $category !== '' || $onlyAvailable || $browseAll;

// The "New on the shelf" feature: the newest book, preferring one with a free copy.
$showFeature = !$filtering && $p['page'] === 1;
$featured = $showFeature
    ? q_one('SELECT ' . book_columns() . ' FROM ' . books_from() . ' ORDER BY ((b.copies - COALESCE(al.active, 0)) > 0) DESC, b.created_at DESC, b.id DESC LIMIT 1')
    : null;
$heldIds = array_map('intval', array_column($loans, 'book_id'));
$block = borrow_block_reason((int)$user['id']);

// Home screen: swipeable rows (popular posters, new arrivals, what's on the
// shelf now, then any category big enough to fill a row) instead of one long
// grid. Choosing a category, searching or "All books" shows the grid.
$popular = $shelves = [];
if ($showFeature) {
    $popular = q_all('SELECT ' . book_columns() . ' FROM ' . books_from()
        . ' LEFT JOIN (SELECT book_id, COUNT(*) AS n FROM loans GROUP BY book_id) pop ON pop.book_id = b.id'
        . ' ORDER BY COALESCE(pop.n, 0) DESC, b.created_at DESC LIMIT 10');
    $shelves[] = ['New arrivals', url('discover.php', ['view' => 'all']),
        q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' ORDER BY b.created_at DESC, b.id DESC LIMIT 12')];
    $shelves[] = ['Available to borrow now', url('discover.php', ['available' => '1']),
        q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' WHERE (b.copies - COALESCE(al.active, 0)) > 0 ORDER BY b.title LIMIT 12')];
    $byCount = array_filter($cats, fn($n) => $n >= 4);
    arsort($byCount);
    foreach (array_slice(array_keys($byCount), 0, 6) as $c) {
        $shelves[] = [$c, url('discover.php', ['category' => $c]),
            q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' WHERE b.category = ? ORDER BY b.created_at DESC, b.id DESC LIMIT 12', [$c])];
    }
}

$title = 'Discover';
$active = 'discover';
require __DIR__ . '/includes/partials/header.php';

$chipUrl = fn(string $c) => url('discover.php', array_filter(['category' => $c, 'view' => $c === '' ? 'all' : '', 'q' => $search, 'sort' => $sort !== 'new' ? $sort : '', 'available' => $onlyAvailable ? '1' : '']));
?>
<header class="page-head page-head--discover">
  <div class="page-head__title">
    <h1><?= $search !== '' ? 'Results for “' . e($search) . '”' : ($category !== '' ? e($category) : ($browseAll ? 'All books' : 'Discover')) ?></h1>
    <p><?= $filtering ? ($p['total'] ? plural($p['total'], 'book') : 'No books') . ($onlyAvailable ? ', available now' : '') . '.' : 'Browse the catalogue, borrow a book and keep track of your loans.' ?></p>
  </div>
  <form class="search" method="get" role="search" action="<?= e(url('discover.php')) ?>">
    <?= icon('search') ?>
    <label class="sr-only" for="q">Search the catalogue</label>
    <input id="q" type="search" name="q" autocomplete="off" spellcheck="false" value="<?= e($search) ?>" placeholder="Search title, author or category" data-suggest role="combobox" aria-expanded="false" aria-controls="q-suggest" aria-autocomplete="list">
    <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
    <kbd class="kbd" aria-hidden="true">/</kbd>
    <div class="suggest" id="q-suggest" role="listbox" aria-label="Suggestions" hidden></div>
  </form>
  <?php if ($filtering && $books): ?><a class="btn btn--ghost" href="<?= e(url('discover.php')) ?>"><?= $browseAll && $search === '' && $category === '' && !$onlyAvailable ? 'Back to Discover' : 'Clear filters' ?></a><?php endif; ?>
</header>

<?php if ($block && $block['code'] === 'overdue'): ?>
<a class="alert alert--error" href="<?= e(url('loans.php')) ?>" style="text-decoration:none">
  <?= icon('clock') ?><span><strong>Borrowing is paused.</strong> <?= e($block['message']) ?> <span class="link">Go to My loans</span></span>
</a>
<?php endif; ?>

<?php if ($featured): $fid = (int)$featured['id']; ?>
<section class="feature hide-mobile" aria-labelledby="feature-h" data-book="<?= $fid ?>">
  <?= cover_ambient($featured) ?>
  <a class="feature__cover" data-tilt href="<?= e(url('book.php', ['id' => $fid])) ?>" tabindex="-1" aria-hidden="true"><?= cover_html($featured) ?></a>
  <div class="feature__body">
    <span class="feature__kicker">New on the shelf</span>
    <h2 id="feature-h"><a href="<?= e(url('book.php', ['id' => $fid])) ?>"><?= e($featured['title']) ?></a></h2>
    <p><?= e($featured['author']) ?><?= $featured['category'] ? ', ' . e($featured['category']) : '' ?>. <?= e($featured['description'] ?? '') ?></p>
    <div class="feature__actions">
      <?php if (in_array($fid, $heldIds, true)): ?>
        <a class="btn btn--outline" href="<?= e(url('loans.php')) ?>">You have this book</a>
      <?php elseif ((int)$featured['available'] > 0 && $block): ?>
        <?php /* the banner above explains why borrowing is paused */ ?>
      <?php elseif ((int)$featured['available'] > 0): ?>
        <form method="post" action="<?= e(url('book.php', ['id' => $fid])) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="borrow">
          <button class="btn" type="submit">Borrow for <?= (int)config('library.loan_days') ?> days</button>
        </form>
      <?php endif; ?>
      <a class="btn btn--outline" href="<?= e(url('book.php', ['id' => $fid])) ?>">View details</a>
    </div>
  </div>
  <div class="feature__side">
    <h2>Your loans</h2>
    <?php if ($loans): ?>
      <?php foreach (array_slice($loans, 0, 3) as $loan):
        $d = days_until($loan['due_at']);
        $tone = $loan['status'] === 'return_requested' ? '' : ($d < 0 ? 'is-late' : ($d <= (int)config('library.due_soon_days') ? 'is-soon' : ''));
        $total = max(1, (strtotime($loan['due_at']) - strtotime($loan['borrowed_at'])) / 86400);
        $pct = (int)max(4, min(100, round((strtotime(today()) - strtotime($loan['borrowed_at'])) / 86400 / $total * 100)));
        $when = $loan['status'] === 'return_requested' ? 'Waiting for check-in' : ($d < 0 ? plural(abs($d), 'day') . ' late' : ($d === 0 ? 'Due today' : plural($d, 'day') . ' left'));
      ?>
        <div class="due">
          <div class="due__row"><a href="<?= e(url('book.php', ['id' => $loan['book_id']])) ?>"><?= e($loan['title']) ?></a><span class="due__when <?= $tone ?>"><?= e($when) ?></span></div>
          <?php if ($loan['status'] !== 'return_requested'): ?><div class="progress <?= $tone ?>" role="img" aria-label="<?= e(due_label($loan['due_at'])) ?>"><span style="width: <?= $pct ?>%"></span></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <a class="link small" href="<?= e(url('loans.php')) ?>"><?= count($loans) > 3 ? 'See all ' . count($loans) . ' loans' : 'Manage loans' ?></a>
    <?php else: ?>
      <p class="small muted" style="margin:0">Nothing on loan. You can borrow up to <?= (int)config('library.max_active_loans') ?> books at a time, each for <?= (int)config('library.loan_days') ?> days.</p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($showFeature): ?>
<div class="m-home">
  <nav class="chips chips--scroll" aria-label="Categories">
    <a class="chip" href="<?= e($chipUrl('')) ?>">All books</a>
    <?php foreach ($cats as $c => $n): ?><a class="chip" href="<?= e($chipUrl($c)) ?>" aria-current="false"><?= e($c) ?></a><?php endforeach; ?>
  </nav>

  <?php if ($popular): ?>
  <section class="m-section" aria-labelledby="pop-h">
    <div class="m-section__head"><h2 id="pop-h">Popular right now</h2></div>
    <div class="poster-row" data-row>
      <?php foreach ($popular as $rank => $b): $avail = (int)$b['available']; ?>
        <a class="poster" href="<?= e(url('book.php', ['id' => $b['id']])) ?>" data-book="<?= (int)$b['id'] ?>">
          <?= cover_ambient($b) ?>
          <?= cover_html($b, 'cover poster__cover') ?>
          <span class="poster__info">
            <span class="poster__rank" aria-hidden="true"><?= $rank + 1 ?></span>
            <strong><?= e($b['title']) ?></strong>
            <span class="poster__author"><?= e($b['author']) ?><?= $b['category'] ? ', ' . e($b['category']) : '' ?></span>
            <?php if (in_array((int)$b['id'], $heldIds, true)): ?><span class="poster__tag is-mine">On your loans</span><?php else: ?><span class="poster__tag <?= $avail ? '' : 'is-out' ?>"><?= $avail ? ((int)$b['copies'] > 1 ? $avail . ' of ' . (int)$b['copies'] . ' available' : 'Available') : 'On loan' ?></span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($loans): ?>
  <section class="m-section show-mobile" aria-labelledby="mloans-h">
    <div class="m-section__head"><h2 id="mloans-h">Your loans</h2><a href="<?= e(url('loans.php')) ?>">See all <?= icon('chevron-right') ?></a></div>
    <div class="due-row">
      <?php foreach ($loans as $loan):
        $d = days_until($loan['due_at']);
        $tone = $loan['status'] === 'return_requested' ? '' : ($d < 0 ? 'is-late' : ($d <= (int)config('library.due_soon_days') ? 'is-soon' : ''));
        $span = max(1, (strtotime($loan['due_at']) - strtotime($loan['borrowed_at'])) / 86400);
        $pct = (int)max(4, min(100, round((strtotime(today()) - strtotime($loan['borrowed_at'])) / 86400 / $span * 100)));
        $when = $loan['status'] === 'return_requested' ? 'Waiting for check-in' : ($d < 0 ? plural(abs($d), 'day') . ' late' : ($d === 0 ? 'Due today' : 'Due in ' . plural($d, 'day')));
      ?>
        <a class="due-card" href="<?= e(url('book.php', ['id' => $loan['book_id']])) ?>" data-book="<?= (int)$loan['book_id'] ?>">
          <?= cover_html(['title' => $loan['title'], 'author' => $loan['author'], 'cover' => $loan['cover']], 'cover-sm') ?>
          <span class="due-card__body">
            <strong><?= e($loan['title']) ?></strong>
            <span class="due__when <?= $tone ?>"><?= e($when) ?></span>
            <?php if ($loan['status'] !== 'return_requested'): ?><span class="progress <?= $tone ?>" aria-hidden="true"><span style="width: <?= $pct ?>%"></span></span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($shelves as [$shelfTitle, $shelfUrl, $shelfBooks]): if (count($shelfBooks) < 2) continue; ?>
  <section class="m-section" aria-label="<?= e($shelfTitle) ?>">
    <div class="m-section__head"><h2><?= e($shelfTitle) ?></h2><a href="<?= e($shelfUrl) ?>">See all <?= icon('chevron-right') ?></a></div>
    <div class="shelf-row" data-row>
      <?php foreach ($shelfBooks as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$showFeature): ?>
<section class="stack" style="gap:20px" aria-labelledby="shelf-h">
  <div class="section-title">
    <h2 id="shelf-h" class="<?= $filtering ? 'sr-only' : '' ?>"><?= $filtering ? 'Results' : 'On the shelves' ?></h2>
    <?php if ($filtering): ?><span class="spacer"></span><?php endif; ?>
    <form method="get" class="row row--wrap" action="<?= e(url('discover.php')) ?>">
      <?php foreach (['q' => $search, 'category' => $category, 'view' => $browseAll ? 'all' : ''] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
      <label class="row small" style="gap:8px;cursor:pointer;color:var(--text-2)">
        <input type="checkbox" name="available" value="1" <?= $onlyAvailable ? 'checked' : '' ?> data-autosubmit> Available now
      </label>
      <label class="sr-only" for="sort">Sort by</label>
      <select class="select" id="sort" name="sort" data-autosubmit style="width:auto;min-height:38px;padding:6px 40px 6px 16px;font-size:14px;border-radius:999px">
        <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Newest</option>
        <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title A–Z</option>
        <option value="author" <?= $sort === 'author' ? 'selected' : '' ?>>Author A–Z</option>
        <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Top rated</option>
      </select>
      <noscript><button class="btn btn--ghost btn--sm">Apply</button></noscript>
    </form>
  </div>

  <nav class="chips chips--scroll" aria-label="Categories">
    <a class="chip" href="<?= e($chipUrl('')) ?>" aria-current="<?= $category === '' ? 'true' : 'false' ?>">All books</a>
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
      <?= empty_art('search') ?>
      <h3>No books found</h3>
      <p>Try a different search or category.</p>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('discover.php')) ?>">Clear filters</a>
    </div>
    <?php
      $suggest = q_all('SELECT ' . book_columns() . ' FROM ' . books_from()
          . ' LEFT JOIN (SELECT book_id, COUNT(*) AS n FROM loans GROUP BY book_id) pop ON pop.book_id = b.id'
          . ' WHERE (b.copies - COALESCE(al.active, 0)) > 0 ORDER BY COALESCE(pop.n, 0) DESC, b.title LIMIT 8');
    ?>
    <?php if ($suggest): ?>
    <section class="stack" style="gap:16px" aria-labelledby="suggest-h">
      <div class="section-title"><h2 id="suggest-h">Popular and on the shelf now</h2></div>
      <div class="shelf-row" data-row>
        <?php foreach ($suggest as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
      </div>
    </section>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
