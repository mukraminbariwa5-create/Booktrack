<?php
/**
 * Database access (PDO). One shared connection per request.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $c = config('db');
    try {
        if ($c['driver'] === 'sqlite') {
            $pdo = new PDO('sqlite:' . $c['sqlite_path']);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
            $pdo = new PDO($dsn, $c['user'], $c['pass']);
            // keep MySQL's clock in step with PHP's timezone
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        }
    } catch (PDOException $e) {
        http_response_code(500);
        $hint = config('debug')
            ? htmlspecialchars($e->getMessage())
            : 'Please try again later.';
        exit('<!doctype html><meta charset="utf-8"><title>Database unavailable</title>'
            . '<div style="font-family:system-ui;max-width:560px;margin:15vh auto;padding:0 24px;color:#1E2A3A">'
            . '<h1 style="font-weight:600">Can\'t reach the database</h1>'
            . '<p>BookTrack could not connect to MySQL. Make sure MySQL is running in XAMPP, then open '
            . '<a href="' . htmlspecialchars(url('setup.php')) . '">setup.php</a> to create the database '
            . '(or import <code>database/booktrack.sql</code> in phpMyAdmin).</p>'
            . '<pre style="white-space:pre-wrap;background:#F3EEE4;padding:12px;border-radius:8px">' . $hint . '</pre></div>');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

function db_is_mysql(): bool
{
    return config('db.driver') !== 'sqlite';
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Row-lock clause for SELECTs inside a transaction (MySQL only). */
function for_update(): string
{
    return db_is_mysql() ? ' FOR UPDATE' : '';
}

function transaction(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
