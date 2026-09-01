<?php
declare(strict_types=1);
namespace CloudHub\Helpers;

use PDO;

/**
 * The request's one database connection.
 *
 * Three places used to open their own -- the db() helper in public/index.php,
 * Auth's account revalidation, and ServerRepository's constructor -- so a
 * single request could pay for three MySQL handshakes. That is a poor trade on
 * a phone running MySQL under KSWEB, where connection setup is a visible part
 * of the response time.
 *
 * The connection is also pinned to UTC. Nothing set a session time zone, while
 * AuditLog writes UTC_TIMESTAMP() and LoginRateLimiter writes gmdate() -- so
 * every column defaulting to CURRENT_TIMESTAMP was written in whatever zone the
 * server happened to run in, and read back alongside values that were UTC. The
 * two agree now.
 */
final class Db {
    private static ?PDO $pdo = null;

    public static function connection(): PDO {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $c = require dirname(__DIR__, 2).'/config/database.php';
        $pdo = new PDO((string)$c['dsn'], (string)$c['user'], (string)$c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // ServerRepository reads rows by column name off the default, and
            // nothing in the codebase indexes a row numerically.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Best effort: a driver that does not understand the statement must not
        // take the connection down with it.
        if (str_starts_with((string)$c['dsn'], 'mysql:')) {
            try { $pdo->exec("SET time_zone = '+00:00'"); }
            catch (\Throwable $e) { error_log('[db] could not pin session time zone: '.$e->getMessage()); }
        }

        return self::$pdo = $pdo;
    }

    /** Drop the memoised handle. Used by tests; the app opens one per request. */
    public static function reset(): void { self::$pdo = null; }
}
