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
            $fee = complete_return(input_int('loan_id'));
            flash('success', 'Book checked in and back on the shelf.' . ($fee > 0 ? ' It was late, so a ' . money($fee) . ' fee was added.' : ''));
        } elseif ($action === 'fine_paid' || $action === 'fine_waive') {
            $f = settle_fine(input_int('fine_id'), $action === 'fine_paid' ? 'paid' : 'waived', (int)$user['id']);
            flash('success', money((float)$f['amount']) . ' fee from ' . $f['member_name'] . ($action === 'fine_paid' ? ' marked paid.' : ' waived.'));
        } elseif ($action === 'issue') {
            $due = input('due_at');
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new DomainException('Enter a valid due date.');
            create_loan(input_int('user_id'), input_int('book_id'), $due ?: null, input('override') !== '1');
            flash('success', 'Book issued.');
        } elseif ($action === 'cancel_hold') {
            cancel_hold(input_int('hold_id'), null);
            process_hold_queue();
            flash('success', 'Hold cancelled.');
        } elseif ($action === 'remind') {
            $sent = send_due_reminders();
            $n = $sent['due_soon'] + $sent['overdue'];
            flash('success', $n
                ? 'Sent ' . plural($n, 'reminder email') . ' (' . $sent['due_soon'] . ' due soon, ' . $sent['overdue'] . ' overdue).'
                    . (config('mail.driver', 'log') === 'log' ? ' They\'re saved in storage/mail.' : '')
                : 'No reminders to send. Members already got one for every loan that\'s due soon or overdue.');
        } elseif ($action === 'delete') {
            $loan = find_loan(input_int('loan_id'));
            if ($loan) {
                q('DELETE FROM loans WHERE id = ?', [$loan['id']]);
                log_activity('loan.deleted', "Loan record of “{$loan['title']}” for {$loan['member_name']}");
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

$tabs = loan_filter_tabs();
$holdCount = (int)q_val('SELECT COUNT(*) FROM holds WHERE status IN ' . HOLD_OPEN);
$tab = array_key_exists(input('tab'), $tabs) || in_array(input('tab'), ['holds', 'fines'], true) ? input('tab') : 'active';
[$fineCount, $fineTotal] = unpaid_fines_summary();
$counts = [];
foreach ($tabs as $k => [$label, $cond]) {
    $counts[$k] = (int)q_val("SELECT COUNT(*) FROM loans l WHERE $cond", str_contains($cond, '?') ? [$today] : []);
}

$where = [$tabs[$tab][1] ?? $tabs['active'][1]];
$params = str_contains($where[0], '?') ? [$today] : [];
$search = input('q');
if ($search !== '') {
    $where[] = '(b.title LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$from = 'FROM loans l JOIN books b ON b.id = l.book_id JOIN users u ON u.id = l.user_id';
$p = paginate((int)q_val("SELECT COUNT(*) $from $whereSql", $params), 15);
$holdRows = $tab === 'holds'
    ? q_all("SELECT h.*, b.title, b.author, b.cover, u.name AS member_name, u.username FROM holds h JOIN books b ON b.id = h.book_id JOIN users u ON u.id = h.user_id
             WHERE h.status IN " . HOLD_OPEN . ($search !== '' ? ' AND (b.title LIKE ? OR u.name LIKE ? OR u.username LIKE ?)' : '')
             . " ORDER BY (h.status = 'ready') DESC, b.title, h.created_at", $search !== '' ? ["%$search%", "%$search%", "%$search%"] : [])
    : [];
$fineRows = [];
if ($tab === 'fines') {
    $fw = $search !== '' ? ' WHERE (f.book_title LIKE ? OR u.name LIKE ? OR u.username LIKE ?)' : '';
    $fp = $search !== '' ? ["%$search%", "%$search%", "%$search%"] : [];
    $fineFrom = 'FROM fines f JOIN users u ON u.id = f.user_id LEFT JOIN loans l ON l.id = f.loan_id LEFT JOIN users s ON s.id = f.settled_by';
    $p = paginate((int)q_val("SELECT COUNT(*) $fineFrom $fw", $fp), 15);
    $fineRows = q_all("SELECT f.*, u.name AS member_name, u.username, l.book_id, l.due_at, l.returned_at, s.username AS settled_by_name $fineFrom $fw
                       ORDER BY (f.status = 'unpaid') DESC, f.created_at DESC, f.id DESC LIMIT {$p['per']} OFFSET {$p['offset']}", $fp);
}
foreach ($holdRows as &$hr) $hr['position'] = $hr['status'] === 'waiting' ? hold_position($hr) : 0;
unset($hr);
$lastRun = reminders_last_run();
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
  <div class="row row--wrap">
    <form method="post" title="<?= $lastRun ? 'Last sent ' . e(fmt_date($lastRun, 'j M')) : 'Not sent yet' ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="remind">
      <button class="btn btn--outline" type="submit" data-busy="Sending…"><?= icon('send') ?> Send reminders</button>
    </form>
    <?php if ($tab !== 'holds'): ?>
      <a class="btn btn--outline" href="<?= e(url('admin/export.php', array_filter(['type' => $tab === 'fines' ? 'fines' : 'loans', 'tab' => $tab === 'fines' ? null : $tab, 'q' => $search]))) ?>" title="Download this list as a spreadsheet (CSV)" download><?= icon('download') ?> Export</a>
    <?php endif; ?>
    <button class="btn" type="button" data-open="issue-dialog" data-reset><?= icon('plus') ?> Issue a book</button>
  </div>
</header>

<section class="card card--flush">
  <nav class="tabs" style="padding:0 12px" aria-label="Loan filters">
    <?php foreach ($tabs as $k => [$label]): ?>
      <a href="<?= e(url('admin/loans.php', array_filter(['tab' => $k, 'q' => $search]))) ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
    <a href="<?= e(url('admin/loans.php', array_filter(['tab' => 'holds', 'q' => $search]))) ?>" <?= $tab === 'holds' ? 'aria-current="page"' : '' ?>>Holds <span class="count"><?= $holdCount ?></span></a>
    <?php if (fines_enabled() || $fineCount): ?>
      <a href="<?= e(url('admin/loans.php', array_filter(['tab' => 'fines', 'q' => $search]))) ?>" <?= $tab === 'fines' ? 'aria-current="page"' : '' ?>>Fines <span class="count"><?= $fineCount ?></span></a>
    <?php endif; ?>
  </nav>
  <form class="toolbar" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label class="search" style="flex:1;max-width:420px"><?= icon('search') ?><span class="sr-only">Search loans</span>
      <input type="search" name="q" autocomplete="off" spellcheck="false" value="<?= e($search) ?>" placeholder="Search by book or member"></label>
    <?php if ($search !== ''): ?><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/loans.php', ['tab' => $tab])) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($tab === 'fines'): ?>
    <?php if ($fineCount): ?><p class="small muted" style="margin:0;padding:0 20px 12px"><strong style="color:var(--text)"><?= e(money($fineTotal)) ?></strong> unpaid across <?= e(plural($fineCount, 'fee')) ?>. Members pay at the desk; waiving a fee is logged.</p><?php endif; ?>
    <?php if ($fineRows): ?>
    <div class="table-wrap">
      <table class="table table--stack">
        <thead><tr><th>Book</th><th>Member</th><th>Late by</th><th>Status</th><th class="num">Amount</th><th class="num">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($fineRows as $f): ?>
          <tr>
            <td><span class="cell-book"><span><strong><?= e($f['book_title']) ?></strong><span class="muted small">Checked in <?= e(fmt_date($f['returned_at'] ?: $f['created_at'])) ?></span></span></span></td>
            <td><div class="cell-person"><span class="avatar"><?= e(initials($f['member_name'])) ?></span><span><?= e($f['member_name']) ?><br><span class="small muted">@<?= e($f['username']) ?></span></span></div></td>
            <td class="nowrap" data-label="Late by"><?= e(plural((int)$f['days_late'], 'day')) ?></td>
            <td><?= fine_badge($f) ?><?php if ($f['status'] !== 'unpaid'): ?><br><span class="small muted"><?= e(fmt_date($f['settled_at'], 'j M')) ?><?= $f['settled_by_name'] ? ' by @' . e($f['settled_by_name']) : '' ?></span><?php endif; ?></td>
            <td class="num nowrap" data-label="Amount"><?= $f['status'] === 'waived' ? '<s class="muted">' . e(money((float)$f['amount'])) . '</s>' : '<strong>' . e(money((float)$f['amount'])) . '</strong>' ?></td>
            <td><div class="actions">
              <?php if ($f['status'] === 'unpaid'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fine_paid"><input type="hidden" name="fine_id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn--outline btn--sm" type="submit"><?= icon('check') ?> Paid</button></form>
                <form method="post" data-confirm="<?= e($f['member_name']) ?> won't owe <?= e(money((float)$f['amount'])) ?> for “<?= e($f['book_title']) ?>”. This is logged." data-confirm-title="Waive this fee?" data-confirm-ok="Waive fee">
                  <?= csrf_field() ?><input type="hidden" name="action" value="fine_waive"><input type="hidden" name="fine_id" value="<?= (int)$f['id'] ?>">
                  <button class="btn btn--ghost btn--sm" type="submit">Waive</button></form>
              <?php else: ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/desk.php', ['card' => card_number((int)$f['user_id'])])) ?>">Member</a>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pagination_links($p) ?>
    <?php else: ?>
      <div class="empty"><?= empty_art('done') ?><h3><?= $search !== '' ? 'No matching fees' : 'No late fees' ?></h3>
        <p>When a book is checked in late, its fee (<?= e(money((float)config('fines.per_day'))) ?> a day) appears here to collect at the desk.</p></div>
    <?php endif; ?>
  <?php elseif ($tab === 'holds'): ?>
    <?php if ($holdRows): ?>
    <div class="table-wrap">
      <table class="table table--stack">
        <thead><tr><th>Book</th><th>Member</th><th>Placed</th><th>Status</th><th class="num">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($holdRows as $h): ?>
          <tr>
            <td><a class="cell-book" href="<?= e(url('book.php', ['id' => $h['book_id']])) ?>"><?= cover_html($h, 'cover-sm') ?><span><strong><?= e($h['title']) ?></strong><span class="muted small"><?= e($h['author']) ?></span></span></a></td>
            <td><div class="cell-person"><span class="avatar"><?= e(initials($h['member_name'])) ?></span><span><?= e($h['member_name']) ?><br><span class="small muted">@<?= e($h['username']) ?></span></span></div></td>
            <td class="nowrap" data-label="Placed"><?= e(fmt_date($h['created_at'])) ?></td>
            <td><?= $h['status'] === 'ready'
                ? '<span class="badge badge--warn">Kept until ' . e(fmt_date($h['expires_at'], 'j M')) . '</span>'
                : '<span class="badge badge--info">' . ($h['position'] === 1 ? 'Next in line' : '#' . $h['position'] . ' in line') . '</span>' ?></td>
            <td><div class="actions">
              <form method="post" data-confirm="<?= e($h['member_name']) ?> will lose their place for “<?= e($h['title']) ?>”." data-confirm-title="Cancel this hold?" data-confirm-ok="Cancel hold">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel_hold"><input type="hidden" name="hold_id" value="<?= (int)$h['id'] ?>">
                <button class="btn btn--ghost btn--sm" type="submit">Cancel hold</button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty"><?= empty_art('loans') ?><h3><?= $search !== '' ? 'No matching holds' : 'No holds' ?></h3>
        <p>When every copy of a book is out, members can join a line for it. The next copy that comes back is kept for the first person in line for <?= plural(hold_pickup_days(), 'day') ?>.</p></div>
    <?php endif; ?>
  <?php elseif ($loans): ?>
  <div class="table-wrap">
    <table class="table table--stack">
      <thead><tr><th>Book</th><th>Member</th><th>Borrowed</th><th><?= $tab === 'history' ? 'Returned' : 'Due' ?></th><th>Status</th><th class="num">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($loans as $l): ?>
        <tr>
          <td><a class="cell-book" href="<?= e(url('book.php', ['id' => $l['book_id']])) ?>"><?= cover_html($l, 'cover-sm') ?><span><strong><?= e($l['title']) ?></strong><span class="muted small"><?= e($l['author']) ?></span></span></a></td>
          <td><div class="cell-person"><span class="avatar"><?= e(initials($l['member_name'])) ?></span><span><?= e($l['member_name']) ?><br><span class="small muted">@<?= e($l['username']) ?></span></span></div></td>
          <td class="nowrap"><?= e(fmt_date($l['borrowed_at'])) ?></td>
          <td class="nowrap">
            <?php if ($tab === 'history'): ?><?= e(fmt_date($l['returned_at'])) ?>
              <?php if ($l['returned_at'] > $l['due_at']): ?><br><span class="small" style="color:var(--gold-text)">Late</span><?php endif; ?>
            <?php else: ?><?= e(fmt_date($l['due_at'])) ?><br><span class="small <?= loan_state($l) === 'overdue' ? '' : 'muted' ?>" style="<?= loan_state($l) === 'overdue' ? 'color:var(--red)' : '' ?>"><?= e(due_label($l['due_at'])) ?></span><?php endif; ?>
          </td>
          <td><?= loan_badge($l) ?></td>
          <td>
            <div class="actions">
              <?php if (loan_state($l) === 'overdue'): ?>
                <a class="btn btn--ghost btn--sm btn--icon" href="<?= e(url('admin/messages.php', ['member' => $l['user_id']])) ?>" aria-label="Message <?= e($l['member_name']) ?> about this overdue book" title="Message member"><?= icon('message') ?></a>
              <?php endif; ?>
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
    <div class="empty"><?= empty_art($tab === 'overdue' ? 'done' : 'loans') ?>
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
          <?php foreach ($availableBooks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= input_int('book_id') === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['title']) ?>, <?= e($b['author']) ?> (<?= (int)$b['available'] ?> left)</option><?php endforeach; ?>
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
