<?php
/** Choose a new password from an emailed reset link. */
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) redirect(home_for($u));

// The token is a secret: don't cache this page or pass the URL on to other sites.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$errors = [];
if (is_post()) {
    verify_csrf();
    $errors = complete_password_reset($token, (string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''));
    if (!$errors) {
        flash('success', 'Your password has been changed. Sign in with the new one.');
        redirect('login.php');
    }
}
$reset = find_password_reset($token);

$title = 'Choose a new password';
$bodyClass = 'auth-page';
require __DIR__ . '/includes/partials/header.php';
require __DIR__ . '/includes/partials/auth_top.php';
?>
<section class="auth__card" aria-labelledby="reset-h">
<?php if (!$reset): ?>
  <h1 id="reset-h">This link has expired</h1>
  <p class="auth__lede">Reset links work once and only for <?= RESET_TTL_MINUTES ?> minutes. Ask for a new one and use the newest email.</p>
  <a class="btn btn--lg btn--block" href="<?= e(url('forgot_password.php')) ?>">Send a new link</a>
<?php else: ?>
  <h1 id="reset-h">Choose a new password</h1>
  <p class="auth__lede">For <strong>@<?= e($reset['username']) ?></strong>. You'll sign in with it from now on.</p>

  <?php if (isset($errors['form'])): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div><?php endif; ?>

  <form class="stack" method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="field">
      <label for="password">New password</label>
      <div class="input-group">
        <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required autofocus<?= field_error_attrs('password', $errors, 'password') ?>>
        <button class="input-group__btn" type="button" data-toggle-password aria-controls="password" aria-pressed="false">Show</button>
      </div>
      <?= field_error('password', $errors, 'password') ?: '<p class="field__help">At least 8 characters.</p>' ?>
    </div>
    <div class="field">
      <label for="password_confirm">Confirm new password</label>
      <div class="input-group">
        <input class="input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" maxlength="72" required<?= field_error_attrs('password_confirm', $errors, 'confirm') ?>>
        <button class="input-group__btn" type="button" data-toggle-password aria-controls="password_confirm" aria-pressed="false">Show</button>
      </div>
      <?= field_error('password_confirm', $errors, 'confirm') ?>
    </div>
    <button class="btn btn--lg btn--block" type="submit">Save new password</button>
  </form>
<?php endif; ?>
</section>
<?php
require __DIR__ . '/includes/partials/auth_bottom.php';
require __DIR__ . '/includes/partials/footer.php';
