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
    ];
} elseif ($isAdmin) {
    $navCounts = [
        'returns'  => (int)q_val("SELECT COUNT(*) FROM loans WHERE status = 'return_requested'"),
        'messages' => chat_unread_conversations(),
    ];
    $nav = [
        ['admin/index.php', 'overview', 'grid', 'Overview'],
        ['admin/loans.php', 'loans', 'swap', 'Circulation', $navCounts['returns']],
        ['admin/books.php', 'books', 'books', 'Books'],
        ['admin/members.php', 'members', 'users', 'Members'],
        ['admin/messages.php', 'messages', 'inbox', 'Messages', $navCounts['messages']],
        ['admin/activity.php', 'activity', 'activity', 'Activity'],
    ];
}
$theme = ($_COOKIE['bt_theme'] ?? '') === 'light' ? 'light' : 'dark';
$reminders = $user ? reminders_for($user) : [];
?><!doctype html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= $theme === 'light' ? '#F6F3EC' : '#0E1420' ?>">
  <meta name="color-scheme" content="<?= $theme ?>">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e(($title ?? '') ? $title . ' | ' : '') ?><?= e(config('app_name')) ?></title>
  <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=Fraunces:opsz,wght@9..144,600&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <script src="<?= e(asset('js/transitions.js')) ?>"></script>
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>"<?= $user ? ' data-search-api="' . e(url('api/search.php')) . '"' : '' ?>>
<?php if ($user):
    $home = url(home_for($user));
?>
<a class="skip-link" href="#main">Skip to content</a>
<div class="app" id="app">
  <aside class="sidebar" id="sidebar" aria-label="Sidebar">
    <a class="brand" href="<?= e($home) ?>" aria-label="BookTrack home">
      <img src="<?= e(asset('img/logo.png')) ?>" alt="" width="46" height="27">
      <span class="brand__text"><span class="brand__name">BookTrack</span><?php if ($isAdmin): ?><span class="brand__tag">Librarian</span><?php endif; ?></span>
    </a>
    <nav class="nav" aria-label="Main">
      <?php foreach ($nav as $item): ?>
        <a href="<?= e(url($item[0])) ?>" <?= $active === $item[1] ? 'aria-current="page"' : '' ?>>
          <span class="nav__icon"><?= icon($item[2]) ?></span>
          <span class="nav__label"><?= e($item[3]) ?></span>
          <?php if (!empty($item[4])): ?><span class="count" aria-label="<?= (int)$item[4] ?> new"><?= (int)$item[4] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="rail-user">
      <button class="rail-bell" type="button" popovertarget="reminders" aria-label="Reminders<?= $reminders ? ', ' . count($reminders) . ' new' : '' ?>">
        <span class="nav__icon"><?= icon('bell') ?><?php if ($reminders): ?><span class="count"><?= count($reminders) > 9 ? '9+' : count($reminders) ?></span><?php endif; ?></span>
        <span class="nav__label">Reminders</span>
      </button>
      <a class="rail-account" href="<?= e(url('profile.php')) ?>" <?= $active === 'profile' ? 'aria-current="page"' : '' ?> title="<?= e($user['name']) ?>">
        <span class="nav__icon"><span class="avatar"><?= e(initials($user['name'])) ?></span></span>
        <span class="nav__label">Profile</span>
      </a>
      <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button class="rail-signout" type="submit"><span class="nav__icon"><?= icon('logout') ?></span><span class="nav__label">Sign out</span></button>
      </form>
    </div>
  </aside>

  <?php
  // Phones get an app-style tab bar: the five main sections, always in reach.
  $tabbarItems = $isAdmin
      ? array_values(array_filter($nav, fn($i) => $i[1] !== 'activity'))
      : array_merge($nav, [['profile.php', 'profile', 'user', 'Profile']]);
  ?>
  <nav class="tabbar" aria-label="Sections">
    <?php foreach (array_slice($tabbarItems, 0, 5) as $item): ?>
      <a href="<?= e(url($item[0])) ?>" <?= $active === $item[1] ? 'aria-current="page"' : '' ?>>
        <span class="tabbar__icon"><?= icon($item[2]) ?><?php if (!empty($item[4])): ?><span class="count" aria-label="<?= (int)$item[4] ?> new"><?= (int)$item[4] ?></span><?php endif; ?></span>
        <span class="tabbar__label"><?= e($item[1] === 'loans' && !$isAdmin ? 'Loans' : $item[3]) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="shell">
    <header class="topbar" data-topbar>
      <a class="topbar__brand" href="<?= e($home) ?>"><img src="<?= e(asset('img/logo.png')) ?>" alt="" width="34" height="20">BookTrack<?php if ($isAdmin): ?><small>Librarian</small><?php endif; ?></a>
      <div class="topbar__actions">
        <button class="btn btn--ghost btn--icon topbar__btn" type="button" data-palette-open aria-label="Search"><?= icon('search') ?></button>
        <button class="btn btn--ghost btn--icon topbar__btn" type="button" popovertarget="reminders" aria-label="Reminders<?= $reminders ? ', ' . count($reminders) . ' new' : '' ?>"><?= icon('bell') ?><?php if ($reminders): ?><span class="count"><?= count($reminders) > 9 ? '9+' : count($reminders) ?></span><?php endif; ?></button>
        <a class="avatar topbar__avatar" href="<?= e(url('profile.php')) ?>" aria-label="Your profile"><?= e(initials($user['name'])) ?></a>
      </div>
    </header>

    <main class="main" id="main">
<?php endif; ?>
