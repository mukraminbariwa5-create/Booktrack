<?php
/**
 * One-time installer: creates the database and loads the demo data from
 * database/booktrack.sql. It refuses to run once the database has users,
 * so it can't wipe a working library.
 */
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
$c = $config['db'];
date_default_timezone_set($config['timezone'] ?? 'UTC');

$status = null;
$error = null;
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    $exists = (bool)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($c['name']) . " AND table_name = 'users'")->fetchColumn();
    $installed = $exists && (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $c['name']) . '`.users')->fetchColumn() > 0;

    if ($installed) {
        $status = 'installed';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $sql = (string)file_get_contents(__DIR__ . '/database/booktrack.sql');
        $sql = str_replace('booktrack_v2', str_replace('`', '', $c['name']), $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
            if (trim($stmt) !== '') $pdo->exec($stmt);
        }
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
<main style="max-width:560px;margin:12vh auto;padding:0 20px">
  <div class="card stack">
    <div class="brand" style="padding:0"><img src="assets/img/logo.png" alt="" width="46"><span class="brand__name">BookTrack setup</span></div>
    <?php if ($error): ?>
      <div class="alert alert--error"><span><strong>Can't connect to MySQL.</strong> Start MySQL in the XAMPP Control Panel and check the settings in <code>config.php</code>.<br><small><?= htmlspecialchars($error) ?></small></span></div>
    <?php elseif ($status === 'installed'): ?>
      <p>BookTrack is already installed. For safety, this page won't overwrite an existing library.</p>
      <p class="muted small">To start over, drop the <code><?= htmlspecialchars($c['name']) ?></code> database in phpMyAdmin first.</p>
      <a class="btn" href="login.php">Go to sign in</a>
    <?php elseif ($status === 'done'): ?>
      <div class="alert alert--info"><span>Database created with demo data.</span></div>
      <p>Sign in as <strong>admin / admin123</strong> (librarian) or <strong>alice / member123</strong> (member). Change these passwords before putting the site online.</p>
      <a class="btn" href="login.php">Go to sign in</a>
    <?php else: ?>
      <p>This will create the <code><?= htmlspecialchars($c['name']) ?></code> database and fill it with demo books, members and loans.</p>
      <form method="post"><button class="btn" type="submit">Create database</button></form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
