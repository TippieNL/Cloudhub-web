<?php
declare(strict_types=1);

/**
 * Upload session handling and ledger write integrity.
 *
 * The ledger halves run against a real in-memory SQLite database, the upload
 * halves against a real staging tree -- both of these classes of bug looked
 * reasonable in the source and only showed themselves when run.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Repositories/StorageLedger.php';

use CloudHub\Services\FileService;
use CloudHub\Repositories\StorageLedger;

function rmrf25(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf25($p.'/'.$n); @rmdir($p); }
}

$root = dirname(__DIR__);
$checks = [];

// This script provokes failures on purpose, and the ledger reports them
// through error_log(). Pointed at a file so the deliberate messages do not
// land in the runner's output, which fails a script that emits diagnostics.
$errorLog = sys_get_temp_dir().'/cloudhub-p25-errors-'.bin2hex(random_bytes(4)).'.log';
ini_set('error_log', $errorLog);

// --- ledger: record() and relocate() apply as a unit ----------------------

$makeDb = static function(): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE file_metadata (
        id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL, file_path TEXT NOT NULL,
        original_name TEXT NOT NULL, size INTEGER NOT NULL, mime_type TEXT, uploaded_by INTEGER)');
    $db->exec('CREATE TABLE storage_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, is_default INTEGER)');
    $db->exec("INSERT INTO storage_servers (is_default) VALUES (1)");
    return $db;
};

$db = $makeDb();
$ledger = new StorageLedger($db);
$ledger->record('/a.txt', 'a.txt', 100, 'text/plain', 7);
$checks['a recorded file is charged once'] = (int)$db->query("SELECT COUNT(*) FROM file_metadata WHERE file_path='/a.txt'")->fetchColumn() === 1;

// An overwrite replaces the row rather than adding a second one.
$ledger->record('/a.txt', 'a.txt', 250, 'text/plain', 7);
$checks['an overwrite leaves exactly one row'] = (int)$db->query("SELECT COUNT(*) FROM file_metadata WHERE file_path='/a.txt'")->fetchColumn() === 1;
$checks['and the row carries the new size'] = (int)$db->query("SELECT size FROM file_metadata WHERE file_path='/a.txt'")->fetchColumn() === 250;

// A failure between the delete and the insert must leave the old row intact,
// not a path charged to nobody. The insert is broken by dropping the column
// it writes to, which is the closest thing to a mid-statement failure that
// can be provoked deterministically.
$db2 = $makeDb();
$ledger2 = new StorageLedger($db2);
$ledger2->record('/keep.txt', 'keep.txt', 500, 'text/plain', 7);
$db2->exec('ALTER TABLE file_metadata RENAME TO file_metadata_hidden');
$ledger2->record('/keep.txt', 'keep.txt', 900, 'text/plain', 7);   // must fail and log
$db2->exec('ALTER TABLE file_metadata_hidden RENAME TO file_metadata');
$rows = (int)$db2->query("SELECT COUNT(*) FROM file_metadata WHERE file_path='/keep.txt'")->fetchColumn();
$checks['a failed record() does not delete the row it could not replace'] = $rows === 1;
$checks['and the surviving row is the original'] =
    (int)$db2->query("SELECT size FROM file_metadata WHERE file_path='/keep.txt'")->fetchColumn() === 500;

// relocate() rewrites a folder and everything beneath it as one unit.
$db3 = $makeDb();
$ledger3 = new StorageLedger($db3);
foreach ([['/docs', 'docs'], ['/docs/one.txt', 'one.txt'], ['/docs/sub/two.txt', 'two.txt']] as [$path, $name]) {
    $ledger3->record($path, $name, 10, null, 1);
}
$ledger3->relocate('/docs', '/archive');
$moved = $db3->query("SELECT file_path FROM file_metadata ORDER BY file_path")->fetchAll(PDO::FETCH_COLUMN);
$checks['relocate rewrites the folder and its descendants'] =
    $moved === ['/archive', '/archive/one.txt', '/archive/sub/two.txt'];

$checks['the ledger exposes one transaction helper'] =
    str_contains((string)file_get_contents($root.'/src/Repositories/StorageLedger.php'), 'private function transactionally(callable $work): void');
$checks['record() uses the delete that reports failure'] =
    str_contains((string)file_get_contents($root.'/src/Repositories/StorageLedger.php'), '$this->deleteRows($path);   // an overwrite replaces the old row');

// --- copiedFiles() must not invent a row for a file that is not there -----

$base = sys_get_temp_dir().'/cloudhub-p25-'.bin2hex(random_bytes(5));
mkdir($base.'/tree/sub', 0775, true);
file_put_contents($base.'/tree/one.txt', 'one');
file_put_contents($base.'/tree/sub/two.txt', 'two');
$fs = new FileService(['root_dir' => $base, 'read_only' => false]);

$checks['copiedFiles lists what a copy produced'] = count($fs->copiedFiles($base.'/tree')) === 2;
$checks['copiedFiles reports nothing for a path that was never written'] = $fs->copiedFiles($base.'/missing') === [];
$checks['copiedFiles still reports a single file'] = $fs->copiedFiles($base.'/tree/one.txt') === [$base.'/tree/one.txt'];

// --- upload service -------------------------------------------------------

$upload = (string)file_get_contents($root.'/src/Services/UploadService.php');

// A staging directory PHP cannot remove must not fail the upload that runs
// the cleanup. Reproduced for real, since only an unprivileged process can.
$stage = $base.'/staging';
mkdir($stage.'/stuck', 0775, true);
file_put_contents($stage.'/stuck/data.part', 'x');
touch($stage.'/stuck', time() - 86400 * 30);

if (posix_getuid() !== 0) {
    chmod($stage.'/stuck', 0500);   // entries cannot be unlinked
    require_once $root.'/src/Services/UploadService.php';
    $svc = new \CloudHub\Services\UploadService(
        ['root_dir' => $base, 'read_only' => false, 'upload_abandon_hours' => 1,
         'max_upload_mb' => 10, 'upload_staging_dir' => $stage, 'upload_conflict' => 'rename'],
        $fs
    );
    $survived = true;
    try { $svc->cleanupAbandoned(); } catch (Throwable) { $survived = false; }
    $checks['an undeletable staging session does not break cleanup'] = $survived;
    chmod($stage.'/stuck', 0755);
} else {
    echo "[SKIP] undeletable-staging check needs an unprivileged user\n";
}
$checks['cleanup handles each session separately'] =
    str_contains($upload, 'catch (\Throwable $e) {') && str_contains($upload, 'could not clean abandoned session');

// The returned path is resolved, not cut from the raw config string.
$checks['complete() returns a resolved relative path'] =
    str_contains($upload, "'path'=>\$this->files->relative(\$dest)")
    && !str_contains($upload, "strlen(\$this->config['root_dir'])");

// Cancelling needs something to prove ownership against.
$checks['cancel refuses a session with no metadata'] =
    str_contains($upload, "if (!is_file(\$dir.'/meta.json')) throw new RuntimeException('Upload session not found or expired', 404);");
$checks['cancel still checks the owner'] =
    str_contains($upload, '$this->assertOwner($this->readMeta($id));');

// A missing size is a missing field, not an oversized file.
$checks['a missing size is reported as a missing size'] =
    str_contains($upload, "if (\$size < 0) throw new RuntimeException('A file size is required to start an upload', 400);");
$checks['the size limit is still enforced separately'] =
    str_contains($upload, "if (\$size > \$max) throw new RuntimeException('File exceeds the '.\$this->config['max_upload_mb'].' MB limit', 413);");

// --- routes ---------------------------------------------------------------

$index = (string)file_get_contents($root.'/public/index.php');
$checks['a partly-failed copy attributes what landed'] =
    str_contains($index, "if (\$verb === 'copy' && isset(\$target)) {");
$checks['the usage cache is written through a temporary file'] =
    str_contains($index, '$cacheTmp = $cache.\'.\'.bin2hex(random_bytes(4)).\'.tmp\';')
    && !str_contains($index, '@file_put_contents($cache, json_encode($report, JSON_UNESCAPED_SLASHES));');

rmrf25($base);
@unlink($errorLog);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
