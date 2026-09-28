<?php
/**
 * Page shell: <head>, sidebar navigation and the opening of <main>.
 * Expects: $title (string), $active (nav key). Optional: $bodyClass.
 */
$user = current_user();
$active = $active ?? '';
$isAdmin = $user && $user['role'] === 'admin';

if ($user && !$isAdmin) {
    $navCounts = [
        'loans'    => active_loan_count((int)$user['id']),
        'messages' => unread_replies((int)$user['id']),
    ];
    $nav = [
        ['discover.php', 'discover', 'compass', 'Discover'],
        ['loans.php', 'loans', 'book', 'My loans', $navCounts['loans']],
        ['favorites.php', 'favorites', 'heart', 'Favorites'],
        ['messages.php', 'messages', 'message', 'Messages', $navCounts['messages']],
        ['profile.php', 'profile', 'user', 'Profile'],
    ];
} elseif ($isAdmin) {
    $navCounts = [
        'returns'  => (int)q_val("SELECT COUNT(*) FROM loans WHERE status = 'return_requested'"),
        'messages' => (int)q_val('SELECT COUNT(*) FROM messages WHERE is_read = 0'),
    ];
    $nav = [
        ['admin/index.php', 'overview', 'grid', 'Overview'],
        ['admin/loans.php', 'loans', 'swap', 'Circulation', $navCounts['returns']],
        ['admin/books.php', 'books', 'books', 'Books'],
        ['admin/members.php', 'members', 'users', 'Members'],
        ['admin/messages.php', 'messages', 'inbox', 'Messages', $navCounts['messages']],
        ['admin/activity.php', 'activity', 'activity', 'Activity log'],
        ['profile.php', 'profile', 'user', 'Profile'],
    ];
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e(($title ?? '') ? $title . ' · ' : '') ?><?= e(config('app_name')) ?></title>
  <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Instrument+Sans:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php if ($user): ?>
<div class="app" id="app">
  <header class="topbar">
    <button class="btn btn--ghost btn--icon menu-btn" type="button" data-nav-toggle aria-label="Open menu"><?= icon('menu') ?></button>
    <a class="brand" href="<?= e(url(home_for($user))) ?>">
      <img src="<?= e(asset('img/logo.png')) ?>" alt="" width="46" height="27">
      <span class="brand__name">BookTrack</span>
    </a>
  </header>
  <div class="scrim" data-nav-toggle></div>

  <aside class="sidebar" aria-label="Sidebar">
    <a class="brand" href="<?= e(url(home_for($user))) ?>">
      <img src="<?= e(asset('img/logo.png')) ?>" alt="" width="46" height="27">
      <span><span class="brand__name">BookTrack</span><?php if ($isAdmin): ?><span class="brand__tag">Librarian</span><?php endif; ?></span>
    </a>
    <nav class="nav" aria-label="Main">
      <?php foreach ($nav as $item): ?>
        <a href="<?= e(url($item[0])) ?>" <?= $active === $item[1] ? 'aria-current="page"' : '' ?>>
          <?= icon($item[2]) ?><span><?= e($item[3]) ?></span>
          <?php if (!empty($item[4])): ?><span class="count"><?= (int)$item[4] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="user-card">
      <span class="avatar"><?= e(initials($user['name'])) ?></span>
      <div class="user-card__meta">
        <span class="user-card__name"><?= e($user['name']) ?></span>
        <span class="user-card__role"><?= $isAdmin ? 'Librarian' : 'Member' ?></span>
      </div>
      <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn--ghost btn--sm btn--icon" type="submit" aria-label="Sign out" title="Sign out" style="border-color:transparent"><?= icon('logout') ?></button>
      </form>
    </div>
  </aside>

  <main class="main" id="main">
<?php endif; ?>
