<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$uid = (int)$user['id'];

$errors = ['profile' => [], 'password' => [], '2fa' => []];
if (is_post()) {
    verify_csrf();
    $guarded = ['profile' => 'details', 'password' => 'password', '2fa_start' => 'two-step sign-in', '2fa_confirm' => 'two-step sign-in'];
    if (isset($guarded[input('action')]) && is_demo_account($user)) {
        flash('error', 'This is a shared demo account, so its ' . $guarded[input('action')] . ' can\'t be changed. Create your own account to try that.');
        redirect('profile.php');
    }
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
    } elseif (input('action') === '2fa_start') {
        $_SESSION['2fa_setup'] = ['secret' => totp_new_secret(), 'at' => time()];
        redirect('profile.php', ['setup' => '2fa']);
    } elseif (input('action') === '2fa_cancel') {
        unset($_SESSION['2fa_setup']);
        redirect('profile.php');
    } elseif (input('action') === '2fa_confirm') {
        $setup = $_SESSION['2fa_setup'] ?? null;
        if (!$setup || time() - (int)$setup['at'] > 1800) {
            unset($_SESSION['2fa_setup']);
            flash('error', 'The setup timed out. Start again.');
            redirect('profile.php');
        }
        try {
            $_SESSION['2fa_codes_show'] = two_factor_enable($uid, (string)$setup['secret'], input('code'));
            unset($_SESSION['2fa_setup']);
            flash('success', 'Two-step sign-in is on. Save your recovery codes now.');
            redirect('profile.php', ['setup' => '2fa']);
        } catch (DomainException $ex) {
            $errors['2fa'][] = $ex->getMessage();
        }
    } elseif (input('action') === '2fa_disable' || input('action') === '2fa_codes') {
        $hash = q_val('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$hash)) {
            $errors['2fa'][] = 'Your password is incorrect.';
        } elseif (input('action') === '2fa_disable') {
            two_factor_disable($uid);
            flash('success', 'Two-step sign-in is off. Only your password protects your account now.');
            redirect('profile.php');
        } else {
            $_SESSION['2fa_codes_show'] = two_factor_new_recovery_codes($uid);
            flash('success', 'New recovery codes made. The old ones no longer work.');
            redirect('profile.php', ['setup' => '2fa']);
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

$tf = two_factor_for($uid);
$setup = !$tf ? ($_SESSION['2fa_setup'] ?? null) : null;
$showCodes = $_SESSION['2fa_codes_show'] ?? null;
unset($_SESSION['2fa_codes_show']);   // shown once

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
      <?php if ($user['role'] === 'member'): ?>
        <p class="profile-tally">
          <a href="<?= e(url('loans.php')) ?>"><strong><?= $stats['total'] ?></strong> <?= $stats['total'] === 1 ? 'book' : 'books' ?> borrowed</a>
          <a href="<?= e(url('loans.php')) ?>#history"><strong><?= $stats['returned'] ?></strong> returned</a>
          <a href="<?= e(url('favorites.php')) ?>"><strong><?= $stats['favorites'] ?></strong> <?= $stats['favorites'] === 1 ? 'favorite' : 'favorites' ?></a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if ($user['role'] === 'member'): $card = card_number($uid); ?>
<section class="libcard-wrap" aria-labelledby="card-h">
  <h2 id="card-h" class="sr-only">Your library card</h2>
  <div class="libcard">
    <div class="libcard__top">
      <span class="libcard__brand"><?= logo_mark() ?><?= e(config('app_name')) ?> Library</span>
      <span class="libcard__kind">Member card</span>
    </div>
    <div class="libcard__holder">
      <strong class="libcard__name"><?= e($user['name']) ?></strong>
      <span class="libcard__since">Member since <?= e(fmt_date($user['created_at'], 'F Y')) ?></span>
    </div>
    <div class="libcard__label">
      <?= qr_svg($card, 'qr libcard__qr', 'QR code for library card ' . $card) ?>
      <span class="libcard__number"><span class="libcard__number-label">Card number</span><span class="libcard__digits"><?= e($card) ?></span></span>
    </div>
  </div>
  <div class="libcard-actions">
    <button class="btn btn--sm" type="button" data-open="card-dialog"><?= icon('scan') ?> Show at the desk</button>
    <button class="btn btn--ghost btn--sm" type="button" data-copy="<?= e($card) ?>" hidden><?= icon('file') ?> <span data-copy-label>Copy number</span></button>
  </div>
</section>

<dialog class="modal card-dialog" id="card-dialog" aria-labelledby="card-dialog-h">
  <div class="card-dialog__inner">
    <button class="btn btn--ghost btn--icon btn--sm card-dialog__close" type="button" data-close aria-label="Close"><?= icon('x') ?></button>
    <h2 id="card-dialog-h"><?= e($user['name']) ?></h2>
    <?= qr_svg($card, 'qr card-dialog__qr', 'QR code for library card ' . $card) ?>
    <span class="card-dialog__digits"><?= e($card) ?></span>
    <p>Hold your phone up to the librarian's scanner. Turning up the screen brightness helps.</p>
  </div>
</dialog>
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

<section class="card twofa stack" id="two-step" aria-labelledby="twofa-h">
  <div class="twofa__head">
    <div>
      <h2 id="twofa-h"><?= icon('shield') ?> Two-step sign-in</h2>
      <p class="small muted">After your password, also enter a code from an authenticator app on your phone, so a stolen password isn't enough to get in.</p>
    </div>
    <?= $tf ? '<span class="badge badge--ok">On</span>' : '<span class="badge badge--muted">Off</span>' ?>
  </div>
  <?php if ($errors['2fa']): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $errors['2fa'])) ?></span></div><?php endif; ?>

  <?php if ($showCodes): ?>
    <div class="recovery">
      <p><strong>Your recovery codes.</strong> Save them somewhere safe, like a password manager or a printout. If you lose your phone, each code signs you in once. They won't be shown again.</p>
      <ol class="recovery__codes"><?php foreach ($showCodes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?></ol>
      <div class="row row--wrap" style="gap:8px">
        <button class="btn btn--sm" type="button" data-copy="<?= e(implode("\n", $showCodes)) ?>" hidden><?= icon('file') ?> <span data-copy-label>Copy codes</span></button>
        <a class="btn btn--ghost btn--sm" download="booktrack-recovery-codes.txt" href="data:text/plain;charset=utf-8,<?= rawurlencode(config('app_name', 'BookTrack') . " recovery codes for @" . $user['username'] . "\n\n" . implode("\n", $showCodes) . "\n") ?>"><?= icon('download') ?> Download</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tf): ?>
    <p class="small" style="margin:0">On since <?= e(fmt_date($tf['enabled_at'])) ?>. <?= e(plural(recovery_codes_left($uid), 'recovery code')) ?> left.</p>
    <div class="twofa__manage">
      <details class="twofa__more">
        <summary class="btn btn--outline btn--sm">New recovery codes</summary>
        <form class="stack" method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="2fa_codes">
          <div class="field"><label for="tf-pw-codes">Confirm with your password</label><input class="input" id="tf-pw-codes" name="current_password" type="password" autocomplete="current-password" required></div>
          <div><button class="btn btn--sm" type="submit">Make new codes</button></div>
        </form>
      </details>
      <details class="twofa__more">
        <summary class="btn btn--ghost btn--sm">Turn off</summary>
        <form class="stack" method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="2fa_disable">
          <div class="field"><label for="tf-pw-off">Confirm with your password</label><input class="input" id="tf-pw-off" name="current_password" type="password" autocomplete="current-password" required></div>
          <div><button class="btn btn--danger btn--sm" type="submit">Turn off two-step sign-in</button></div>
        </form>
      </details>
    </div>
  <?php elseif ($setup): $uri = totp_uri((string)$setup['secret'], (string)$user['username']); ?>
    <div class="twofa__setup">
      <div class="twofa__qr"><?= qr_svg($uri, 'qr', 'QR code to add ' . config('app_name', 'BookTrack') . ' to an authenticator app') ?></div>
      <ol class="twofa__steps">
        <li><strong>Scan this QR code</strong> with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password…).
          <span class="small muted">Can't scan? Choose "enter a setup key" and type <code class="twofa__key"><?= e(trim(chunk_split((string)$setup['secret'], 4, ' '))) ?></code></span></li>
        <li><strong>Type the 6-digit code</strong> the app shows to finish.
          <form class="twofa__confirm" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="2fa_confirm">
            <label class="sr-only" for="tf-code">6-digit code</label>
            <input class="input input--code" id="tf-code" name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" maxlength="7" placeholder="123 456" required <?= input('setup') === '2fa' ? 'autofocus' : '' ?>>
            <button class="btn" type="submit">Turn on</button>
          </form>
        </li>
      </ol>
    </div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_cancel"><button class="link-button small" type="submit">Cancel setup</button></form>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_start">
      <button class="btn btn--outline btn--sm" type="submit"><?= icon('shield') ?> Set up two-step sign-in</button></form>
  <?php endif; ?>
</section>

<div class="grid-2 grid-2--even">
  <form class="card stack" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <div class="card__head" style="margin:0"><h2>Personal details</h2></div>
    <?php if ($errors['profile']): ?><div class="alert alert--error"><?= icon('alert') ?><span><?= e(implode(' ', $errors['profile'])) ?></span></div><?php endif; ?>
    <div class="field"><label for="name">Full name</label><input class="input" id="name" name="name" required value="<?= $v('name') ?>"></div>
    <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" value="<?= $v('email') ?>"></div>
    <div class="field"><label for="phone">Phone</label><input class="input" id="phone" name="phone" value="<?= $v('phone') ?>"></div>
    <div class="field"><label for="username">Username</label><input class="input" id="username" value="<?= e($user['username']) ?>" disabled aria-describedby="username-hint"><span class="hint" id="username-hint">Ask the librarian if you need to change your username.</span></div>
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

<form class="show-mobile profile-signout" method="post" action="<?= e(url('logout.php')) ?>">
  <?= csrf_field() ?>
  <button class="btn btn--ghost" type="submit" style="width:100%"><?= icon('logout') ?> Sign out</button>
</form>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
