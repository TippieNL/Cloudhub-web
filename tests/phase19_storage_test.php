<?php
declare(strict_types=1);

/**
 * Storage dashboard and quotas.
 *
 * `file_metadata` was declared in schema.sql from the beginning and referenced
 * by no PHP at all, so nothing recorded which account a file came from -- and
 * without that a per-user quota cannot be computed, only guessed at. It is now
 * an upload ledger, and the administrator screen reports what is actually on
 * disk rather than what someone believes is on disk.
 *
 * The filesystem and ledger halves run for real: the ledger against an
 * in-memory SQLite database with the same columns MySQL gets from
 * database/migrate.php. Route and UI wiring is asserted against source.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Repositories/StorageLedger.php';
use CloudHub\Services\FileService;
use CloudHub\Repositories\StorageLedger;

$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$view = (string)file_get_contents($root.'/views/pages/app.php');
$conf = (string)file_get_contents($root.'/config/config.php');
$env = (string)file_get_contents($root.'/.env.example');
$schema = (string)file_get_contents($root.'/database/schema.sql');
$migrate = (string)file_get_contents($root.'/database/migrate.php');
$ledgerSrc = (string)file_get_contents($root.'/src/Repositories/StorageLedger.php');

function rmrf(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf($p.'/'.$n); @rmdir($p); }
}

$base = sys_get_temp_dir().'/cloudhub-p19-'.bin2hex(random_bytes(5));
mkdir($base.'/Photos/2024', 0775, true);
mkdir($base.'/Videos', 0775, true);
file_put_contents($base.'/Photos/a.jpg', str_repeat('i', 400));
file_put_contents($base.'/Photos/2024/b.png', str_repeat('i', 100));
file_put_contents($base.'/Videos/c.mp4', str_repeat('v', 1000));
file_put_contents($base.'/notes.txt', str_repeat('d', 50));
file_put_contents($base.'/blob', str_repeat('o', 7));
$fs = new FileService(['root_dir' => $base, 'read_only' => false]);

$checks = [];

// --- the report ---------------------------------------------------------
$report = $fs->storageReport();
$checks['the report totals every file in the tree'] = $report['bytes'] === 1557 && $report['files'] === 5;
$checks['folders are ranked by size'] =
    array_column($report['folders'], 'name') === ['Videos', 'Photos'];
$checks['a folder total includes its subfolders'] =
    $report['folders'][1]['bytes'] === 500 && $report['folders'][1]['files'] === 2;
$checks['the largest files come first'] =
    array_column($report['largest'], 'path') === ['/Videos/c.mp4', '/Photos/a.jpg', '/Photos/2024/b.png', '/notes.txt', '/blob'];
$checks['the largest list is capped'] = count($fs->storageReport(2)['largest']) === 2;
$checks['sizes are grouped by kind'] =
    $report['byType'] === ['video' => 1000, 'image' => 500, 'document' => 50, 'other' => 7];
$checks['unknown extensions are not miscategorised'] = FileService::fileCategory('/x/y.qqq') === 'other';
$checks['category matching ignores case'] = FileService::fileCategory('/x/Y.JPEG') === 'image';
$checks['the disk figures are reported'] = $report['diskTotal'] > 0 && $report['diskFree'] > 0;

// The trash is space you can reclaim, not space you are using, so it must not
// inflate the browsable total.
$fs->trash($base.'/Videos/c.mp4', 'tester');
$after = $fs->storageReport();
$checks['trashed bytes leave the browsable total'] = $after['bytes'] === 557 && $after['files'] === 4;
// Summed from what each entry recorded, so the bookkeeping file beside the
// payload is not reported to the administrator as reclaimable space.
$checks['trashed bytes are reported separately'] =
    $after['trash']['bytes'] === 1000 && $after['trash']['files'] === 1 && $after['trash']['entries'] === 1;
$checks['the trash never appears as a folder'] =
    !in_array('.trash', array_column($after['folders'], 'name'), true);
$fs->trashPurge(null);

// --- copy attribution ---------------------------------------------------
$checks['a copied file is enumerated'] = $fs->copiedFiles($base.'/notes.txt') === [$base.'/notes.txt'];
$checks['a copied folder enumerates its whole tree'] = (function () use ($fs, $base): bool {
    $found = $fs->copiedFiles($base.'/Photos');
    sort($found);
    return $found === [$base.'/Photos/2024/b.png', $base.'/Photos/a.jpg'];
})();

// --- the ledger, against a real database --------------------------------
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE storage_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, is_default INTEGER DEFAULT 0)');
$db->exec("INSERT INTO storage_servers (name, is_default) VALUES ('local', 1)");
$db->exec('CREATE TABLE file_metadata (id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL,
    file_path TEXT NOT NULL, original_name TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0,
    mime_type TEXT NULL, uploaded_by INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$ledger = new StorageLedger($db);

$ledger->record('/Photos/a.jpg', 'a.jpg', 400, 'image/jpeg', 7);
$ledger->record('/Photos/2024/b.png', 'b.png', 100, 'image/png', 7);
$ledger->record('/notes.txt', 'notes.txt', 50, 'text/plain', 9);
$checks['usage is attributed per account'] = $ledger->usage(7) === 500 && $ledger->usage(9) === 50;
$checks['total usage sums every account'] = $ledger->usage() === 550;

$ledger->record('/notes.txt', 'notes.txt', 80, 'text/plain', 9);
$checks['an overwrite replaces the row rather than doubling it'] = $ledger->usage(9) === 80;

$ledger->relocate('/Photos/a.jpg', '/Videos/a.jpg');
$checks['a rename follows the file'] = $ledger->usage(7) === 500 && (function () use ($db): bool {
    return (string)$db->query("SELECT file_path FROM file_metadata WHERE original_name='a.jpg'")->fetchColumn() === '/Videos/a.jpg';
})();

$ledger->relocate('/Photos', '/Archive/Photos');
$checks['moving a folder rewrites every path beneath it'] =
    (string)$db->query("SELECT file_path FROM file_metadata WHERE original_name='b.png'")->fetchColumn() === '/Archive/Photos/2024/b.png';

// LIKE has no default escape character in SQLite, so an underscore in an
// ordinary filename would act as a wildcard and rewrite unrelated rows.
$ledger->record('/a_b/one.txt', 'one.txt', 10, null, 1);
$ledger->record('/axb/two.txt', 'two.txt', 20, null, 1);
$ledger->relocate('/a_b', '/moved');
$checks['a wildcard character in a folder name matches literally'] =
    (string)$db->query("SELECT file_path FROM file_metadata WHERE original_name='two.txt'")->fetchColumn() === '/axb/two.txt';

$ledger->forget('/Archive/Photos');
$checks['forgetting a folder drops everything beneath it'] = $ledger->usage(7) === 400;

$byUser = $ledger->usageByUser();
$checks['usage per account is ranked'] = $byUser[0]['bytes'] >= $byUser[1]['bytes'];

$checks['a missing server means no row rather than an error'] = (function (): bool {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE file_metadata (id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL,
        file_path TEXT NOT NULL, original_name TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0,
        mime_type TEXT NULL, uploaded_by INTEGER NULL)');
    $l = new StorageLedger($db);
    $l->record('/x.txt', 'x.txt', 10, null, 1);
    return (int)$db->query('SELECT COUNT(*) FROM file_metadata')->fetchColumn() === 0;
})();

// Fail open: a broken ledger must mean "the quota does not bind", never that a
// legitimate upload is refused.
$checks['an unusable ledger reports zero rather than throwing'] = (function (): bool {
    // The ledger logs what it swallows; this check breaks it deliberately, so
    // send that logging somewhere other than the test's own output.
    $previous = ini_set('error_log', tempnam(sys_get_temp_dir(), 'cfh-ledger-'));
    try {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $l = new StorageLedger($db);   // no tables at all
        return $l->usage(1) === 0 && $l->usageByUser() === [];
    } finally {
        if ($previous !== false) ini_set('error_log', $previous);
    }
})();
$checks['every ledger method swallows its errors'] =
    substr_count($ledgerSrc, 'catch (Throwable') >= 6;

// The sweep is the backstop for writes CloudHub does not see.
$sweepDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sweepDb->exec('CREATE TABLE storage_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, is_default INTEGER DEFAULT 0)');
$sweepDb->exec("INSERT INTO storage_servers (name, is_default) VALUES ('local', 1)");
$sweepDb->exec('CREATE TABLE file_metadata (id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL,
    file_path TEXT NOT NULL, original_name TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0,
    mime_type TEXT NULL, uploaded_by INTEGER NULL)');
$sweeper = new StorageLedger($sweepDb);
$sweeper->record('/notes.txt', 'notes.txt', 50, null, 1);
$sweeper->record('/gone-by-another-route.txt', 'gone.txt', 999, null, 1);
$checks['the sweep drops rows whose file has disappeared'] =
    $sweeper->sweep($fs) === 1 && $sweeper->usage(1) === 50;

rmrf($base);

// --- the route ----------------------------------------------------------
$checks['the usage route is administrator-only'] = (function () use ($index): bool {
    $at = strpos($index, "'/api/storage/usage' && \$method === 'GET'");
    return $at !== false && str_contains(substr($index, $at, 200), 'Authorization::requireAdmin()');
})();
$checks['the measurement is cached'] =
    str_contains($index, "function storage_report(FileService \$fs, array \$config, bool \$force = false)")
    && str_contains($index, "time() - (int)filemtime(\$cache) < \$ttl");
$checks['the cache can be forced fresh'] = str_contains($index, "storage_report(\$fs, \$config, !empty(\$_GET['refresh']))");
$checks['the cache lives outside the storage root'] =
    str_contains($index, "dirname(__DIR__).'/storage/.cache/usage.json'");
$checks['the usage route sweeps stale ledger rows'] = (function () use ($index): bool {
    $at = strpos($index, "'/api/storage/usage' && \$method === 'GET'");
    return $at !== false && str_contains(substr($index, $at, 1200), 'ledger()->sweep($fs);');
})();

// --- quotas -------------------------------------------------------------
$checks['both limits are checked before anything is staged'] = (function () use ($index): bool {
    $at = strpos($index, "'/api/uploads/init' && \$method === 'POST'");
    if ($at === false) return false;
    $body = substr($index, $at, 600);
    return strpos($body, 'assert_upload_fits') < strpos($body, 'uploads()->init');
})();
$checks['the whole-store limit is enforced'] = str_contains($index, "The file store is full");
$checks['the per-account quota is enforced'] = str_contains($index, "of your '.human_bytes(\$quota).' quota");
$checks['both limits are opt-in'] =
    str_contains($index, 'if ($limit > 0) {') && str_contains($index, 'if ($quota > 0 && $user !== null) {');
// "Insufficient storage" is a 5xx, and 5xx messages are masked -- but the
// caller cannot act on "an internal server error occurred".
$checks['a quota refusal explains itself'] =
    str_contains($index, "507 => 'INSUFFICIENT_STORAGE'")
    && str_contains($index, '$known = isset($codes[$status]);')
    && str_contains($index, "\$msg = (\$status >= 500 && !\$known)?'An internal server error occurred':\$e->getMessage();");
$checks['limits below a gigabyte still read sensibly'] = str_contains($index, 'function human_bytes(int $n): string');

// --- ledger maintenance points -----------------------------------------
foreach ([
    'an upload is attributed' => "ledger()->record((string)\$done['path'], (string)\$done['name'],",
    'a permanent delete forgets its row' => "\$fs->deleteTree(\$p);\n        ledger()->forget(\$rel);",
    'trashing forgets its row' => "ledger()->forget(\$meta['originalPath']);",
    'a move carries attribution with it' => "if (\$verb === 'move') {\n                ledger()->relocate(",
    'a rename carries attribution with it' => "ledger()->relocate(\$from, \$fs->relative(\$z));",
    'a restore reconciles by sweeping' => "\$restored = \$fs->restore(Http::string(\$b, 'id', 1, 64));\n    // The file is back on disk",
] as $name => $needle) {
    $checks[$name] = str_contains($index, $needle);
}
// A quota that is avoided by uploading one file and copying it a hundred times
// is not a quota.
$checks['a copy is charged to whoever made it'] =
    str_contains($index, 'foreach ($fs->copiedFiles($target) as $copied) {');

// --- schema -------------------------------------------------------------
$checks['fresh installs record the uploader'] = str_contains($schema, 'uploaded_by INT UNSIGNED NULL');
$checks['existing installs gain the column'] =
    str_contains($migrate, "addColumn(\$pdo, 'file_metadata', 'uploaded_by', 'INT UNSIGNED NULL');");
$checks['per-account sums are indexed'] =
    str_contains($migrate, 'idx_file_uploader') && str_contains($schema, 'idx_file_uploader(uploaded_by)');
$checks['the column is nullable so nobody is wrongly blamed'] =
    str_contains($schema, 'uploaded_by INT UNSIGNED NULL') && !str_contains($schema, 'uploaded_by INT UNSIGNED NOT NULL');

// --- configuration ------------------------------------------------------
$checks['the limits are configurable'] =
    str_contains($conf, "'storage_limit_gb'=>(float)env('STORAGE_LIMIT_GB',0)")
    && str_contains($conf, "'user_quota_gb'=>(float)env('USER_QUOTA_GB',0)")
    && str_contains($conf, "'usage_cache_seconds'=>(int)env('USAGE_CACHE_SECONDS',300)");
$checks['the limits are documented'] =
    str_contains($env, 'STORAGE_LIMIT_GB=0') && str_contains($env, 'USER_QUOTA_GB=0');

// --- UI -----------------------------------------------------------------
$checks['a Storage page exists'] =
    str_contains($view, 'id="storage-page"') && str_contains($app, 'async function loadStorage(');
$checks['the Storage nav entry is admin-only'] =
    str_contains($view, 'id="nav-storage"') && substr_count($app, "\$('#nav-storage').hidden = S.role !== 'admin';") === 2;
$checks['/storage is a page route'] =
    (bool)preg_match("/in_array\(\\\$path, \[(.*?)\], true\)/", $index, $pages)
    && str_contains($pages[1], "'/storage'")
    && str_contains($app, "(p === '/storage')");
$checks['the screen says how old the figure is'] =
    str_contains($app, 'd.cached') && str_contains($app, 'Measured just now');
$checks['a recalculate control forces a fresh measurement'] =
    str_contains($view, 'id="recalculate-usage"') && str_contains($app, "loadStorage(true)");
$checks['the usage bar warns before it is full'] =
    str_contains($app, "pct >= 90 ? ' critical' : pct >= 75 ? ' warning' : ''");
$checks['clipped paths stay readable'] = str_contains($app, 'title="${esc(r[0])}"');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
