<?php
declare(strict_types=1);

/**
 * The ways CloudHub used to destroy data without saying so.
 *
 * Each check below reproduces a real failure against the real filesystem --
 * these are not source assertions, because every one of these bugs looked
 * perfectly reasonable in the source and only showed itself when the operation
 * was actually run.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Repositories/StorageLedger.php';
use CloudHub\Services\FileService;
use CloudHub\Repositories\StorageLedger;

function rmrf22(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf22($p.'/'.$n); @rmdir($p); }
}

$checks = [];
$base = sys_get_temp_dir().'/cloudhub-p22-'.bin2hex(random_bytes(5));
mkdir($base, 0775, true);
$fs = new FileService(['root_dir' => $base, 'read_only' => false]);

// --- trash() must not lose the file when meta.json cannot be written -----
//
// The payload is renamed into .trash first. If the metadata write then fails,
// trashMeta() returns null and the entry disappears from trashList(),
// trashPurge(), trashPurgeExpired() and the storage report at once -- while
// the caller was told "Moved to trash". The realistic trigger is a full disk,
// which is exactly when someone is deleting things.
//
// A read-only .trash directory is how that is simulated. root bypasses the
// permission bits entirely, so the simulation is only meaningful as an
// ordinary user -- which is what CI runs as. Skipped rather than silently
// passing when running as root, because a check that cannot fail proves
// nothing.
file_put_contents($base.'/keepme.txt', str_repeat('k', 128));
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

if ($isRoot) {
    echo "[SKIP] a trash that cannot record the deletion fails loudly (running as root)".PHP_EOL;
    echo "[SKIP] and leaves the file exactly where it was (running as root)".PHP_EOL;
} else {
    mkdir($base.'/.trash', 0500, true);
    $threw = false;
    try { $fs->trash($base.'/keepme.txt', 'tester'); } catch (Throwable) { $threw = true; }

    $checks['a trash that cannot record the deletion fails loudly'] = $threw;
    $checks['and leaves the file exactly where it was'] =
        is_file($base.'/keepme.txt') && filesize($base.'/keepme.txt') === 128;

    chmod($base.'/.trash', 0775);
    rmrf22($base.'/.trash');
}

// Regardless of uid: the write is checked at all, and the payload is put back
// rather than being left orphaned inside .trash.
$fsSrc = (string)file_get_contents(dirname(__DIR__).'/src/Services/FileService.php');
$checks['the meta.json write result is checked'] =
    str_contains($fsSrc, "if(file_put_contents(\$entry.'/meta.json'");
$checks['a failed write puts the payload back'] =
    str_contains($fsSrc, "if(!rename(\$entry.'/payload/'.\$name,\$realPath))");

// The happy path still works, so the guard above did not break deletion.
$meta = $fs->trash($base.'/keepme.txt', 'tester');
$checks['an ordinary delete still reaches the trash'] =
    !file_exists($base.'/keepme.txt')
    && count($fs->trashList()) === 1
    && $fs->trashList()[0]['name'] === 'keepme.txt'
    && (int)$meta['bytes'] === 128;

// --- freeName() is what keeps a rename from destroying the occupant ------
//
// The route now routes a taken destination through freeName() exactly as
// move/copy does. ALLOW_OVERWRITE defaults to true, so before this a rename
// onto an existing name deleted it with no prompt, no trash and no audit
// entry -- and since the destination is any path, that included renaming
// across folders.
file_put_contents($base.'/invoice.pdf', 'ORIGINAL');
file_put_contents($base.'/scan.pdf', 'SCAN');
$target = $fs->freeName($base.'/invoice.pdf');
rename($base.'/scan.pdf', $target);

$checks['renaming onto a taken name does not destroy the occupant'] =
    is_file($base.'/invoice.pdf') && file_get_contents($base.'/invoice.pdf') === 'ORIGINAL';
$checks['the renamed file lands beside it'] =
    is_file($base.'/invoice (2).pdf') && file_get_contents($base.'/invoice (2).pdf') === 'SCAN';

rmrf22($base);

// --- ledger prefix matching is character-based, like MySQL's SUBSTR ------
//
// The column is utf8mb4, so MySQL's SUBSTR() counts characters while PHP's
// strlen() counts bytes. Passing a byte length overshot for any non-ASCII
// name, so the comparison never matched and every row beneath an accented,
// CJK or emoji folder survived its own deletion -- still counting against the
// owner's quota, forever.
$prefix = "/Fotos \u{00d1}/";
$checks['a non-ASCII prefix is longer in bytes than in characters'] =
    strlen($prefix) === 10 && mb_strlen($prefix) === 9;
$checks['the byte length would have matched nothing'] =
    mb_substr($prefix.'a.jpg', 0, strlen($prefix)) !== $prefix;
$checks['the character length matches the child rows'] =
    mb_substr($prefix.'a.jpg', 0, mb_strlen($prefix)) === $prefix;

$ledgerSrc = (string)file_get_contents(dirname(__DIR__).'/src/Repositories/StorageLedger.php');
$checks['forget() passes a character length to SQL'] =
    str_contains($ledgerSrc, '$stmt->execute([$path, mb_strlen($prefix), $prefix]);');
$checks['relocate() passes a character length to SQL'] =
    str_contains($ledgerSrc, '$stmt->execute([mb_strlen($prefix), $prefix]);');
// PHP's substr() is byte-based, so the rewrite below it must keep strlen().
$checks['the PHP-side substring still uses a byte length'] =
    str_contains($ledgerSrc, "substr((string)\$row['file_path'], strlen(\$prefix))");

// --- sweep() advances instead of re-reading the same rows ----------------
//
// `ORDER BY id LIMIT 500` with no cursor examined the same lowest 500 ids on
// every call, so on a larger table rows above the watermark were never checked
// by any code path: recorded usage only grew, and a configured quota
// eventually locked the account out with no admin remedy.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE file_metadata (id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INT NOT NULL DEFAULT 1,
    file_path TEXT NOT NULL, original_name TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0,
    mime_type TEXT NULL, uploaded_by INTEGER NULL, created_at TEXT)');
$db->exec("CREATE TABLE storage_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, is_default INT DEFAULT 1)");
$db->exec('INSERT INTO storage_servers (is_default) VALUES (1)');

$swept = $base.'-sweep';
mkdir($swept, 0775, true);
$ins = $db->prepare('INSERT INTO file_metadata (server_id,file_path,original_name,size) VALUES (1,?,?,10)');
// 12 rows, none of which exist on disk, swept 5 at a time.
for ($i = 1; $i <= 12; $i++) $ins->execute(['/gone-'.$i.'.txt', 'gone-'.$i.'.txt']);

$sweepFs = new FileService(['root_dir' => $swept, 'read_only' => false]);
@unlink(dirname(__DIR__).'/storage/.cache/sweep-cursor');
$ledger = new StorageLedger($db);
$rounds = [];
for ($i = 0; $i < 3; $i++) $rounds[] = $ledger->sweep($sweepFs, 5);
$remaining = (int)$db->query('SELECT COUNT(*) FROM file_metadata')->fetchColumn();
@unlink(dirname(__DIR__).'/storage/.cache/sweep-cursor');
rmrf22($swept);

$checks['each sweep examines a further window'] = $rounds === [5, 5, 2];
$checks['every stale row is eventually reached'] = $remaining === 0;

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
