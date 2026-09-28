<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');

$groups = ['' => 'Everything', 'loan' => 'Loans', 'book' => 'Books', 'member' => 'Members', 'message' => 'Messages'];
$group = array_key_exists(input('type'), $groups) ? input('type') : '';
$where = $group !== '' ? 'WHERE action LIKE ?' : '';
$params = $group !== '' ? [$group . '.%'] : [];

$p = paginate((int)q_val("SELECT COUNT(*) FROM activity_log $where", $params), 25);
$rows = q_all("SELECT * FROM activity_log $where ORDER BY created_at DESC, id DESC LIMIT {$p['per']} OFFSET {$p['offset']}", $params);

$title = 'Activity log';
$active = 'activity';
require __DIR__ . '/../includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Activity log</h1>
    <p>Every change to books, members and loans, newest first.</p>
  </div>
</header>

<section class="card card--flush">
  <nav class="tabs" style="padding:0 12px" aria-label="Activity filters">
    <?php foreach ($groups as $k => $label): ?>
      <a href="<?= e(url('admin/activity.php', array_filter(['type' => $k]))) ?>" <?= $group === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>When</th><th>Event</th><th>Details</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap small"><?= e(fmt_date($r['created_at'], 'j M Y, H:i')) ?><br><span class="muted"><?= e(time_ago($r['created_at'])) ?></span></td>
          <td class="nowrap"><span class="row" style="gap:10px"><span class="dot <?= activity_dot($r['action']) ?>" style="margin:0"></span><?= e(activity_label($r['action'])) ?></span></td>
          <td><?= e($r['details']) ?></td>
          <td class="small muted">@<?= e($r['actor']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p) ?>
  <?php else: ?>
    <div class="empty"><?= icon('activity') ?><h3>Nothing logged yet</h3></div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
