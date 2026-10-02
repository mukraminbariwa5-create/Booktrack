<?php
/**
 * Shared frame of the sign-in / sign-up pages: the form panel on the left and,
 * on wide screens, a slowly drifting wall of the library's own book covers with
 * a rotating spotlight card. Expects $title.
 */
$wallBooks = q_all('SELECT title, author, cover FROM books ORDER BY id');
$wallCols = [];
if ($wallBooks) {
    // at least 5 per column so every column can loop seamlessly
    while (count($wallBooks) < 25) $wallBooks = array_merge($wallBooks, $wallBooks);
    foreach (array_slice($wallBooks, 0, 30) as $i => $b) $wallCols[$i % 5][] = $b;
}
// A few books to feature in the rotating card, real covers first.
$spotlight = q_all('SELECT title, author, category, cover FROM books');
shuffle($spotlight);
usort($spotlight, fn($a, $b) => (int)empty($a['cover']) <=> (int)empty($b['cover']));
$spotlight = array_slice($spotlight, 0, 5);
?>
<a class="skip-link" href="#auth-main">Skip to form</a>
<div class="auth">
  <div class="auth__panel">
    <a class="auth__brand" href="<?= e(url('login.php')) ?>">
      <img src="<?= e(asset('img/logo.png')) ?>" alt="" width="44" height="26">
      <span>BookTrack</span>
    </a>
    <button class="btn btn--ghost btn--icon auth__theme" type="button" data-theme-toggle aria-label="Switch to <?= $theme === 'light' ? 'dark' : 'light' ?> theme" title="Switch theme"><?= icon($theme === 'light' ? 'moon' : 'sun') ?></button>
    <main class="auth__main" id="auth-main">
