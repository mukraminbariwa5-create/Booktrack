<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) redirect(home_for($u));

$error = null;
$username = '';
if (is_post()) {
    verify_csrf();
    $username = input('username');
    $error = attempt_login($username, (string)($_POST['password'] ?? ''));
    if (!$error && two_factor_pending()) redirect('two_factor.php');
    if (!$error) redirect_after_sign_in();
}

$title = 'Sign in';
$bodyClass = 'auth-page';
require __DIR__ . '/includes/partials/header.php';
require __DIR__ . '/includes/partials/auth_top.php';
?>
<section class="auth__card" aria-labelledby="signin-h">
  <h1 id="signin-h">Sign in</h1>
  <p class="auth__lede">Use your library account to borrow and track books.</p>

  <?php if ($error): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

  <form class="stack" method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input class="input" id="username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= e($username) ?>" <?= $error ? '' : 'autofocus' ?>>
    </div>
    <div class="field">
      <div class="field__label-row">
        <label for="password">Password</label>
        <a class="link-button" href="<?= e(url('forgot_password.php')) ?>">Forgot password?</a>
      </div>
      <div class="input-group">
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required <?= $error ? 'autofocus' : '' ?>>
        <button class="input-group__btn" type="button" data-toggle-password aria-controls="password" aria-pressed="false">Show</button>
      </div>
    </div>
    <button class="btn btn--lg btn--block" type="submit">Sign in</button>
  </form>
</section>

<?php if (config('auth.allow_registration')): ?>
  <p class="auth__switch">New to the library? <a class="link" href="<?= e(url('register.php')) ?>">Create an account</a></p>
<?php endif; ?>

<?php if (config('auth.show_demo_logins') || demo_mode()): ?>
  <div class="auth__demo">
    <span>Trying the demo?<?php if (demo_mode()): ?> <span class="muted">It's shared with other visitors and resets every day. Please don't use a password from another site here.</span><?php endif; ?></span>
    <div class="auth__demo-actions">
      <button class="btn btn--outline btn--sm" type="button" data-demo-user="admin" data-demo-pass="admin123">Sign in as librarian</button>
      <button class="btn btn--outline btn--sm" type="button" data-demo-user="alice" data-demo-pass="member123">Sign in as member</button>
    </div>
  </div>
<?php endif; ?>
<?php
require __DIR__ . '/includes/partials/auth_bottom.php';
require __DIR__ . '/includes/partials/footer.php';
