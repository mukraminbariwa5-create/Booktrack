<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');
$today = today();

$stats = [
    'titles'   => (int)q_val('SELECT COUNT(*) FROM books'),
    'copies'   => (int)q_val('SELECT COALESCE(SUM(copies), 0) FROM books'),
    'on_loan'  => (int)q_val('SELECT COUNT(*) FROM loans WHERE status IN ' . ACTIVE_LOAN),
    'overdue'  => (int)q_val("SELECT COUNT(*) FROM loans WHERE status = 'borrowed' AND due_at < ?", [$today]),
    'members'  => (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member' AND is_active = 1"),
    'new_members' => (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member' AND created_at >= ?", [date('Y-m-d', strtotime('-30 days'))]),
    'due_week' => (int)q_val("SELECT COUNT(*) FROM loans WHERE status = 'borrowed' AND due_at BETWEEN ? AND ?", [$today, date('Y-m-d', strtotime('+7 days'))]),
];

$attention = q_all("SELECT l.*, b.title, b.author, b.cover, u.name AS member_name
                    FROM loans l JOIN books b ON b.id = l.book_id JOIN users u ON u.id = l.user_id
                    WHERE l.status = 'return_requested' OR (l.status = 'borrowed' AND l.due_at < ?)
                    ORDER BY (l.status = 'return_requested') DESC, l.due_at ASC LIMIT 8", [$today]);

// Loans per month, last 6 months (grouped in PHP so it works on any database)
$from = date('Y-m-01', strtotime('-5 months', strtotime(date('Y-m-01'))));
$months = [];
for ($i = 0; $i < 6; $i++) $months[date('Y-m', strtotime("$from +$i months"))] = 0;
foreach (q_all('SELECT borrowed_at FROM loans WHERE borrowed_at >= ?', [$from]) as $r) {
    $k = substr($r['borrowed_at'], 0, 7);
    if (isset($months[$k])) $months[$k]++;
}
$maxMonth = max(1, max($months));

// Circulation health, last 90 days (computed in PHP so it works on any database)
$since = date('Y-m-d', strtotime('-90 days'));
$returned = q_all("SELECT borrowed_at, due_at, returned_at FROM loans WHERE status = 'returned' AND returned_at >= ?", [$since]);
$onTime = count(array_filter($returned, fn($r) => $r['returned_at'] <= $r['due_at']));
$health = [
    'this_month' => end($months),
    'last_month' => prev($months),
    'on_time_pct' => $returned ? (int)round($onTime / count($returned) * 100) : null,
    'avg_days' => $returned ? (int)round(array_sum(array_map(fn($r) => (strtotime($r['returned_at']) - strtotime($r['borrowed_at'])) / 86400, $returned)) / count($returned)) : null,
    'returned' => count($returned),
];
$diff = $health['this_month'] - $health['last_month'];

$popular = q_all('SELECT b.id, b.title, b.author, b.cover, COUNT(l.id) AS n FROM loans l JOIN books b ON b.id = l.book_id
                  GROUP BY b.id, b.title, b.author, b.cover ORDER BY n DESC, b.title LIMIT 5');
$activity = q_all('SELECT * FROM activity_log ORDER BY created_at DESC, id DESC LIMIT 7');
$unread = chat_unread_conversations();

$title = 'Overview';
$active = 'overview';
require __DIR__ . '/../includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Library overview</h1>
    <p><?= e(date('l, j F Y')) ?></p>
  </div>
  <div class="row row--wrap">
    <a class="btn" href="<?= e(url('admin/loans.php', ['issue' => 1])) ?>"><?= icon('swap') ?> Issue a book</a>
  </div>
</header>

<section class="stats" aria-label="Key numbers">
  <a class="stat" href="<?= e(url('admin/books.php')) ?>">
    <span class="stat__label"><?= icon('books') ?> Titles</span>
    <span class="stat__value"><?= $stats['titles'] ?></span>
    <span class="stat__meta"><?= plural($stats['copies'], 'copy', 'copies') ?> in the collection</span>
  </a>
  <a class="stat" href="<?= e(url('admin/loans.php')) ?>">
    <span class="stat__label"><?= icon('swap') ?> On loan</span>
    <span class="stat__value"><?= $stats['on_loan'] ?></span>
    <span class="stat__meta"><?= $stats['due_week'] ?> due in the next 7 days</span>
  </a>
  <a class="stat <?= $stats['overdue'] ? 'stat--alert' : '' ?>" href="<?= e(url('admin/loans.php', ['tab' => 'overdue'])) ?>">
    <span class="stat__label"><?= icon('clock') ?> Overdue</span>
    <span class="stat__value"><?= $stats['overdue'] ?></span>
    <span class="stat__meta"><?= $stats['overdue'] ? 'Needs follow-up' : 'Everything is on time' ?></span>
  </a>
  <a class="stat" href="<?= e(url('admin/members.php')) ?>">
    <span class="stat__label"><?= icon('users') ?> Active members</span>
    <span class="stat__value"><?= $stats['members'] ?></span>
    <span class="stat__meta"><?= $stats['new_members'] ?> joined in the last 30 days</span>
  </a>
</section>

<div class="grid-2 grid-2--stretch">
  <section class="card card--flush" aria-labelledby="attn-h">
    <div class="card__head" style="padding:20px 22px 0">
      <h2 id="attn-h">Needs attention</h2>
      <a class="link small" href="<?= e(url('admin/loans.php', ['tab' => 'requests'])) ?>">Open circulation</a>
    </div>
    <?php if ($attention): ?>
    <div class="table-wrap">
      <table class="table table--compact table--stack">
        <thead><tr><th>Book and member</th><th class="hide-sm">Status</th><th><span class="sr-only">Action</span></th></tr></thead>
        <tbody>
        <?php foreach ($attention as $l): ?>
          <tr>
            <?php $badge = $l['status'] === 'return_requested' ? loan_badge($l) : '<span class="badge badge--danger">' . e(due_label($l['due_at'])) . '</span>'; ?>
            <td><div class="cell-book"><?= cover_html($l, 'cover-sm') ?><span><strong><?= e($l['title']) ?></strong><span class="muted small"><?= e($l['member_name']) ?>, due <?= e(fmt_date($l['due_at'], 'j M')) ?></span><span class="show-sm" style="margin-top:6px"><?= $badge ?></span></span></div></td>
            <td class="hide-sm"><?= $badge ?></td>
            <td class="num">
              <form method="post" action="<?= e(url('admin/loans.php')) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="return"><input type="hidden" name="loan_id" value="<?= (int)$l['id'] ?>">
                <button class="btn btn--outline btn--sm nowrap" type="submit"><?= icon('check') ?> Check in</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty"><?= empty_art('done') ?><h3>All caught up</h3><p>No return requests or overdue books right now.</p></div>
    <?php endif; ?>
  </section>

  <section class="card card--chart" aria-labelledby="chart-h">
    <div class="card__head"><h2 id="chart-h">Loans per month</h2><span class="small muted">Last 6 months</span></div>
    <div class="bars" role="img" aria-label="Loans per month: <?= e(implode(', ', array_map(fn($k, $v) => date('F', strtotime("$k-01")) . " $v", array_keys($months), $months))) ?>">
      <?php $last = array_key_last($months); $bi = 0; foreach ($months as $k => $n): ?>
        <div class="bars__col <?= $k === $last ? 'is-current' : '' ?>" style="--i: <?= $bi++ ?>" data-tip="<?= e(date('F Y', strtotime("$k-01")) . ': ' . plural($n, 'loan')) ?>">
          <span class="bars__value"><?= $n ?></span>
          <span class="bars__bar" style="height: <?= round($n / $maxMonth * 100) ?>%"></span>
          <span class="bars__label"><?= e(date('M', strtotime("$k-01"))) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <dl class="mini-stats">
      <div>
        <dt>This month so far</dt>
        <dd><?= plural($health['this_month'], 'loan') ?></dd>
        <dd class="mini-stats__note <?= $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'muted') ?>"><?= $diff === 0 ? 'Same as last month' : ($diff > 0 ? '+' : '−') . abs($diff) . ' vs last month' ?></dd>
      </div>
      <div>
        <dt>Returned on time</dt>
        <dd><?= $health['on_time_pct'] === null ? '—' : $health['on_time_pct'] . '%' ?></dd>
        <dd class="mini-stats__note muted"><?= $health['returned'] ? 'of ' . plural($health['returned'], 'return') . ', last 90 days' : 'No returns in 90 days' ?></dd>
      </div>
      <div>
        <dt>Average loan</dt>
        <dd><?= $health['avg_days'] === null ? '—' : plural($health['avg_days'], 'day') ?></dd>
        <dd class="mini-stats__note muted">Loan period is <?= (int)config('library.loan_days') ?> days</dd>
      </div>
    </dl>
    <details class="chart-table"><summary>View as table</summary>
      <table class="table" style="margin-top:8px"><thead><tr><th>Month</th><th class="num">Loans</th></tr></thead><tbody>
      <?php foreach ($months as $k => $n): ?><tr><td><?= e(date('F Y', strtotime("$k-01"))) ?></td><td class="num"><?= $n ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </details>
  </section>
</div>

<div class="grid-2">
  <section class="card" aria-labelledby="act-h">
    <div class="card__head"><h2 id="act-h">Recent activity</h2><a class="link small" href="<?= e(url('admin/activity.php')) ?>">View all</a></div>
    <?php if ($activity): ?>
    <div class="list">
      <?php foreach ($activity as $a): ?>
        <div class="list__item">
          <span class="dot <?= activity_dot($a['action']) ?>"></span>
          <div class="list__text"><strong><?= e(activity_label($a['action'])) ?></strong><span class="muted"><?= e($a['details']) ?></span></div>
          <span class="small muted nowrap"><?= e(time_ago($a['created_at'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><p class="muted">No activity yet.</p><?php endif; ?>
  </section>

  <section class="card" aria-labelledby="pop-h">
    <div class="card__head"><h2 id="pop-h">Most borrowed</h2></div>
    <div class="rank">
      <?php foreach ($popular as $i => $b): ?>
        <a class="rank__item" href="<?= e(url('book.php', ['id' => $b['id']])) ?>">
          <span class="rank__n"><?= $i + 1 ?></span>
          <?= cover_html($b, 'cover-sm') ?>
          <span class="rank__body"><strong><?= e($b['title']) ?></strong><span class="small muted"><?= e($b['author']) ?></span></span>
          <span class="badge badge--muted badge--plain"><?= plural((int)$b['n'], 'loan') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($unread): ?>
      <a class="alert alert--warn" href="<?= e(url('admin/messages.php')) ?>" style="margin-top:18px"><?= icon('inbox') ?><span><?= plural($unread, 'member') ?> waiting for a reply</span></a>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
