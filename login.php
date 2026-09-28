<?php
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) redirect(home_for($u));

$error = null;
$username = '';
if (is_post()) {
    verify_csrf();
    $username = input('username');
    $error = attempt_login($username, (string)($_POST['password'] ?? ''));
    if (!$error) {
        $user = current_user(true);
        $intended = $_SESSION['intended'] ?? null;
        unset($_SESSION['intended']);
        // only follow same-app paths for the intended redirect
        if ($intended && str_starts_with($intended, base_url()) && !str_starts_with($intended, '//') && !str_contains($intended, 'logout')) {
            $isAdminPath = str_contains($intended, '/admin/');
            if ($isAdminPath === ($user['role'] === 'admin')) { header('Location: ' . $intended); exit; }
        }
        redirect(home_for($user));
    }
}

$shelf = q_all("SELECT title, author, cover FROM books ORDER BY (cover IS NULL), id LIMIT 6");
$title = 'Sign in';
$bodyClass = 'auth-page';
require __DIR__ . '/includes/partials/header.php';
?>
<div class="auth">
  <section class="auth__art" aria-hidden="true">
    <div class="brand">
      <img src="<?= e(asset('img/logo.png')) ?>" alt="" width="46" height="27">
      <span><span class="brand__name">BookTrack</span><span class="brand__tag">Library system</span></span>
    </div>
    <div class="auth__shelf">
      <?php foreach ($shelf as $b): ?><?= cover_html($b) ?><?php endforeach; ?>
    </div>
    <p class="auth__quote">“A library is not a luxury but one of the necessities of life.”<small>Henry Ward Beecher</small></p>
  </section>

  <section class="auth__panel">
    <form class="auth__form" method="post" novalidate>
      <?= csrf_field() ?>
      <div class="stack" style="gap:6px">
        <span class="eyebrow">Welcome back</span>
        <h1>Sign in to BookTrack</h1>
        <p class="muted" style="margin:0">Borrow books, track due dates and manage the library.</p>
      </div>

      <?php if ($error): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

      <div class="field">
        <label for="username">Username</label>
        <input class="input" id="username" name="username" autocomplete="username" required value="<?= e($username) ?>" autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn--block" type="submit" style="height:48px;font-size:15px">Sign in <?= icon('arrow-right') ?></button>

      <div class="demo-accounts">
        <strong>Demo accounts</strong>
        <span>Librarian: <button type="button" data-demo-user="admin" data-demo-pass="admin123">admin / admin123</button></span>
        <span>Member: <button type="button" data-demo-user="alice" data-demo-pass="member123">alice / member123</button></span>
      </div>
    </form>
  </section>
</div>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
