<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];

$errors = ['profile' => [], 'password' => []];
if (is_post()) {
    verify_csrf();
    if (input('action') === 'theme') {
        // fallback for the theme switch when JavaScript is off; it normally switches instantly
        $t = input('theme') === 'light' ? 'light' : 'dark';
        setcookie('bt_theme', $t, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        back('profile.php');
    } elseif (input('action') === 'profile') {
        $name = input('name');
        $email = input('email');
        $phone = input('phone');
        if ($name === '') $errors['profile'][] = 'Your name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['profile'][] = 'Enter a valid email address.';
        array_push($errors['profile'], ...length_errors(compact('name', 'email', 'phone'), ['name' => [120, 'Name'], 'email' => [160, 'Email'], 'phone' => [40, 'Phone']]));
        if (!$errors['profile']) {
            q('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?', [$name, $email ?: null, $phone ?: null, $uid]);
            flash('success', 'Profile updated.');
            redirect('profile.php');
        }
    } elseif (input('action') === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $hash = q_val('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify($current, (string)$hash)) $errors['password'][] = 'Your current password is incorrect.';
        if (strlen($new) < 8) $errors['password'][] = 'The new password must be at least 8 characters.';
        if (strlen($new) > 72) $errors['password'][] = 'The new password can be at most 72 characters.';
        if ($new !== ($_POST['confirm_password'] ?? '')) $errors['password'][] = 'The new passwords don\'t match.';
        if (!$errors['password']) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true); // invalidate any copy of the old session cookie
            log_activity('user.password_changed', $user['username']);
            flash('success', 'Password changed.');
            redirect('profile.php');
        }
    }
}

$stats = [
    'total' => (int)q_val('SELECT COUNT(*) FROM loans WHERE user_id = ?', [$uid]),
    'returned' => (int)q_val("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'returned'", [$uid]),
    'favorites' => (int)q_val('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$uid]),
];

$title = 'Profile';
$active = 'profile';
require __DIR__ . '/includes/partials/header.php';
$v = fn($k) => e(is_post() && input('action') === 'profile' ? input($k) : ($user[$k] ?? ''));
?>
<header class="page-head">
  <div class="page-head__title row" style="flex-direction:row;align-items:center;gap:18px">
    <span class="avatar avatar--lg"><?= e(initials($user['name'])) ?></span>
    <div class="stack" style="gap:2px">
      <h1><?= e($user['name']) ?></h1>
      <p>@<?= e($user['username']) ?>, <?= $user['role'] === 'admin' ? 'librarian' : 'member' ?> since <?= e(fmt_date($user['created_at'], 'F Y')) ?></p>
    </div>
  </div>
  <form class="show-mobile" method="post" action="<?= e(url('logout.php')) ?>">
    <?= csrf_field() ?>
    <button class="btn btn--ghost" type="submit"><?= icon('logout') ?> Sign out</button>
  </form>
</header>

<?php if ($user['role'] === 'member'): ?>
<div class="stats stats--3">
  <div class="stat"><span class="stat__label">Books borrowed</span><span class="stat__value"><?= $stats['total'] ?></span></div>
  <div class="stat"><span class="stat__label">Returned</span><span class="stat__value"><?= $stats['returned'] ?></span></div>
  <div class="stat"><span class="stat__label">Favorites</span><span class="stat__value"><?= $stats['favorites'] ?></span></div>
</div>
<?php endif; ?>

<section class="card appearance">
  <div class="appearance__text">
    <h2>Appearance</h2>
    <p class="small muted">Night Shelf is easy on the eyes after dark; Day Shelf reads better in bright rooms. Saved on this device.</p>
  </div>
  <form method="post" class="segmented" role="group" aria-label="Theme">
    <?= csrf_field() ?><input type="hidden" name="action" value="theme">
    <button type="submit" name="theme" value="dark" data-theme-set="dark" aria-pressed="<?= $theme === 'dark' ? 'true' : 'false' ?>"><?= icon('moon') ?> Night</button>
    <button type="submit" name="theme" value="light" data-theme-set="light" aria-pressed="<?= $theme === 'light' ? 'true' : 'false' ?>"><?= icon('sun') ?> Day</button>
  </form>
</section>

<div class="grid-2 grid-2--even">
  <form class="card stack" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <div class="card__head" style="margin:0"><h2>Personal details</h2></div>
    <?php if ($errors['profile']): ?><div class="alert alert--error"><?= icon('alert') ?><span><?= e(implode(' ', $errors['profile'])) ?></span></div><?php endif; ?>
    <div class="field"><label for="name">Full name</label><input class="input" id="name" name="name" required value="<?= $v('name') ?>"></div>
    <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" value="<?= $v('email') ?>"></div>
    <div class="field"><label for="phone">Phone</label><input class="input" id="phone" name="phone" value="<?= $v('phone') ?>"></div>
    <div class="field"><label>Username</label><input class="input" value="<?= e($user['username']) ?>" disabled><span class="hint">Ask the librarian if you need to change your username.</span></div>
    <div><button class="btn" type="submit">Save changes</button></div>
  </form>

  <form class="card stack" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="card__head" style="margin:0"><h2>Change password</h2></div>
    <?php if ($errors['password']): ?><div class="alert alert--error"><?= icon('alert') ?><span><?= e(implode(' ', $errors['password'])) ?></span></div><?php endif; ?>
    <div class="field"><label for="current_password">Current password</label><input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
    <div class="field"><label for="new_password">New password</label><input class="input" id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required><span class="hint">At least 8 characters.</span></div>
    <div class="field"><label for="confirm_password">Confirm new password</label><input class="input" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required></div>
    <div><button class="btn" type="submit">Update password</button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
