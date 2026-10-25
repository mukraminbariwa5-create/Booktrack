<?php
/** Ask for a password reset link by username or email. */
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) redirect(home_for($u));

$error = null;
$sent = false;
$identifier = '';
if (is_post()) {
    verify_csrf();
    $identifier = input('identifier');
    if ($identifier === '') {
        $error = 'Enter your username or email address.';
    } else {
        $error = request_password_reset($identifier);
        $sent = $error === null;
    }
}

$title = 'Reset your password';
$bodyClass = 'auth-page';
require __DIR__ . '/includes/partials/header.php';
require __DIR__ . '/includes/partials/auth_top.php';
?>
<section class="auth__card" aria-labelledby="forgot-h">
<?php if ($sent): ?>
  <h1 id="forgot-h">Check your email</h1>
  <p class="auth__lede">If <strong><?= e($identifier) ?></strong> matches an account, we've sent a link to its email address. The link works once, for the next <?= RESET_TTL_MINUTES ?> minutes.</p>
  <div class="alert alert--info" role="status"><?= icon('inbox') ?><span>Nothing after a few minutes? Check your spam folder, or ask a librarian to set a new password for you.</span></div>
  <?php if (config('mail.driver', 'log') === 'log' && demo_mode()): ?>
    <p class="field__help">This public demo doesn't send emails, and the shared demo accounts can't be reset. Create your own account to try the full flow on your own copy.</p>
  <?php elseif (config('mail.driver', 'log') === 'log' && config('auth.show_demo_logins')): ?>
    <p class="field__help">Demo mode: emails aren't sent. Open the newest <code>.eml</code> file in <code>storage/mail/</code> to find the link.</p>
  <?php endif; ?>
  <a class="btn btn--lg btn--block" href="<?= e(url('login.php')) ?>">Back to sign in</a>
<?php else: ?>
  <h1 id="forgot-h">Reset your password</h1>
  <p class="auth__lede">Enter your username or the email on your account and we'll send you a link to choose a new password.</p>

  <?php if ($error): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

  <form class="stack" method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="identifier">Username or email</label>
      <input class="input" id="identifier" name="identifier" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="160" required value="<?= e($identifier) ?>" autofocus>
    </div>
    <button class="btn btn--lg btn--block" type="submit">Send reset link</button>
  </form>
  <p class="field__help">No email on your account? A librarian can set a new password for you at the desk.</p>
<?php endif; ?>
</section>

<?php if (!$sent): ?>
  <p class="auth__switch">Remembered it? <a class="link" href="<?= e(url('login.php')) ?>">Sign in</a></p>
<?php endif; ?>
<?php
require __DIR__ . '/includes/partials/auth_bottom.php';
require __DIR__ . '/includes/partials/footer.php';
