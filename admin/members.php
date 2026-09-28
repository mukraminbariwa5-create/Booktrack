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
            'name' => input('name'),
            'username' => strtolower(input('username')),
            'email' => input('email'),
            'phone' => input('phone'),
            'is_active' => input('is_active') === '1' ? 1 : 0,
        ];
        $password = (string)($_POST['password'] ?? '');
        $existing = $id ? q_one("SELECT * FROM users WHERE id = ? AND role = 'member'", [$id]) : null;

        if ($id && !$existing) $formErrors[] = 'That member no longer exists.';
        if ($data['name'] === '') $formErrors[] = 'Name is required.';
        array_push($formErrors, ...length_errors($data, ['name' => [120, 'Name'], 'email' => [160, 'Email'], 'phone' => [40, 'Phone']]));
        if (strlen($password) > 72) $formErrors[] = 'Passwords can be at most 72 characters.';
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $data['username'])) $formErrors[] = 'Username must be 3–60 characters: letters, numbers, dots, dashes or underscores.';
        elseif (q_val('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$data['username'], $id])) $formErrors[] = 'That username is already taken.';
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $formErrors[] = 'Enter a valid email address.';
        if (!$existing && strlen($password) < 8) $formErrors[] = 'Set a password of at least 8 characters.';
        if ($existing && $password !== '' && strlen($password) < 8) $formErrors[] = 'New passwords must be at least 8 characters.';

        if (!$formErrors) {
            if ($existing) {
                q('UPDATE users SET name = ?, username = ?, email = ?, phone = ?, is_active = ? WHERE id = ?',
                    [$data['name'], $data['username'], $data['email'] ?: null, $data['phone'] ?: null, $data['is_active'], $id]);
                if ($password !== '') q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                log_activity('member.updated', $data['name'] . ($password !== '' ? ' (password reset)' : ''));
                flash('success', $data['name'] . '\'s account was updated.');
            } else {
                q("INSERT INTO users (name, username, email, phone, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'member', ?, ?)",
                    [$data['name'], $data['username'], $data['email'] ?: null, $data['phone'] ?: null, password_hash($password, PASSWORD_DEFAULT), $data['is_active'], date('Y-m-d H:i:s')]);
                log_activity('member.created', $data['name']);
                flash('success', $data['name'] . ' can now sign in as @' . $data['username'] . '.');
            }
            back('admin/members.php');
        }
        $formData = $data + ['id' => $id ?: ''];
    }

    if ($action === 'delete') {
        $m = q_one("SELECT * FROM users WHERE id = ? AND role = 'member'", [input_int('id')]);
        if ($m) {
            if (active_loan_count((int)$m['id'])) {
                flash('error', $m['name'] . ' still has books on loan. Check them in first, or deactivate the account instead.');
            } else {
                q('DELETE FROM users WHERE id = ?', [$m['id']]);
                log_activity('member.deleted', $m['name']);
                flash('success', $m['name'] . ' was removed.');
            }
        }
        back('admin/members.php');
    }
}

$search = input('q');
$status = input('status');
$where = ["u.role = 'member'"];
$params = [];
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($status === 'active') $where[] = 'u.is_active = 1';
if ($status === 'inactive') $where[] = 'u.is_active = 0';
$whereSql = 'WHERE ' . implode(' AND ', $where);
$p = paginate((int)q_val("SELECT COUNT(*) FROM users u $whereSql", $params), 15);
$members = q_all("SELECT u.id, u.name, u.username, u.email, u.phone, u.is_active, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.status IN " . ACTIVE_LOAN . ") AS active_loans,
                    (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.status = 'borrowed' AND l.due_at < ?) AS overdue,
                    (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id) AS total_loans
                  FROM users u $whereSql ORDER BY u.name LIMIT {$p['per']} OFFSET {$p['offset']}", array_merge([today()], $params));

$memberJson = fn(array $m) => json_encode(['id' => $m['id'], 'name' => $m['name'], 'username' => $m['username'], 'email' => $m['email'], 'phone' => $m['phone'], 'is_active' => $m['is_active']], JSON_UNESCAPED_UNICODE);

$title = 'Members';
$active = 'members';
require __DIR__ . '/../includes/partials/header.php';
?>
<header class="page-head">
  <div class="page-head__title">
    <h1>Members</h1>
    <p><?= plural($p['total'], 'member') ?><?= $search !== '' || $status !== '' ? ' match your filters' : '' ?>.</p>
  </div>
  <button class="btn" type="button" data-open="member-dialog" data-reset><?= icon('plus') ?> Add member</button>
</header>

<section class="card card--flush">
  <form class="toolbar" method="get">
    <label class="search" style="flex:1;max-width:420px"><?= icon('search') ?><span class="sr-only">Search members</span>
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, username or email"></label>
    <label class="sr-only" for="status">Status</label>
    <select class="select" id="status" name="status" data-autosubmit style="width:auto">
      <option value="">All members</option>
      <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Deactivated</option>
    </select>
    <?php if ($search !== '' || $status !== ''): ?><a class="btn btn--ghost btn--sm" href="<?= e(url('admin/members.php')) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($members): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Member</th><th>Contact</th><th>On loan</th><th class="num">Total loans</th><th>Last sign-in</th><th class="num">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td><div class="cell-person"><span class="avatar" style="<?= (int)$m['is_active'] ? '' : 'background:var(--border-strong)' ?>"><?= e(initials($m['name'])) ?></span>
            <span><strong><?= e($m['name']) ?></strong><?= (int)$m['is_active'] ? '' : ' <span class="badge badge--muted badge--plain">Deactivated</span>' ?><br><span class="small muted">@<?= e($m['username']) ?> · joined <?= e(fmt_date($m['created_at'], 'M Y')) ?></span></span></div></td>
          <td class="small"><?= e($m['email'] ?: '—') ?><br><span class="muted"><?= e($m['phone'] ?: '') ?></span></td>
          <td><?php if ((int)$m['overdue']): ?><span class="badge badge--danger"><?= (int)$m['overdue'] ?> overdue</span>
              <?php elseif ((int)$m['active_loans']): ?><span class="badge badge--info"><?= plural((int)$m['active_loans'], 'book') ?></span>
              <?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td class="num"><?= (int)$m['total_loans'] ?></td>
          <td class="small muted nowrap"><?= $m['last_login_at'] ? e(time_ago($m['last_login_at'])) : 'Never' ?></td>
          <td>
            <div class="actions">
              <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/loans.php', ['q' => $m['username'], 'tab' => 'active'])) ?>" title="View loans">Loans</a>
              <button class="btn btn--ghost btn--sm btn--icon" type="button" data-open="member-dialog" data-fill="<?= e($memberJson($m)) ?>" aria-label="Edit <?= e($m['name']) ?>" title="Edit"><?= icon('edit') ?></button>
              <form method="post" data-confirm="<?= e($m['name']) ?>'s account, favorites, messages and loan history will be permanently removed. You can deactivate the account instead to keep the history." data-confirm-title="Delete this member?" data-confirm-ok="Delete member">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn--ghost btn--sm btn--icon" type="submit" aria-label="Delete <?= e($m['name']) ?>" title="Delete"><?= icon('trash') ?></button>
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
    <div class="empty"><?= icon('users') ?><h3>No members found</h3><p>Try a different search, or add a new member.</p></div>
  <?php endif; ?>
</section>

<?php $f = $formData; $isEdit = $f && !empty($f['id']); ?>
<dialog class="modal" id="member-dialog" <?= $formErrors ? 'data-autoopen' : '' ?> aria-labelledby="member-h">
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($f['id'] ?? '') ?>">
    <div class="modal__head"><h2 id="member-h" data-title-edit="Edit member" data-title-create="Add a member"><?= $isEdit ? 'Edit member' : 'Add a member' ?></h2>
      <button class="btn btn--ghost btn--icon btn--sm" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal__body">
      <?php if ($formErrors): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $formErrors)) ?></span></div><?php endif; ?>
      <div class="form-grid">
        <div class="field span-2"><label for="m-name">Full name</label><input class="input" id="m-name" name="name" required maxlength="120" value="<?= e($f['name'] ?? '') ?>"></div>
        <div class="field"><label for="m-username">Username</label><input class="input" id="m-username" name="username" required maxlength="60" pattern="[A-Za-z0-9._\-]{3,60}" value="<?= e($f['username'] ?? '') ?>"></div>
        <div class="field"><label for="m-password">Password</label><input class="input" id="m-password" name="password" type="password" minlength="8" autocomplete="new-password">
          <span class="hint" data-when-create <?= $isEdit ? 'hidden' : '' ?>>At least 8 characters.</span>
          <span class="hint" data-when-edit <?= $isEdit ? '' : 'hidden' ?>>Leave blank to keep the current password.</span></div>
        <div class="field"><label for="m-email">Email</label><input class="input" id="m-email" name="email" type="email" maxlength="160" value="<?= e($f['email'] ?? '') ?>"></div>
        <div class="field"><label for="m-phone">Phone</label><input class="input" id="m-phone" name="phone" maxlength="40" value="<?= e($f['phone'] ?? '') ?>"></div>
        <div class="field span-2">
          <input type="hidden" name="is_active" value="0">
          <label class="row" style="gap:10px;font-weight:500"><input type="checkbox" name="is_active" value="1" <?= ($f['is_active'] ?? 1) ? 'checked' : '' ?>> Account is active <span class="muted small">(deactivated members can't sign in)</span></label>
        </div>
      </div>
    </div>
    <div class="modal__foot"><button class="btn btn--ghost" type="button" data-close>Cancel</button><button class="btn" type="submit">Save member</button></div>
  </form>
</dialog>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
