<?php
declare(strict_types=1);

/**
 * database/migrations/20261009_two_factor.sql says what migrate.php does.
 *
 * The SQL file is for hosts without a PHP command line: it is pasted into
 * phpMyAdmin or Adminer instead of running the migration. Two copies of a
 * schema drift, and a drifted one fails quietly -- a column one character
 * shorter, an ENUM missing a value, and two-step verification refuses every
 * request on that installation only. So the tables, columns and ENUM it
 * creates are compared with migrate.php's, and it is held to statements both
 * MySQL and MariaDB accept from a web SQL tab.
 */
$root = dirname(__DIR__);
$migrate = (string)file_get_contents($root.'/database/migrate.php');
$sql = (string)file_get_contents($root.'/database/migrations/20261009_two_factor.sql');
$checks = [];

$squash = static fn(string $s): string => strtolower(trim((string)preg_replace('/\s+/', ' ', $s)));
$table = static function (string $source, string $name) use ($squash): ?string {
    return preg_match('/CREATE TABLE IF NOT EXISTS\s+'.$name.'\s*\((.*?)\)\s*ENGINE=([^;"]+)/si', $source, $m)
        ? $squash($m[1].' engine='.$m[2]) : null;
};

foreach (['two_factor_challenges', 'two_factor_recovery_codes', 'login_attempts'] as $name) {
    $want = $table($migrate, $name);
    $checks["$name is created exactly as migrate.php creates it"] = $want !== null && $table($sql, $name) === $want;
}

// The columns on users, with migrate.php's definitions.
foreach (['two_factor_phone', 'two_factor_enabled_at'] as $column) {
    $defined = preg_match("/addColumn\\(\\\$pdo, 'users', '$column', '([^']+)'\\)/", $migrate, $m) ? $m[1] : null;
    $checks["users.$column is added as migrate.php adds it, only when missing"] = $defined !== null
        && str_contains($sql, "COLUMN_NAME='$column'")
        && str_contains($sql, "'ALTER TABLE users ADD COLUMN $column $defined'");
}

// An installation whose login_attempts predates the new scopes gets them.
$enum = preg_match("/MODIFY scope ENUM\\(([^)]*)\\) NOT NULL\"\\)/", $migrate, $m) ? $m[1] : null;
$checks['the scope ENUM is extended to the same values'] = $enum !== null
    && str_contains($sql, 'MODIFY scope ENUM('.str_replace("'", "''", $enum).') NOT NULL')
    && str_contains($sql, "LOCATE('''sms_phone''', @type)=0");

// Runs as pasted, in either database, in either quoting mode. The comments
// name what is avoided, so only the statements are read.
$statements = (string)preg_replace('/^--.*$/m', '', $sql);
$checks['no syntax only one of MySQL and MariaDB has'] = !preg_match('/ADD COLUMN IF NOT EXISTS|DELIMITER|CREATE PROCEDURE/i', $statements);
$checks['no double-quoted strings, which ANSI_QUOTES reads as names'] = !str_contains($statements, '"');
$checks['nothing is dropped or deleted'] = !preg_match('/\b(DROP|DELETE|TRUNCATE)\b/i', $statements);

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
