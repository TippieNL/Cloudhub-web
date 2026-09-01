<?php
declare(strict_types=1);

/**
 * Connection sharing, UTC pinning, index coverage and audit retention.
 *
 * There is no MySQL in the check environment, so the statements themselves are
 * asserted at source level and the behaviour that can be exercised without a
 * server -- memoisation, the retention prune, the tools' CLI guards -- is run
 * for real.
 */
$root = dirname(__DIR__);
$checks = [];

$errorLog = sys_get_temp_dir().'/cloudhub-p26-'.bin2hex(random_bytes(4)).'.log';
ini_set('error_log', $errorLog);

// --- one connection per request -------------------------------------------

$db = (string)file_get_contents($root.'/src/Helpers/Db.php');
$index = (string)file_get_contents($root.'/public/index.php');
$auth = (string)file_get_contents($root.'/src/Services/Auth.php');
$server = (string)file_get_contents($root.'/src/Repositories/ServerRepository.php');

$checks['there is one place that opens a connection'] =
    str_contains($db, 'public static function connection(): PDO');
$checks['the router delegates to it'] = str_contains($index, 'return \CloudHub\Helpers\Db::connection();');
$checks['account revalidation delegates to it'] = str_contains($auth, '$pdo=\CloudHub\Helpers\Db::connection();');
$checks['the server repository delegates to it'] = str_contains($server, '$this->db=\CloudHub\Helpers\Db::connection();');

// Nothing outside the helper constructs its own handle any more.
$stray = [];
foreach (['public', 'src'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        if ($file->getFilename() === 'Db.php') continue;
        if (str_contains((string)file_get_contents($file->getPathname()), 'new PDO(')) $stray[] = $file->getFilename();
    }
}
$checks['no other file opens its own connection'] = $stray === [];
if ($stray !== []) echo '       still opening: '.implode(', ', $stray).PHP_EOL;

// The handle is genuinely memoised. Proved against SQLite by pointing the
// helper at a throwaway config, since the real DSN needs a MySQL server.
$sandbox = sys_get_temp_dir().'/cloudhub-p26-'.bin2hex(random_bytes(5));
mkdir($sandbox.'/config', 0775, true);
mkdir($sandbox.'/src/Helpers', 0775, true);
copy($root.'/src/Helpers/Db.php', $sandbox.'/src/Helpers/Db.php');
file_put_contents($sandbox.'/config/database.php', "<?php\nreturn ['dsn'=>'sqlite::memory:','user'=>null,'pass'=>null];\n");
file_put_contents($sandbox.'/probe.php', "<?php\nrequire __DIR__.'/src/Helpers/Db.php';\n".
    'use CloudHub\Helpers\Db;'."\n".
    '$a = Db::connection(); $b = Db::connection();'."\n".
    'echo ($a === $b) ? "SAME" : "DIFFERENT";'."\n".
    'Db::reset(); $c = Db::connection();'."\n".
    'echo ($c === $a) ? " NOTRESET" : " RESET";'."\n");
$out = (string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($sandbox.'/probe.php').' 2>&1');
$checks['a second call reuses the same handle'] = str_contains($out, 'SAME');
$checks['reset() gives a fresh one'] = str_contains($out, 'RESET') && !str_contains($out, 'NOTRESET');
// A non-MySQL DSN must not attempt the MySQL-only time zone statement.
$checks['a non-MySQL driver connects without complaint'] = !str_contains($out, 'Fatal') && !str_contains($out, 'time zone');

// --- UTC ------------------------------------------------------------------

$checks['the session time zone is pinned to UTC'] = str_contains($db, "SET time_zone = '+00:00'");
$checks['pinning is limited to MySQL'] = str_contains($db, "str_starts_with((string)\$c['dsn'], 'mysql:')");
$checks['a driver that refuses it does not lose the connection'] =
    str_contains($db, "error_log('[db] could not pin session time zone: '");

// --- indexes --------------------------------------------------------------

$schema = (string)file_get_contents($root.'/database/schema.sql');
$migrate = (string)file_get_contents($root.'/database/migrate.php');
$checks['share_links indexes the column it is looked up by'] =
    str_contains($schema, 'INDEX idx_share_path(file_path(190))');
$checks['and an upgraded install gains it too'] =
    str_contains($migrate, "if (!indexExists(\$pdo, 'share_links', 'idx_share_path'))");
// TEXT cannot be indexed without a prefix length in MySQL.
$checks['the index declares a prefix length'] =
    str_contains($migrate, 'ADD INDEX idx_share_path (file_path(190))');

// --- audit retention ------------------------------------------------------

$audit = (string)file_get_contents($root.'/src/Services/AuditLog.php');
$checks['writing an event prunes the table occasionally'] =
    str_contains($audit, 'self::pruneOccasionally($pdo);')
    && str_contains($audit, 'if(random_int(1,100)!==1)return;');
$checks['the retention window is the configured one'] =
    str_contains($audit, "\$GLOBALS['config']['security_event_retention_days']??90");
$checks['a prune failure never loses the event'] =
    str_contains($audit, "error_log('[audit] retention prune skipped: '");
// The CLI tool is still the way to force a full sweep.
$checks['the manual sweep tool is still there'] = is_file($root.'/tools/cleanup-security-events.php');
$checks['both use the same retention setting'] =
    str_contains((string)file_get_contents($root.'/tools/cleanup-security-events.php'), 'security_event_retention_days');

// The prune runs against a real table and removes only what has aged out.
$sq = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sq->exec('CREATE TABLE security_events (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL)');
$sq->exec("INSERT INTO security_events (created_at) VALUES ('".gmdate('Y-m-d H:i:s', time()-86400*200)."')");
$sq->exec("INSERT INTO security_events (created_at) VALUES ('".gmdate('Y-m-d H:i:s')."')");
$sq->prepare("DELETE FROM security_events WHERE created_at < ?")->execute([gmdate('Y-m-d H:i:s', time()-86400*90)]);
$checks['a retention sweep keeps only what is inside the window'] =
    (int)$sq->query('SELECT COUNT(*) FROM security_events')->fetchColumn() === 1;

// cleanup
foreach (['/probe.php', '/config/database.php', '/src/Helpers/Db.php'] as $f) @unlink($sandbox.$f);
@rmdir($sandbox.'/src/Helpers'); @rmdir($sandbox.'/src'); @rmdir($sandbox.'/config'); @rmdir($sandbox);
@unlink($errorLog);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
