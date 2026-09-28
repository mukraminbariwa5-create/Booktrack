<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');
$today = today();

$issueErrors = [];
if (is_post()) {
    verify_csrf();
    $action = input('action');
    try {
        if ($action === 'return') {
            complete_return(input_int('loan_id'));
            flash('success', 'Book checked in and back on the shelf.');
        } elseif ($action === 'issue') {
            $due = input('due_at');
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new DomainException('Enter a valid due date.');
            create_loan(input_int('user_id'), input_int('book_id'), $due ?: null, input('override') !== '1');
            flash('success', 'Book issued.');
        } elseif ($action === 'delete') {
            $loan = find_loan(input_int('loan_id'));
            if ($loan) {
                q('DELETE FROM loans WHERE id = ?', [$loan['id']]);
                log_activity('loan.deleted', "“{$loan['title']}” / {$loan['member_name']}");
                flash('success', 'Loan record deleted.');
            }
        }
    } catch (DomainException $ex) {
        if ($action === 'issue') {
            $issueErrors[] = $ex->getMessage();
        } else {
            flash('error', $ex->getMessage());
        }
    }
    if (!$issueErrors) back('admin/loans.php');
}

$tabs = [
    'active'   => ['On loan', "l.status IN ('borrowed','return_requested')"],
    'requests' => ['Return requests', "l.status = 'return_requested'"],
    'overdue'  => ['Overdue', "l.status = 'borrowed' AND l.due_at < ?"],
    'history'  => ['Returned', "l.status = 'returned'"],
];
$tab = array_key_exists(input('tab'), $tabs) ? input('tab') : 'active';
$counts = [];
foreach ($tabs as $k => [$label, $cond]) {
    $counts[$k] = (int)q_val("SELECT COUNT(*) FROM loans l WHERE $cond", str_contains($cond, '?') ? [$today] : []);
}

$where = [$tabs[$tab][1]];
$params = str_contains($tabs[$tab][1], '?') ? [$today] : [];
$search = input('q');
if ($search !== '') {
    $where[] = '(b.title LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$from = 'FROM loans l JOIN books b ON b.id = l.book_id JOIN users u ON u.id = l.user_id';
$p = paginate((int)q_val("SELECT COUNT(*) $from $whereSql", $params), 15);
$order = $tab === 'history' ? 'l.returned_at DESC, l.id DESC' : "(l.status = 'return_requested') DESC, l.due_at ASC";
$loans = q_all("SELECT l.*, b.title, b.author, b.cover, u.name AS member_name, u.username $from $whereSql ORDER BY $order LIMIT {$p['per']} OFFSET {$p['offset']}", $params);

// data for the "Issue a book" dialog
$members = q_all("SELECT id, name, username FROM users WHERE role = 'member' AND is_active = 1 ORDER BY name");
$availableBooks = q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . ' WHERE (b.copies - COALESCE(al.active, 0)) > 0 ORDER BY b.title');

$title = 'Circulation';
$active = 'loans';
require __DIR__ . '/../includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Circulation</h1>
    <p>Issue books, check in returns and follow up on overdue loans.</p>
  </div>
  <button class="btn" type="button" data-open="issue-dialog" data-reset><?= icon('plus') ?> Issue a book</button>
</header>

<section class="card card--flush">
  <nav class="tabs" style="padding:0 12px" aria-label="Loan filters">
    <?php foreach ($tabs as $k => [$label]): ?>
      <a href="<?= e(url('admin/loans.php', array_filter(['tab' => $k, 'q' => $search]))) ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="toolbar" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label class="search" style="flex:1;max-width:420px"><?= icon('search') ?><span class="sr-only">Search loans</span>
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by book or member"></label>
    <?php if ($search !== ''): ?><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/loans.php', ['tab' => $tab])) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($loans): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Book</th><th>Member</th><th>Borrowed</th><th><?= $tab === 'history' ? 'Returned' : 'Due' ?></th><th>Status</th><th class="num">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($loans as $l): ?>
        <tr>
          <td><a class="cell-book" href="<?= e(url('book.php', ['id' => $l['book_id']])) ?>"><?= cover_html($l, 'cover-sm') ?><span><strong><?= e($l['title']) ?></strong><span class="muted small"><?= e($l['author']) ?></span></span></a></td>
          <td><div class="cell-person"><span class="avatar"><?= e(initials($l['member_name'])) ?></span><span><?= e($l['member_name']) ?><br><span class="small muted">@<?= e($l['username']) ?></span></span></div></td>
          <td class="nowrap"><?= e(fmt_date($l['borrowed_at'])) ?></td>
          <td class="nowrap">
            <?php if ($tab === 'history'): ?><?= e(fmt_date($l['returned_at'])) ?>
              <?php if ($l['returned_at'] > $l['due_at']): ?><br><span class="small" style="color:var(--gold-ink)">Late</span><?php endif; ?>
            <?php else: ?><?= e(fmt_date($l['due_at'])) ?><br><span class="small <?= loan_state($l) === 'overdue' ? '' : 'muted' ?>" style="<?= loan_state($l) === 'overdue' ? 'color:var(--red)' : '' ?>"><?= e(due_label($l['due_at'])) ?></span><?php endif; ?>
          </td>
          <td><?= loan_badge($l) ?></td>
          <td>
            <div class="actions">
              <?php if ($l['status'] !== 'returned'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="loan_id" value="<?= (int)$l['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit"><?= icon('check') ?> Check in</button></form>
              <?php else: ?>
                <form method="post" data-confirm="This removes the loan from the member's reading history and the statistics." data-confirm-title="Delete this loan record?" data-confirm-ok="Delete">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="loan_id" value="<?= (int)$l['id'] ?>">
                  <button class="btn btn--ghost btn--sm btn--icon" type="submit" aria-label="Delete loan record" title="Delete record"><?= icon('trash') ?></button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
  <?php else: ?>
    <div class="empty"><?= icon($tab === 'overdue' ? 'check' : 'swap') ?>
      <h3><?= $search !== '' ? 'No matching loans' : ['active' => 'No books on loan', 'requests' => 'No return requests', 'overdue' => 'Nothing overdue', 'history' => 'No returns yet'][$tab] ?></h3>
      <p><?= $tab === 'overdue' ? 'Every loan is within its due date.' : 'Loans will appear here as members borrow and return books.' ?></p></div>
  <?php endif; ?>
</section>

<dialog class="modal" id="issue-dialog" <?= ($issueErrors || input('issue') === '1') ? 'data-autoopen' : '' ?> aria-labelledby="issue-h">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="issue">
    <div class="modal__head"><h2 id="issue-h">Issue a book</h2><button class="btn btn--ghost btn--icon btn--sm" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal__body">
      <?php if ($issueErrors): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $issueErrors)) ?></span></div><?php endif; ?>
      <div class="field"><label for="issue-member">Member</label>
        <select class="select" id="issue-member" name="user_id" required>
          <option value="">Choose a member…</option>
          <?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>" <?= input_int('user_id') === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (@<?= e($m['username']) ?>)</option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="issue-book">Book</label>
        <select class="select" id="issue-book" name="book_id" required>
          <option value="">Choose an available book…</option>
          <?php foreach ($availableBooks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= input_int('book_id') === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['title']) ?> — <?= e($b['author']) ?> (<?= (int)$b['available'] ?> left)</option><?php endforeach; ?>
        </select></div>
      <div class="form-grid">
        <div class="field"><label for="issue-due">Due date</label>
          <input class="input" type="date" id="issue-due" name="due_at" min="<?= e($today) ?>" value="<?= e(input('due_at') ?: date('Y-m-d', strtotime('+' . (int)config('library.loan_days') . ' days'))) ?>"></div>
        <div class="field" style="justify-content:flex-end"><label class="row small" style="gap:8px;font-weight:500;min-height:44px"><input type="checkbox" name="override" value="1"> Skip the member's loan limit</label></div>
      </div>
    </div>
    <div class="modal__foot"><button class="btn btn--ghost" type="button" data-close>Cancel</button><button class="btn" type="submit">Issue book</button></div>
  </form>
</dialog>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
