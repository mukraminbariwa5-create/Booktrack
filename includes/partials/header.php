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
        // only loans that need attention: overdue or due within the "due soon" window
        'loans'    => (int)q_val("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'borrowed' AND due_at <= ?",
                        [(int)$user['id'], date('Y-m-d', strtotime('+' . (int)config('library.due_soon_days') . ' days'))])
                      + ready_hold_count((int)$user['id']),
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
        [$navCounts['returns'] ? 'admin/loans.php?tab=requests' : 'admin/loans.php', 'loans', 'swap', 'Circulation', $navCounts['returns']],
        ['admin/desk.php', 'desk', 'scan', 'Desk'],
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
  <meta name="theme-color" content="<?= $theme === 'light' ? '#EDF0F4' : '#0E1420' ?>">
  <meta name="color-scheme" content="<?= $theme ?>">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e(($title ?? '') ? $title . ' | ' : '') ?><?= e(config('app_name')) ?></title>
  <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/icon-512.png')) ?>">
  <link rel="preload" href="<?= e(url('assets/fonts/dm-sans.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= e(url('assets/fonts/bricolage-grotesque.woff2')) ?>" as="font" type="font/woff2" crossorigin>
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
      <?= logo_mark() ?>
      <span class="brand__text"><span class="brand__name">BookTrack</span><?php if ($isAdmin): ?><span class="brand__tag">Librarian</span><?php endif; ?></span>
    </a>
    <nav class="nav" aria-label="Main">
      <?php foreach ($nav as $item): ?>
        <a href="<?= e(url($item[0])) ?>" <?= $active === $item[1] ? 'aria-current="page"' : '' ?>>
          <span class="nav__icon"><?= icon($item[2]) ?></span>
          <span class="nav__label"><?= e($item[3]) ?></span>
          <?php if (!empty($item[4])): ?><span class="count" aria-label="<?= (int)$item[4] ?> <?= $item[1] === 'messages' ? 'new' : 'need attention' ?>"><?= (int)$item[4] ?></span><?php endif; ?>
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
  // Rendered after <main> (in footer.php) so keyboard and screen-reader users reach the
  // page before the bar; it's fixed to the bottom of the screen either way.
  ob_start();
  ?>
  <nav class="tabbar" aria-label="Sections">
    <?php foreach (array_slice($tabbarItems, 0, $isAdmin ? 6 : 5) as $item): ?>
      <a href="<?= e(url($item[0])) ?>" <?= $active === $item[1] ? 'aria-current="page"' : '' ?>>
        <span class="tabbar__icon"><?= icon($item[2]) ?><?php if (!empty($item[4])): ?><span class="count" aria-label="<?= (int)$item[4] ?> <?= $item[1] === 'messages' ? 'new' : 'need attention' ?>"><?= (int)$item[4] ?></span><?php endif; ?></span>
        <span class="tabbar__label"><?= e($item[1] === 'loans' && !$isAdmin ? 'Loans' : $item[3]) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php $tabbarHtml = ob_get_clean(); ?>

  <div class="shell">
    <header class="topbar" data-topbar>
      <a class="topbar__brand" href="<?= e($home) ?>"><?= logo_mark() ?>BookTrack<?php if ($isAdmin): ?><small>Librarian</small><?php endif; ?></a>
      <div class="topbar__actions">
        <button class="btn btn--ghost btn--icon topbar__btn" type="button" data-palette-open aria-label="Search"><?= icon('search') ?></button>
        <button class="btn btn--ghost btn--icon topbar__btn" type="button" popovertarget="reminders" aria-label="Reminders<?= $reminders ? ', ' . count($reminders) . ' new' : '' ?>"><?= icon('bell') ?><?php if ($reminders): ?><span class="count"><?= count($reminders) > 9 ? '9+' : count($reminders) ?></span><?php endif; ?></button>
        <a class="avatar topbar__avatar" href="<?= e(url('profile.php')) ?>" aria-label="Your profile"><?= e(initials($user['name'])) ?></a>
      </div>
    </header>

    <main class="main" id="main">
<?php endif; ?>
