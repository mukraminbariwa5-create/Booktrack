<?php
/** Shown for any address inside BookTrack that doesn't exist (see .htaccess). */
require __DIR__ . '/includes/bootstrap.php';
http_response_code(404);

$user = current_user();
$title = 'Page not found';
$active = '';
$bodyClass = $user ? '' : 'auth-page';
require __DIR__ . '/includes/partials/header.php';
?>
<?php if (!$user) require __DIR__ . '/includes/partials/auth_top.php'; ?>
<section class="<?= $user ? 'card ' : '' ?>empty not-found" aria-labelledby="nf-h">
  <?= icon('compass') ?>
  <h1 id="nf-h">Page not found</h1>
  <p>The address may be mistyped, or the page was moved or deleted.</p>
  <a class="btn" href="<?= e(url($user ? home_for($user) : 'login.php')) ?>"><?= $user ? 'Go to ' . ($user['role'] === 'admin' ? 'the overview' : 'Discover') : 'Go to sign in' ?></a>
</section>
<?php if (!$user) require __DIR__ . '/includes/partials/auth_bottom.php'; ?>
<?php require __DIR__ . '/includes/partials/footer.php';
