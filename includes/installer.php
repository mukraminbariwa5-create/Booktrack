<?php
/**
 * Loads database/booktrack.sql (tables + demo data) into a MySQL database.
 * Used by setup.php for a new install and by the daily demo reset.
 *
 * On shared hosting the database usually already exists under a name the host
 * chose (e.g. if0_123_booktrack) and accounts can't create databases, so when
 * the database exists the CREATE DATABASE / USE lines are skipped.
 */

/** A server connection (no database selected), using the config's MySQL settings. */
function installer_server_pdo(array $c, ?string $timezoneOffset = null): PDO
{
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int)$c['port']), $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '" . ($timezoneOffset ?? date('P')) . "'");
    return $pdo;
}

function installer_database_exists(PDO $pdo, string $name): bool
{
    return (bool)$pdo->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ' . $pdo->quote($name))->fetchColumn();
}

/** The SQL statements to run, rewritten for this database name. */
function installer_statements(string $name, bool $createDatabase): array
{
    $sql = (string)file_get_contents(APP_ROOT_INSTALL . '/database/booktrack.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $quoted = '`' . str_replace('`', '', $name) . '`';
    $out = [];
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        if (preg_match('/^CREATE DATABASE\b/i', $stmt)) {
            if (!$createDatabase) continue;
            $stmt = preg_replace('/`booktrack_v2`/', $quoted, $stmt);
        } elseif (preg_match('/^USE\b/i', $stmt)) {
            $stmt = 'USE ' . $quoted;
        }
        $out[] = $stmt;
    }
    return $out;
}

/** Create every table and load the demo data. Existing BookTrack tables are dropped first. */
function install_database(PDO $pdo, string $name): void
{
    $exists = installer_database_exists($pdo, $name);
    foreach (installer_statements($name, !$exists) as $stmt) {
        $pdo->exec($stmt);
    }
}

if (!defined('APP_ROOT_INSTALL')) define('APP_ROOT_INSTALL', dirname(__DIR__));
