<?php
/**
 * One-time installer: creates the database and loads the demo data from
 * database/booktrack.sql. It refuses to run once the database has users,
 * so it can't wipe a working library.
 */
declare(strict_types=1);
require __DIR__ . '/includes/load_config.php';
require __DIR__ . '/includes/installer.php';
$config = load_config(__DIR__);
$c = $config['db'];
date_default_timezone_set($config['timezone'] ?? 'UTC');

$status = null;
$error = null;
try {
    $pdo = installer_server_pdo($c);
    $exists = (bool)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($c['name']) . " AND table_name = 'users'")->fetchColumn();
    $installed = $exists && (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $c['name']) . '`.users')->fetchColumn() > 0;

    if ($installed) {
        $status = 'installed';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        install_database($pdo, (string)$c['name']);
        $status = 'done';
    } else {
        $status = 'ready';
    }
} catch (PDOException $e) {
    $error = $e->getMessage();
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up BookTrack</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<main class="plain-page">
  <div class="card stack">
    <div class="brand" style="padding:0"><img src="assets/img/logo.png" alt="" width="40" height="40"><span class="brand__name">BookTrack setup</span></div>
    <?php if ($error): ?>
      <div class="alert alert--error"><span><strong>Can't connect to MySQL.</strong> Start MySQL in the XAMPP Control Panel (or check your host's database details) and the settings in <code>config.php</code> or <code>config.local.php</code>.<?php if (!empty($config['debug'])): ?><br><small><?= htmlspecialchars($error) ?></small><?php endif; ?></span></div>
    <?php elseif ($status === 'installed'): ?>
      <p>BookTrack is already installed. For safety, this page won't overwrite an existing library.</p>
      <p class="muted small">To start over, remove BookTrack's tables in phpMyAdmin first.</p>
      <a class="btn" href="login.php">Go to sign in</a>
    <?php elseif ($status === 'done'): ?>
      <div class="alert alert--info"><span>Database created with demo data.</span></div>
      <?php if (!empty($config['demo']['enabled'])): ?>
        <p>The public demo is ready. Visitors use the demo sign-in buttons; the demo accounts are protected and the data is put back every day.</p>
      <?php else: ?>
        <p>Sign in as <strong>admin / admin123</strong> (librarian) or <strong>alice / member123</strong> (member). Change these passwords before putting the site online.</p>
      <?php endif; ?>
      <a class="btn" href="login.php">Go to sign in</a>
    <?php else: ?>
      <p>This will set up the <code><?= htmlspecialchars($c['name']) ?></code> database and fill it with demo books, members and loans.</p>
      <form method="post"><button class="btn" type="submit">Create database</button></form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
