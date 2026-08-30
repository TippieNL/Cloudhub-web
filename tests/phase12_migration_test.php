<?php
declare(strict_types=1);

/**
 * database/migrate.php is the documented upgrade path, so it must provision
 * everything the runtime actually queries.
 *
 * It previously created neither login_attempts nor security_events and never
 * added users.role. LoginRateLimiter::assertAllowed() runs on every login and
 * selects from login_attempts, so a database upgraded with migrate.php alone
 * answered every login attempt with a PDOException surfacing as HTTP 500.
 *
 * No MySQL server is available in this environment, so these are source-level
 * assertions over the migration script rather than a live schema diff.
 */
$root = dirname(__DIR__);
$migrate = (string)file_get_contents($root.'/database/migrate.php');
$schema = (string)file_get_contents($root.'/database/schema.sql');
$limiter = (string)file_get_contents($root.'/src/Services/LoginRateLimiter.php');
$audit = (string)file_get_contents($root.'/src/Services/AuditLog.php');
$auth = (string)file_get_contents($root.'/src/Services/Auth.php');

/** Tables the migration must create, discovered from schema.sql. */
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $schema, $m);
$schemaTables = array_unique($m[1]);

$checks = [];

foreach ($schemaTables as $table) {
    $checks["migrate.php creates $table"] =
        (bool)preg_match('/CREATE TABLE IF NOT EXISTS\s+'.preg_quote($table, '/').'\b/i', $migrate);
}

// The tables the runtime queries by name must be among them.
$checks['login_attempts is queried by the throttle'] = str_contains($limiter, 'login_attempts');
$checks['security_events is queried by the audit log'] = str_contains($audit, 'security_events');

$checks['migrate.php adds users.role'] =
    (bool)preg_match("/addColumn\(\\\$pdo,\s*'users',\s*'role'/", $migrate);
$checks['role migration promotes an existing admin'] =
    str_contains($migrate, "UPDATE users SET role = 'admin' WHERE username = ?");
$checks['login reads the role column'] = str_contains($auth, 'role FROM users');

// Phase 4 removed the predefined account; migrate.php must not reintroduce it.
$checks['migrate.php seeds no password hash'] =
    !str_contains($migrate, '$2y$') && !preg_match('/INSERT INTO users/i', $migrate);
$checks['schema.sql seeds no user'] = !preg_match('/INSERT INTO users/i', $schema);
$checks['migrate.php points at create-admin'] = str_contains($migrate, 'tools/create-admin.php');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
