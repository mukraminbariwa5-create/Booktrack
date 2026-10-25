<?php
/**
 * Put the database back to the demo data right now (MySQL only), and remove
 * files visitors uploaded. Used by the test suite, and handy as a scheduled task
 * on a demo server. WARNING: this erases every change, on any server.
 *
 *   php tools/reset_demo.php --yes
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';

if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "This erases every change and restores the demo data. Run again with --yes to do it.\n");
    exit(2);
}
if (!db_is_mysql()) {
    fwrite(STDERR, "The demo reset works on MySQL / MariaDB. (Tests on SQLite rebuild their database with tests/make_sqlite.php.)\n");
    exit(1);
}
demo_reset_now();
@file_put_contents(demo_reset_stamp(), today());
echo "Demo data restored: " . (int)q_val('SELECT COUNT(*) FROM books') . " books, " . (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member'") . " members.\n";
