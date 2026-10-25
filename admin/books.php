<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

$formErrors = [];
$formData = null;
if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'save') {
        $id = input_int('id');
        $data = [
            'title' => input('title'),
            'author' => input('author'),
            'category' => input('category'),
            'isbn' => input('isbn'),
            'published_year' => input('published_year'),
            'copies' => input('copies', '1'),
            'description' => input('description'),
        ];
        if ($data['title'] === '') $formErrors[] = 'Title is required.';
        if ($data['author'] === '') $formErrors[] = 'Author is required.';
        array_push($formErrors, ...length_errors($data, [
            'title' => [200, 'Title'], 'author' => [160, 'Author'], 'category' => [80, 'Category'],
            'isbn' => [20, 'ISBN'], 'description' => [5000, 'Description'],
        ]));
        if (!ctype_digit($data['copies']) || (int)$data['copies'] < 1 || (int)$data['copies'] > 999) $formErrors[] = 'Copies must be a number from 1 to 999.';
        if ($data['published_year'] !== '' && (!preg_match('/^-?\d{1,4}$/', $data['published_year']) || (int)$data['published_year'] > (int)date('Y') + 1)) $formErrors[] = 'Enter a valid year.';

        $existing = $id ? q_one('SELECT * FROM books WHERE id = ?', [$id]) : null;
        if ($id && !$existing) $formErrors[] = 'That book no longer exists.';
        if (!$formErrors && q_val('SELECT COUNT(*) FROM books WHERE title = ? AND author = ? AND id <> ?', [$data['title'], $data['author'], $id])) {
            $formErrors[] = 'A book with this title and author already exists.';
        }
        if (!$formErrors && $existing) {
            $onLoan = (int)q_val('SELECT COUNT(*) FROM loans WHERE book_id = ? AND status IN ' . ACTIVE_LOAN, [$id]);
            if ((int)$data['copies'] < $onLoan) $formErrors[] = "$onLoan copies are on loan right now, so copies can't be lower than that.";
        }

        $cover = $pdf = null;
        if (!$formErrors) {
            try {
                $cover = store_cover('cover_file');
                $pdf = store_pdf('pdf_file');
            } catch (RuntimeException $ex) {
                delete_upload($cover);
                $cover = null;
                $formErrors[] = $ex->getMessage();
            }
        }

        // No cover uploaded: use the one "Find cover" picked, or look one up for
        // a new book / a book that has none (unless the librarian just removed it).
        $coverNote = '';
        if (!$formErrors && !$cover) {
            $picked = input('cover_url');
            $hasCover = $existing && $existing['cover'] && input('remove_cover') !== '1';
            if ($picked !== '' && ($cover = store_cover_from_url($picked))) {
                $coverNote = ' The cover from Open Library was added.';
            } elseif (!$hasCover && input('remove_cover') !== '1' && config('covers.auto_fetch') && ($cover = fetch_cover($data))) {
                $coverNote = ' Found its cover on Open Library.';
            }
        }

        if (!$formErrors) {
            $vals = [
                $data['title'], $data['author'], $data['category'] ?: null, $data['isbn'] ?: null,
                $data['published_year'] !== '' ? (int)$data['published_year'] : null, (int)$data['copies'], $data['description'] ?: null,
            ];
            if ($existing) {
                $newCover = $cover ?? (input('remove_cover') === '1' ? null : $existing['cover']);
                $newPdf = $pdf ?? (input('remove_pdf') === '1' ? null : $existing['pdf']);
                q('UPDATE books SET title = ?, author = ?, category = ?, isbn = ?, published_year = ?, copies = ?, description = ?, cover = ?, pdf = ? WHERE id = ?',
                    [...$vals, $newCover, $newPdf, $id]);
                if ($newCover !== $existing['cover']) delete_upload($existing['cover']);
                if ($newPdf !== $existing['pdf']) delete_upload($existing['pdf']);
                log_activity('book.updated', "“{$data['title']}”");
                flash('success', '“' . $data['title'] . '” was updated.' . $coverNote);
            } else {
                q('INSERT INTO books (title, author, category, isbn, published_year, copies, description, cover, pdf, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$vals, $cover, $pdf, date('Y-m-d H:i:s')]);
                log_activity('book.created', "“{$data['title']}”");
                flash('success', '“' . $data['title'] . '” was added to the catalogue.' . $coverNote);
            }
            back('admin/books.php');
        }
        $formData = $data + ['id' => $id ?: ''];
    }

    // Look up every book that still has no cover (a batch at a time so a slow
    // connection can't time the page out).
    if ($action === 'fill_covers') {
        @set_time_limit(180);
        $missing = q_all("SELECT id, title, author, isbn FROM books WHERE cover IS NULL OR cover = '' ORDER BY id LIMIT 40");
        $found = 0;
        foreach ($missing as $m) {
            if ($path = fetch_cover($m)) {
                q('UPDATE books SET cover = ? WHERE id = ?', [$path, $m['id']]);
                $found++;
            }
        }
        if ($found) log_activity('book.covers', plural($found, 'cover') . ' from Open Library');
        if (!$missing) flash('success', 'Every book already has a cover.');
        elseif ($found) flash('success', 'Found covers for ' . $found . ' of ' . plural(count($missing), 'book') . '.' . ($found < count($missing) ? ' The rest keep their designed covers.' : ''));
        else flash('error', 'No covers found. Check the internet connection, or add an ISBN to help the search.');
        back('admin/books.php');
    }

    if ($action === 'delete') {
        $book = q_one('SELECT * FROM books WHERE id = ?', [input_int('id')]);
        if ($book) {
            $onLoan = (int)q_val('SELECT COUNT(*) FROM loans WHERE book_id = ? AND status IN ' . ACTIVE_LOAN, [$book['id']]);
            if ($onLoan) {
                flash('error', "“{$book['title']}” can't be deleted while it's on loan.");
            } else {
                q('DELETE FROM books WHERE id = ?', [$book['id']]);
                delete_upload($book['cover']);
                delete_upload($book['pdf']);
                log_activity('book.deleted', "“{$book['title']}”");
                flash('success', '“' . $book['title'] . '” was deleted.');
            }
        }
        back('admin/books.php');
    }
}

// ---- list
$search = input('q');
$category = input('category');
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($category !== '') { $where[] = 'b.category = ?'; $params[] = $category; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$p = paginate((int)q_val('SELECT COUNT(*) FROM books b ' . $whereSql, $params), 15);
$books = q_all('SELECT ' . book_columns() . ', (SELECT COUNT(*) FROM loans lx WHERE lx.book_id = b.id) AS times FROM ' . books_from()
    . " $whereSql ORDER BY b.title LIMIT {$p['per']} OFFSET {$p['offset']}", $params);
$cats = categories();

// open the editor straight away for ?edit=ID or ?new=1
$autoEdit = null;
if (!$formData && input_int('edit')) {
    $autoEdit = q_one('SELECT id, title, author, category, isbn, published_year, copies, description, cover, pdf FROM books WHERE id = ?', [input_int('edit')]);
}

// What's currently attached to a book, so the editor can show it instead of an empty file picker.
$attachments = function (?array $b): array {
    $coverOk = $b && $b['cover'] && is_file(APP_ROOT . '/' . $b['cover']);
    $pdfPath = $b && $b['pdf'] ? APP_ROOT . '/' . $b['pdf'] : null;
    $pdfOk = $pdfPath && is_file($pdfPath);
    return [
        '_cover_url' => $coverOk ? url($b['cover']) : '',
        '_pdf_url'   => $pdfOk ? url('read.php', ['id' => $b['id']]) : '',
        '_pdf_size'  => $pdfOk ? format_bytes((int)filesize($pdfPath)) : '',
    ];
};
$bookJson = fn(array $b) => json_encode([
    'id' => $b['id'], 'title' => $b['title'], 'author' => $b['author'], 'category' => $b['category'], 'isbn' => $b['isbn'],
    'published_year' => $b['published_year'], 'copies' => $b['copies'], 'description' => $b['description'],
] + $attachments($b), JSON_UNESCAPED_UNICODE);

$missingCovers = (int)q_val("SELECT COUNT(*) FROM books WHERE cover IS NULL OR cover = ''");

$title = 'Books';
$active = 'books';
require __DIR__ . '/../includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Books</h1>
    <p><?= plural($p['total'], 'title') ?> in the catalogue<?= $search !== '' || $category !== '' ? ' match your filters' : '' ?>.</p>
  </div>
  <?php if ($missingCovers): ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="fill_covers">
      <button class="btn btn--outline" type="submit" data-busy="Finding covers…" title="Look up real covers on Open Library for books that have none"><?= icon('image') ?> Find <?= $missingCovers ?> missing <?= $missingCovers === 1 ? 'cover' : 'covers' ?></button>
    </form>
  <?php endif; ?>
  <a class="btn btn--outline" href="<?= e(url('admin/export.php', array_filter(['type' => 'books', 'q' => $search, 'category' => $category]))) ?>" title="Download this list as a spreadsheet (CSV)" download><?= icon('download') ?> Export</a>
  <button class="btn" type="button" data-open="book-dialog" data-reset><?= icon('plus') ?> Add book</button>
</header>

<section class="card card--flush">
  <form class="toolbar" method="get">
    <label class="search" style="flex:1;max-width:420px"><?= icon('search') ?><span class="sr-only">Search books</span>
      <input type="search" name="q" autocomplete="off" spellcheck="false" value="<?= e($search) ?>" placeholder="Search title, author or ISBN"></label>
    <label class="sr-only" for="cat">Category</label>
    <select class="select" id="cat" name="category" data-autosubmit style="width:auto">
      <option value="">All categories</option>
      <?php foreach ($cats as $c => $n): ?><option value="<?= e($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= e($c) ?> (<?= (int)$n ?>)</option><?php endforeach; ?>
    </select>
    <?php if ($search !== '' || $category !== ''): ?><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/books.php')) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($books): ?>
  <div class="table-wrap">
    <table class="table table--stack table--books">
      <thead><tr><th>Title</th><th>Category</th><th class="num">Copies</th><th>Availability</th><th class="num">Loans</th><th class="num">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($books as $b): ?>
        <tr>
          <td><a class="cell-book" href="<?= e(url('book.php', ['id' => $b['id']])) ?>"><?= cover_html($b, 'cover-sm') ?><span><strong><?= e($b['title']) ?></strong><span class="muted small"><?= e($b['author']) ?><?= $b['published_year'] ? ', ' . e($b['published_year']) : '' ?></span>
            <span class="book-row-meta show-sm"><?= e(implode(', ', array_filter([$b['category'], plural((int)$b['copies'], 'copy', 'copies')]))) ?>,
              <span class="<?= (int)$b['available'] > 0 ? 'is-ok' : 'is-out' ?>"><?= (int)$b['available'] > 0 ? (int)$b['available'] . ' available' : 'all on loan' ?></span></span></span></a></td>
          <td class="hide-sm"><?= $b['category'] ? '<span class="badge badge--muted badge--plain">' . e($b['category']) . '</span>' : '<span class="muted">—</span>' ?></td>
          <td class="num hide-sm"><?= (int)$b['copies'] ?></td>
          <td class="hide-sm"><?= (int)$b['available'] > 0 ? '<span class="badge badge--ok">' . (int)$b['available'] . ' available</span>' : '<span class="badge badge--warn">All on loan</span>' ?>
            <?php if ($b['pdf']): ?><span class="badge badge--info badge--plain" title="Has an e-book PDF">PDF</span><?php endif; ?></td>
          <td class="num hide-sm"><?= (int)$b['times'] ?></td>
          <td>
            <div class="actions">
              <button class="btn btn--ghost btn--sm btn--icon" type="button" data-open="book-dialog" data-fill="<?= e($bookJson($b)) ?>" aria-label="Edit <?= e($b['title']) ?>" title="Edit"><?= icon('edit') ?></button>
              <form method="post" data-confirm="“<?= e($b['title']) ?>” and its loan history will be permanently removed." data-confirm-title="Delete this book?" data-confirm-ok="Delete book">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn--ghost btn--sm btn--icon" type="submit" aria-label="Delete <?= e($b['title']) ?>" title="Delete"><?= icon('trash') ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
  <?php else: ?>
    <div class="empty"><?= empty_art('shelf') ?><h3>No books found</h3><p><?= $search !== '' || $category !== '' ? 'Try another search or category.' : 'Add your first book to start the catalogue.' ?></p></div>
  <?php endif; ?>
</section>

<?php
$f = $formData ?? ($autoEdit ? $autoEdit : null);
$isEdit = $f && !empty($f['id']);
$current = $attachments($isEdit ? q_one('SELECT id, cover, pdf FROM books WHERE id = ?', [(int)$f['id']]) : null);
$open = $formErrors || $autoEdit || input('new') === '1';
?>
<dialog class="modal" id="book-dialog" <?= $open ? 'data-autoopen' : '' ?> aria-labelledby="book-h" style="width:min(720px, calc(100vw - 32px))">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($f['id'] ?? '') ?>">
    <div class="modal__head"><h2 id="book-h" data-title-edit="Edit book" data-title-create="Add a book"><?= $isEdit ? 'Edit book' : 'Add a book' ?></h2>
      <button class="btn btn--ghost btn--icon btn--sm" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal__body">
      <?php if ($formErrors): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $formErrors)) ?></span></div><?php endif; ?>
      <div class="form-grid">
        <div class="field span-2"><label for="b-title">Title</label><input class="input" id="b-title" name="title" required maxlength="200" value="<?= e($f['title'] ?? '') ?>"></div>
        <div class="field"><label for="b-author">Author</label><input class="input" id="b-author" name="author" required maxlength="160" value="<?= e($f['author'] ?? '') ?>"></div>
        <div class="field"><label for="b-category">Category</label><input class="input" id="b-category" name="category" list="cat-list" maxlength="80" value="<?= e($f['category'] ?? '') ?>" placeholder="e.g. Fiction">
          <datalist id="cat-list"><?php foreach ($cats as $c => $n): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
        <div class="span-2 form-row-3">
          <div class="field"><label for="b-year">Published year</label><input class="input" id="b-year" name="published_year" inputmode="numeric" maxlength="5" value="<?= e($f['published_year'] ?? '') ?>"></div>
          <div class="field"><label for="b-isbn">ISBN <span class="muted">(optional)</span></label><input class="input" id="b-isbn" name="isbn" maxlength="20" value="<?= e($f['isbn'] ?? '') ?>"></div>
          <div class="field"><label for="b-copies">Copies</label><input class="input" id="b-copies" name="copies" type="number" min="1" max="999" required value="<?= e($f['copies'] ?? '1') ?>"></div>
        </div>
        <div class="field span-2"><label for="b-desc">Description</label><textarea class="textarea" id="b-desc" name="description" rows="4" maxlength="5000"><?= e($f['description'] ?? '') ?></textarea></div>
        <div class="field file-field" data-file-field="cover" data-api-cover="<?= e(url('api/cover.php')) ?>" data-has="<?= $current['_cover_url'] ? '1' : '0' ?>">
          <span class="label" id="b-cover-label">Cover image</span>
          <div class="file-card">
            <img class="file-card__thumb" data-file-thumb src="<?= e($current['_cover_url']) ?>" alt="" <?= $current['_cover_url'] ? '' : 'hidden' ?>>
            <span class="file-card__thumb file-card__thumb--empty" data-file-empty <?= $current['_cover_url'] ? 'hidden' : '' ?>><?= icon('books') ?></span>
            <div class="file-card__body">
              <strong data-file-status><?= $current['_cover_url'] ? 'Cover uploaded' : 'No cover yet' ?></strong>
              <span class="small muted" data-file-note><?= $current['_cover_url'] ? 'This is the cover readers see.' : 'Without one, the book gets a styled cover.' ?></span>
              <div class="file-card__actions">
                <label class="btn btn--outline btn--sm file-pick">
                  <input class="sr-only" id="b-cover" name="cover_file" type="file" accept="image/jpeg,image/png,image/webp" aria-labelledby="b-cover-label" data-file-input>
                  <span data-file-pick-label><?= $current['_cover_url'] ? 'Replace' : 'Upload cover' ?></span>
                </label>
                <button class="btn btn--ghost btn--sm" type="button" data-cover-find><?= icon('search') ?> Find online</button>
                <button class="btn btn--ghost btn--sm" type="button" data-file-undo hidden>Undo</button>
                <label class="file-remove" data-file-remove-wrap <?= $current['_cover_url'] ? '' : 'hidden' ?>><input type="checkbox" name="remove_cover" value="1" data-file-remove> Remove</label>
              </div>
            </div>
          </div>
          <input type="hidden" name="cover_url" data-cover-url>
          <span class="hint">JPG, PNG or WebP, up to <?= (int)config('uploads.max_cover_mb') ?> MB. “Find online” looks the book up on Open Library by ISBN or title.</span>
        </div>
        <div class="field file-field" data-file-field="pdf" data-has="<?= $current['_pdf_url'] ? '1' : '0' ?>">
          <span class="label" id="b-pdf-label">E-book PDF <span class="muted">(optional)</span></span>
          <div class="file-card">
            <span class="file-card__thumb file-card__thumb--pdf" data-file-pdf-icon><?= icon('file') ?></span>
            <div class="file-card__body">
              <strong data-file-status><?= $current['_pdf_url'] ? 'PDF attached' : 'No PDF attached' ?></strong>
              <span class="small muted" data-file-note><?php if ($current['_pdf_url']): ?><?= e($current['_pdf_size']) ?>, <a class="link" href="<?= e($current['_pdf_url']) ?>" target="_blank" rel="noopener" data-file-open>open it</a><?php else: ?>Members can read it online once added.<?php endif; ?></span>
              <div class="file-card__actions">
                <label class="btn btn--outline btn--sm file-pick">
                  <input class="sr-only" id="b-pdf" name="pdf_file" type="file" accept="application/pdf" aria-labelledby="b-pdf-label" data-file-input>
                  <span data-file-pick-label><?= $current['_pdf_url'] ? 'Replace' : 'Upload PDF' ?></span>
                </label>
                <button class="btn btn--ghost btn--sm" type="button" data-file-undo hidden>Undo</button>
                <label class="file-remove" data-file-remove-wrap <?= $current['_pdf_url'] ? '' : 'hidden' ?>><input type="checkbox" name="remove_pdf" value="1" data-file-remove> Remove</label>
              </div>
            </div>
          </div>
          <span class="hint">Only signed-in users can open it. Up to <?= (int)config('uploads.max_pdf_mb') ?> MB.</span>
        </div>
      </div>
    </div>
    <div class="modal__foot"><button class="btn btn--ghost" type="button" data-close>Cancel</button><button class="btn" type="submit">Save book</button></div>
  </form>
</dialog>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
