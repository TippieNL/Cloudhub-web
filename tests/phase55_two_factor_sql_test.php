<?php
declare(strict_types=1);

/**
 * database/migrations/20261009_two_factor_email.sql says what migrate.php does.
 *
 * The SQL file is for hosts without a PHP command line: it is pasted into
 * phpMyAdmin or Adminer instead of running the migration. Two copies of a
 * schema drift, and a drifted one fails quietly -- a column one character
 * shorter, an ENUM missing a value, and two-step verification refuses every
 * request on that installation only. So the tables, columns and ENUMs it
 * creates are compared with migrate.php's, and it is held to statements both
 * MySQL and MariaDB accept from a web SQL tab.
 */
$root = dirname(__DIR__);
$migrate = (string)file_get_contents($root.'/database/migrate.php');
$sql = (string)file_get_contents($root.'/database/migrations/20261009_two_factor_email.sql');
$checks = [];

$checks['the text-message SQL is gone, so it cannot be run by mistake'] = !is_file($root.'/database/migrations/20261009_two_factor.sql');

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
foreach (['two_factor_email', 'two_factor_enabled_at'] as $column) {
    $defined = preg_match("/addColumn\\(\\\$pdo, 'users', '$column', '([^']+)'\\)/", $migrate, $m) ? $m[1] : null;
    $checks["users.$column is added as migrate.php adds it, only when missing"] = $defined !== null
        && str_contains($sql, "COLUMN_NAME='$column'")
        && str_contains($sql, "'ALTER TABLE users ADD COLUMN $column $defined'");
}
$checks['users.two_factor_phone is no longer added'] = !str_contains($migrate, "'two_factor_phone', 'VARCHAR")
    && !preg_match('/ADD COLUMN two_factor_phone/i', $sql);

// Every database ends with the same scope values: migrate.php's, and the
// CREATE TABLE's in both.
$scopes = preg_match('/\$scopes = "([^"]+)";/', $migrate, $m) ? $m[1] : null;
$created = preg_match("/scope ENUM\\(([^)]*)\\) NOT NULL,/i", (string)$table($migrate, 'login_attempts'), $m) ? $m[1] : null;
$checks['the final scope values are the ones a new table gets'] = $scopes !== null && strtolower($scopes) === $created
    && str_contains($sql, "'ALTER TABLE login_attempts MODIFY scope ENUM(".str_replace("'", "''", $scopes).") NOT NULL'");
// A database set up for text-message codes passes through the same union.
$union = preg_match("/MODIFY scope ENUM\\(('user','ip','verify_user','verify_ip','sms_user'[^)]*)\\) NOT NULL\"\\)/", $migrate, $m) ? $m[1] : null;
$checks['the text-message values are kept until their rows are gone, as in migrate.php'] = $union !== null
    && str_contains($union, "'email_address'") && str_contains($sql, 'MODIFY scope ENUM('.str_replace("'", "''", $union).') NOT NULL')
    && str_contains($sql, "'DELETE FROM login_attempts WHERE scope IN (''sms_user'',''sms_ip'',''sms_phone'')'")
    && str_contains($migrate, "DELETE FROM login_attempts WHERE scope IN ('sms_user','sms_ip','sms_phone')");
// The union is added, then the rows deleted, then the values narrowed.
$at = static fn(string $needle): int|false => strpos($sql, $needle);
$checks['in that order'] = $at("''sms_phone'',''email_user''") !== false && $at('DELETE FROM login_attempts') !== false
    && $at("''sms_phone'',''email_user''") < $at('DELETE FROM login_attempts')
    && $at('DELETE FROM login_attempts') < strrpos($sql, "MODIFY scope ENUM(''user'',''ip'',''verify_user'',''verify_ip'',''email_user''");

// Runs as pasted, in either database, in either quoting mode. The comments
// name what is avoided, so only the statements are read.
$statements = (string)preg_replace('/^--.*$/m', '', $sql);
$checks['no syntax only one of MySQL and MariaDB has'] = !preg_match('/ADD COLUMN IF NOT EXISTS|DELIMITER|CREATE PROCEDURE/i', $statements);
$checks['no double-quoted strings, which ANSI_QUOTES reads as names'] = !str_contains($statements, '"');
$checks['no table or column is dropped, and only text-message throttle rows are deleted'] = !preg_match('/\b(DROP|TRUNCATE)\b/i', $statements)
    && preg_match_all('/\bDELETE\b/i', $statements) === 1 && str_contains($statements, "DELETE FROM login_attempts WHERE scope IN (''sms_user'',''sms_ip'',''sms_phone'')");

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
