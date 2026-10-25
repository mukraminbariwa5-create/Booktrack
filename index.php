<?php
/**
 * The public front page: what BookTrack is, who built it and how to try the demo.
 * Signed-in people go straight to their own home page.
 */
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if ($user) redirect(home_for($user));

$repo = (string)config('project.repo', '');
$author = (string)config('project.author', '');
$authorUrl = (string)config('project.author_url', '');
$shelf = q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . " ORDER BY (b.cover IS NULL OR b.cover = '') ASC, b.id ASC LIMIT 5");
$counts = [
    'books' => (int)q_val('SELECT COUNT(*) FROM books'),
    'members' => (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member' AND is_active = 1"),
];
$features = [
    ['compass', 'Browse and borrow', 'Search the catalogue, see live availability and borrow in one click. Renew once, or request a return.'],
    ['clock', 'Holds and reminders', 'Join a line when every copy is out. Reminder emails go out before a book is due and when it\'s late.'],
    ['scan', 'The desk', 'Scan a member\'s library card QR code to check books in, lend new ones and see what they owe.'],
    ['alert', 'Late fees', 'Fees grow while a book is late and are fixed at check-in. Librarians mark them paid or waive them.'],
    ['shield', 'Two-step sign-in', 'Authenticator-app codes with one-time recovery codes, on top of rate-limited sign-in.'],
    ['file', 'Reports', 'Export loans, late fees, members and books to spreadsheets, using the filters on screen.'],
];

$title = 'Library management for small libraries';
$bodyClass = 'landing-page';
require __DIR__ . '/includes/partials/header.php';
?>
<div class="landing">
  <header class="landing__bar">
    <a class="auth__brand" href="<?= e(url('index.php')) ?>"><?= logo_mark() ?><span>BookTrack</span></a>
    <nav class="landing__nav" aria-label="Site">
      <?php if ($repo): ?><a class="btn btn--ghost btn--sm" href="<?= e($repo) ?>" rel="noopener">Source code</a><?php endif; ?>
      <a class="btn btn--sm" href="<?= e(url('login.php')) ?>">Sign in</a>
    </nav>
  </header>

  <main id="main">
    <section class="landing__hero" aria-labelledby="hero-h">
      <div class="landing__intro">
        <p class="landing__eyebrow"><?= demo_mode() ? 'Live demo of a portfolio project' : 'Library management' ?></p>
        <h1 id="hero-h">Every book accounted for, <span>without the paperwork.</span></h1>
        <p class="landing__lede">BookTrack is a library system for schools and small libraries. Members browse the catalogue, borrow and renew books and message the library. Librarians run loans, holds, late fees and members from one dashboard.</p>
        <div class="landing__cta">
          <a class="btn btn--lg" href="<?= e(url('login.php')) ?>"><?= demo_mode() ? 'Try the demo' : 'Sign in' ?> <?= icon('arrow-right') ?></a>
          <?php if ($repo): ?><a class="btn btn--outline btn--lg" href="<?= e($repo) ?>" rel="noopener">View the code</a><?php endif; ?>
        </div>
        <?php if (demo_mode()): ?>
          <p class="landing__note"><?= icon('shield') ?><span>No sign-up needed: the demo has one-click librarian and member accounts. It's shared with other visitors and resets every day, so <strong>please don't enter real personal details or a password you use anywhere else.</strong></span></p>
        <?php endif; ?>
      </div>
      <div class="landing__shelf" aria-hidden="true">
        <?php foreach ($shelf as $i => $b): ?>
          <div class="landing__book landing__book--<?= $i ?>"><?= cover_html($b, 'cover') ?></div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="landing__features" aria-labelledby="features-h">
      <h2 id="features-h">What it does</h2>
      <ul>
        <?php foreach ($features as [$icon, $name, $text]): ?>
          <li class="card"><span class="landing__icon"><?= icon($icon) ?></span><h3><?= e($name) ?></h3><p><?= e($text) ?></p></li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="landing__built card" aria-labelledby="built-h">
      <div>
        <h2 id="built-h">How it's built</h2>
        <p>Plain PHP 8 and MySQL, with no framework and a hand-written design system that works on phones and in light and dark themes. Every rule above has automated tests that run on every change, and security is built in: a Content-Security-Policy, CSRF tokens on every form, rate-limited sign-in, hashed reset links and two-step sign-in.</p>
      </div>
      <dl class="landing__facts">
        <div><dt><?= $counts['books'] ?></dt><dd>books in the catalogue</dd></div>
        <div><dt><?= $counts['members'] ?></dt><dd>active members</dd></div>
        <div><dt>0</dt><dd>third-party scripts</dd></div>
      </dl>
    </section>
  </main>

  <footer class="landing__foot">
    <span>&copy; <?= date('Y') ?> BookTrack<?php if ($author): ?>, built by <?= $authorUrl ? '<a class="link" href="' . e($authorUrl) . '" rel="noopener">' . e($author) . '</a>' : e($author) ?><?php endif; ?>.</span>
    <?php if ($repo): ?><a class="link" href="<?= e($repo) ?>" rel="noopener">Source code and security notes on GitHub</a><?php endif; ?>
  </footer>
</div>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
