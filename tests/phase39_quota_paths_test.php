<?php
declare(strict_types=1);

/**
 * Every way bytes arrive answers to the quota, and a restore keeps them charged.
 *
 * The resumable upload checked the quota and recorded who uploaded what; the
 * other ways of putting bytes on disk did not. A copy was charged afterwards
 * but never checked; WebDAV's PUT and the legacy multipart route were neither
 * checked nor charged; and trash-then-restore brought a file back attributed
 * to nobody. Each was a way round a configured quota.
 *
 * The ledger runs against a real in-memory SQLite database and the trash
 * against a real directory tree; the route wiring is pinned against the source
 * (exercised over HTTP by tests/http/run.php, which needs MySQL).
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Repositories/StorageLedger.php';

use CloudHub\Services\FileService;
use CloudHub\Repositories\StorageLedger;

$root = dirname(__DIR__);
$checks = [];

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE file_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL, file_path TEXT NOT NULL,
    original_name TEXT NOT NULL, size INTEGER NOT NULL, mime_type TEXT, uploaded_by INTEGER)');
$db->exec('CREATE TABLE storage_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, is_default INTEGER)');
$db->exec('INSERT INTO storage_servers (is_default) VALUES (1)');
$ledger = new StorageLedger($db);

$base = sys_get_temp_dir().'/cloudhub-p39-'.bin2hex(random_bytes(5));
mkdir($base.'/Fotos é/sub', 0775, true);
file_put_contents($base.'/Fotos é/one.jpg', str_repeat('1', 100));
file_put_contents($base.'/Fotos é/sub/two.jpg', str_repeat('2', 50));
file_put_contents($base.'/Fotos émigré.jpg', str_repeat('3', 7));   // shares the prefix, not the folder
$fs = new FileService(['root_dir' => $base, 'read_only' => false]);

$ledger->record('/Fotos é/one.jpg', 'one.jpg', 100, 'image/jpeg', 7);
$ledger->record('/Fotos é/sub/two.jpg', 'two.jpg', 50, null, 7);
$ledger->record('/Fotos émigré.jpg', 'Fotos émigré.jpg', 7, null, 8);

// --- the rows a trash entry keeps ------------------------------------------
$rows = $ledger->rowsUnder('/Fotos é');
$paths = array_column($rows, 'path'); sort($paths);
$checks['rowsUnder() takes the folder and everything beneath it'] = $paths === ['/Fotos é/one.jpg', '/Fotos é/sub/two.jpg'];
$checks['and nothing that merely shares its prefix'] = !in_array('/Fotos émigré.jpg', $paths, true);
$checks['with the uploader and size of each'] = array_sum(array_column($rows, 'size')) === 150
    && array_unique(array_column($rows, 'userId')) === [7];

// --- trash keeps them, beside meta.json, not in it --------------------------
$meta = $fs->trash($fs->existing('/Fotos é'), 'tester', $rows);
$ledger->forget($meta['originalPath']);
$checks['trashing frees the quota, as before'] = $ledger->usage(7) === 0;
$checks['the entry keeps the attribution'] = is_file($base.'/.trash/'.$meta['id'].'/attribution.json');
$listed = $fs->trashList();
$checks['the trash listing does not hand it to the client'] = $listed !== [] && !array_key_exists('attribution', $listed[0])
    && !str_contains((string)json_encode($listed), 'userId');

// --- restore gives the bytes back ---------------------------------------------
$restored = $fs->restore($meta['id']);
$ledger->reattribute($restored['attribution'], $restored['originalPath'], $restored['path']);
$checks['a restore charges the uploader again'] = $ledger->usage(7) === 150;
$checks['under the paths the files came back to'] =
    (int)$db->query("SELECT COUNT(*) FROM file_metadata WHERE file_path IN ('/Fotos é/one.jpg','/Fotos é/sub/two.jpg')")->fetchColumn() === 2;

// Restored beside a newcomer that took the name: the rows follow the new path.
$rows = $ledger->rowsUnder('/Fotos é');
$meta = $fs->trash($fs->existing('/Fotos é'), 'tester', $rows);
$ledger->forget($meta['originalPath']);
mkdir($base.'/Fotos é');
$restored = $fs->restore($meta['id']);
$ledger->reattribute($restored['attribution'], $restored['originalPath'], $restored['path']);
$checks['a restore under another name moves the rows with it'] = $restored['path'] === '/Fotos é (2)'
    && (int)$db->query("SELECT COUNT(*) FROM file_metadata WHERE file_path = '/Fotos é (2)/sub/two.jpg'")->fetchColumn() === 1
    && $ledger->usage(7) === 150;
$checks['an entry trashed without attribution restores as before'] = (function () use ($fs, $base): bool {
    file_put_contents($base.'/plain.txt', 'x');
    $m = $fs->trash($fs->existing('/plain.txt'), 'tester');
    $r = $fs->restore($m['id']);
    return $r['attribution'] === [] && $r['path'] === '/plain.txt';
})();
$checks['rows from elsewhere are never re-recorded'] = (function () use ($ledger): bool {
    $before = $ledger->usage(9);
    $ledger->reattribute([['path' => '/elsewhere.bin', 'name' => 'x', 'size' => 999, 'userId' => 9]], '/Fotos é', '/Fotos é');
    return $ledger->usage(9) === $before;
})();

// --- the routes: every way in is checked, charged, and kept -----------------
$index = (string)file_get_contents($root.'/public/index.php');
$dav = (string)file_get_contents($root.'/src/Services/WebDav.php');
$checks['a delete keeps the rows with the trash entry'] =
    str_contains($index, "\$fs->trash(\$p, Auth::user()['username'] ?? null, ledger()->rowsUnder(\$fs->relative(\$p)));");
$checks['a restore hands them back'] =
    str_contains($index, "ledger()->reattribute(\$restored['attribution'], \$restored['originalPath'], \$restored['path']);");
$checks['a copy has to fit before it is made'] =
    str_contains($index, "if (\$verb === 'copy') assert_upload_fits(\$fs, \$config, (int)(\$fs->measure(\$source)['bytes'] ?? 0));");
$checks['the multipart route checks, charges, and keeps both by default'] =
    str_contains($index, "\$conflict = in_array(\$_POST['conflict'] ?? '', ['rename', 'overwrite', 'reject'], true) ? (string)\$_POST['conflict'] : 'rename';")
    && str_contains($index, "ledger()->record(\$fs->relative(\$dest), basename(\$dest), \$size, null, Auth::user()['id'] ?? null);");
$checks['WebDAV is handed the upload rules'] =
    str_contains($index, "'fits' => function(int \$bytes) use (\$fs, \$config): void { assert_upload_fits(\$fs, \$config, \$bytes); },")
    && str_contains($index, "'attribution' => fn(string \$rel): array => ledger()->rowsUnder(\$rel),");
$checks['and a PUT applies them'] =
    str_contains($dav, "if(\$fits&&ctype_digit(\$declared)){try{\$fits((int)\$declared);}")
    && str_contains($dav, "if(\$stored)\$stored(\$fs->relative(\$full),\$size);");

// The sweep cursor is how usage stays true. flock() fails outright on the
// Android shared storage this targets, so a LOCK_EX write never advanced it,
// the sweep examined one window forever, and usage only ever grew.
$ledgerSource = (string)file_get_contents($root.'/src/Repositories/StorageLedger.php');
preg_match('/private function writeSweepCursor\(int \$cursor\): void\s*\{.*?\n    \}/s', $ledgerSource, $cursorFn);
$checks['the sweep cursor is written without flock'] = isset($cursorFn[0])
    && str_contains($cursorFn[0], 'file_put_contents($file, ') && !preg_match('/file_put_contents\(\$file,[^;]*LOCK_EX/', $cursorFn[0]);

$rmrf = static function (string $p) use (&$rmrf): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') $rmrf($p.'/'.$n); @rmdir($p); }
};
$rmrf($base);

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
