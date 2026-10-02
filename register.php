<?php
/** Member self-registration. Creates an active member account and signs it in. */
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) redirect(home_for($u));
if (!config('auth.allow_registration')) redirect('login.php');

$errors = [];
$form = ['name' => '', 'username' => '', 'email' => '', 'phone' => ''];

if (is_post()) {
    verify_csrf();
    $form = [
        'name'     => input('name'),
        'username' => strtolower(input('username')),
        'email'    => input('email'),
        'phone'    => input('phone'),
    ];
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

    // Bots fill every field; people never see this one.
    if (input('website') !== '') {
        $errors['form'] = 'Something went wrong. Please try again.';
    } else {
        // At most 5 new accounts per address per hour.
        $recent = (int)login_attempts_sql("SELECT COUNT(*) FROM login_attempts WHERE username = '#signup' AND ip = ? AND attempted_at > ?", [$ip, time() - 3600])->fetchColumn();
        if ($recent >= 5) $errors['form'] = 'Too many accounts were created from this connection. Try again in an hour.';
    }

    if (!$errors) $errors = validate_new_member($form, $password, $confirm);

    if (!$errors) {
        q("INSERT INTO users (name, username, email, phone, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'member', 1, ?)",
            [$form['name'], $form['username'], $form['email'], $form['phone'] ?: null, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        $id = (int)db()->lastInsertId();
        login_attempts_sql("INSERT INTO login_attempts (username, ip, attempted_at) VALUES ('#signup', ?, ?)", [$ip, time()]);
        sign_in_as($id);
        log_activity('member.registered', $form['name'] . ' (@' . $form['username'] . ')');
        flash('success', 'Welcome, ' . explode(' ', $form['name'])[0] . '. Your account is ready.');
        redirect('discover.php');
    }
}

$title = 'Create an account';
$bodyClass = 'auth-page';
require __DIR__ . '/includes/partials/header.php';
require __DIR__ . '/includes/partials/auth_top.php';
?>
<section class="auth__card auth__card--wide" aria-labelledby="signup-h">
  <h1 id="signup-h">Create an account</h1>
  <p class="auth__lede">Members can borrow up to <?= (int)config('library.max_active_loans') ?> books at a time, each for <?= (int)config('library.loan_days') ?> days.</p>

  <?php if (isset($errors['form'])): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($errors['form']) ?></span></div>
  <?php elseif ($errors): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span>Check the <?= count($errors) === 1 ? 'highlighted field' : count($errors) . ' highlighted fields' ?> below.</span></div><?php endif; ?>

  <form class="stack" method="post" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>

    <div class="field">
      <label for="name">Full name</label>
      <input class="input" id="name" name="name" autocomplete="name" maxlength="120" required value="<?= e($form['name']) ?>"<?= field_error_attrs('name', $errors, 'name') ?> <?= $errors ? '' : 'autofocus' ?>>
      <?= field_error('name', $errors, 'name') ?>
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input class="input" id="email" name="email" type="email" autocomplete="email" maxlength="160" required value="<?= e($form['email']) ?>"<?= field_error_attrs('email', $errors, 'email') ?>>
      <?= field_error('email', $errors, 'email') ?>
    </div>

    <div class="form-grid">
      <div class="field">
        <label for="username">Username</label>
        <input class="input" id="username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="60" required value="<?= e($form['username']) ?>"<?= field_error_attrs('username', $errors, 'username') ?>>
        <?= field_error('username', $errors, 'username') ?: '<p class="field__help">You\'ll use this to sign in.</p>' ?>
      </div>
      <div class="field">
        <label for="phone">Phone <span class="muted">(optional)</span></label>
        <input class="input" id="phone" name="phone" type="tel" autocomplete="tel" maxlength="40" value="<?= e($form['phone']) ?>"<?= field_error_attrs('phone', $errors, 'phone') ?>>
        <?= field_error('phone', $errors, 'phone') ?>
      </div>
    </div>


    <div class="form-grid">
      <div class="field">
        <label for="password">Password</label>
        <div class="input-group">
          <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required<?= field_error_attrs('password', $errors, 'password') ?>>
          <button class="input-group__btn" type="button" data-toggle-password aria-controls="password" aria-pressed="false">Show</button>
        </div>
        <?= field_error('password', $errors, 'password') ?: '<p class="field__help">At least 8 characters.</p>' ?>
      </div>
      <div class="field">
        <label for="password_confirm">Confirm password</label>
        <div class="input-group">
          <input class="input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" maxlength="72" required<?= field_error_attrs('password_confirm', $errors, 'confirm') ?>>
          <button class="input-group__btn" type="button" data-toggle-password aria-controls="password_confirm" aria-pressed="false">Show</button>
        </div>
        <?= field_error('password_confirm', $errors, 'confirm') ?>
      </div>
    </div>

    <button class="btn btn--lg btn--block" type="submit">Create account</button>
  </form>
</section>

<p class="auth__switch">Already have an account? <a class="link" href="<?= e(url('login.php')) ?>">Sign in</a></p>
<?php
require __DIR__ . '/includes/partials/auth_bottom.php';
require __DIR__ . '/includes/partials/footer.php';
