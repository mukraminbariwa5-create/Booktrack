<?php
/** Second sign-in step: the 6-digit code from the authenticator app, or a recovery code. */
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) redirect(home_for($u));

if (input('cancel') === '1') {
    two_factor_cancel();
    redirect('login.php');
}
$uid = two_factor_pending();
if (!$uid) {
    flash('error', 'Sign in again to continue.');
    redirect('login.php');
}

$error = null;
$useRecovery = input('recovery') === '1';
if (is_post()) {
    verify_csrf();
    $result = two_factor_complete(input('code'));
    if ($result['ok']) {
        if ($result['method'] === 'recovery') {
            $left = recovery_codes_left($uid);
            flash($left <= 3 ? 'error' : 'success', 'Signed in with a recovery code. ' . ($left
                ? 'You have ' . plural($left, 'code') . ' left' . ($left <= 3 ? '. Make new ones on your Profile page.' : '.')
                : 'That was your last one. Make new codes on your Profile page.'));
        }
        redirect_after_sign_in();
    }
    if ($result['restart']) {
        flash('error', $result['error']);
        redirect('login.php');
    }
    $error = $result['error'];
}
$who = q_one('SELECT username FROM users WHERE id = ?', [$uid]);

$title = 'Two-step sign-in';
$bodyClass = 'auth-page';
require __DIR__ . '/includes/partials/header.php';
require __DIR__ . '/includes/partials/auth_top.php';
?>
<section class="auth__card" aria-labelledby="tf-h">
  <h1 id="tf-h"><?= $useRecovery ? 'Use a recovery code' : 'Enter your code' ?></h1>
  <p class="auth__lede"><?= $useRecovery
      ? 'Type one of the recovery codes you saved when you turned on two-step sign-in. Each code works once.'
      : 'Open your authenticator app and type the 6-digit code for ' . e(config('app_name', 'BookTrack')) . ' (@' . e($who['username'] ?? '') . ').' ?></p>

  <?php if ($error): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

  <form class="stack" method="post" action="<?= e(url('two_factor.php', $useRecovery ? ['recovery' => 1] : [])) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="code"><?= $useRecovery ? 'Recovery code' : 'Authentication code' ?></label>
      <?php if ($useRecovery): ?>
        <input class="input input--code" id="code" name="code" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="20" placeholder="xxxx-xxxx" required autofocus>
      <?php else: ?>
        <input class="input input--code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" maxlength="7" placeholder="123 456" required autofocus>
      <?php endif; ?>
    </div>
    <button class="btn btn--lg btn--block" type="submit">Verify and sign in</button>
  </form>
  <p class="field__help"><?php if ($useRecovery): ?>
    <a class="link" href="<?= e(url('two_factor.php')) ?>">Use the code from your app instead</a>
  <?php else: ?>
    Lost your phone? <a class="link" href="<?= e(url('two_factor.php', ['recovery' => 1])) ?>">Use a recovery code</a>, or ask a librarian to turn two-step sign-in off for you.
  <?php endif; ?></p>
</section>

<p class="auth__switch"><a class="link" href="<?= e(url('two_factor.php', ['cancel' => 1])) ?>">Cancel and go back to sign in</a></p>
<?php
require __DIR__ . '/includes/partials/auth_bottom.php';
require __DIR__ . '/includes/partials/footer.php';
