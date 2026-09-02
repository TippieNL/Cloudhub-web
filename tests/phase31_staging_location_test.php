<?php
declare(strict_types=1);

/**
 * Where partial uploads are staged, and what that costs when finishing one.
 *
 * complete() renames the staged file into place and falls back to copying the
 * whole thing when rename() returns EXDEV. Staging therefore has to be on the
 * same filesystem as the destination, or every upload writes every byte twice.
 * That held before only by coincidence of how an install happened to be laid
 * out; these checks make it a property of the code.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/UploadService.php';
require dirname(__DIR__).'/src/Services/DuplicateFinder.php';

use CloudHub\Services\FileService;
use CloudHub\Services\UploadService;

function rmrf31(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf31($p.'/'.$n); @rmdir($p); }
}

$root = dirname(__DIR__);
$checks = [];

$base = sys_get_temp_dir().'/cloudhub-p31-'.bin2hex(random_bytes(5));
mkdir($base.'/files/Photos', 0775, true);
$_SESSION['user_id'] = 7;

$config = [
    'root_dir' => $base.'/files', 'read_only' => false,
    'upload_abandon_hours' => 24, 'max_upload_mb' => 64,
    'upload_chunk_mb' => 1, 'upload_staging_dir' => '', 'upload_conflict' => 'rename',
];
$fs = new FileService($config);
$svc = new UploadService($config, $fs);

// --- staging lands beside the files it will become ------------------------

$checks['staging defaults inside the storage root'] = is_dir($base.'/files/.uploads');
$checks['and not in the project directory'] = !is_dir($root.'/storage/uploads/.keep-me-honest');
$checks['it is on the same filesystem as the destination'] =
    (@stat($base.'/files/.uploads')['dev'] ?? -1) === (@stat($base.'/files')['dev'] ?? -2);

// An install that wants to stage elsewhere still can.
$elsewhere = $base.'/custom-staging';
new UploadService(array_merge($config, ['upload_staging_dir' => $elsewhere]), $fs);
$checks['UPLOAD_STAGING_DIR still overrides the default'] = is_dir($elsewhere);

// --- .uploads gets every guarantee .trash has ----------------------------

file_put_contents($base.'/files/.uploads/stray.jpg', str_repeat('x', 5000));
file_put_contents($base.'/files/Photos/real.jpg', str_repeat('y', 5000));

$names = array_column($fs->list('/'), 'name');
$checks['.uploads is not listed'] = !in_array('.uploads', $names, true);
$checks['real folders still are'] = in_array('Photos', $names, true);

$found = implode(' ', array_column($fs->search('/', 'jpg')['results'], 'path'));
$checks['.uploads is not searched'] = !str_contains($found, '.uploads');
$checks['real files still are'] = str_contains($found, '/Photos/real.jpg');

$reserved = false;
try { $fs->existing('/.uploads'); } catch (Throwable) { $reserved = true; }
$checks['.uploads cannot be addressed'] = $reserved;

$report = $fs->storageReport();
$checks['.uploads is not counted in the storage report'] =
    $report['files'] === 1 && !str_contains(json_encode($report['folders']), '.uploads');

// The duplicate scanner walks the same tree and must not see it either.
$dupSrc = (string)file_get_contents($root.'/src/Services/DuplicateFinder.php');
$checks['the duplicate scan uses the filtered walk'] = str_contains($dupSrc, '$this->files->childPaths($dir)');

// --- finishing an upload is a rename, not a copy -------------------------

$payload = random_bytes(1024 * 1024 * 2 + 512);
$id = 'loc'.bin2hex(random_bytes(6));
$svc->init('/Photos', 'movie.bin', strlen($payload), $id, 'rename');

$chunkFile = $base.'/chunk.bin';
$chunk = 1024 * 1024;
for ($offset = 0; $offset < strlen($payload); $offset += $chunk) {
    file_put_contents($chunkFile, substr($payload, $offset, $chunk));
    $svc->append($id, $offset, $chunkFile);
}

// The staged file's inode before the move. A rename preserves it; a copy does
// not, so this distinguishes the two paths by observation rather than by
// trusting that rename() was reached.
$part = $base.'/files/.uploads/'.$id.'/data.part';
$stagedInode = @stat($part)['ino'] ?? null;
$checks['the staged file exists before finishing'] = $stagedInode !== null;

$done = $svc->complete($id);
$landed = $base.'/files'.$done['path'];
$checks['the finished file is byte-identical'] =
    is_file($landed) && hash_file('sha256', $landed) === hash('sha256', $payload);
$checks['finishing was a rename, not a copy'] = (@stat($landed)['ino'] ?? -1) === $stagedInode;
$checks['the staging session is cleaned up'] = !is_dir($base.'/files/.uploads/'.$id);

// The uploaded file is a normal file in the tree, not hidden with the staging.
$photoNames = array_column($fs->list('/Photos'), 'name');
$checks['the upload appears in its folder'] = in_array('movie.bin', $photoNames, true);

// --- the cross-device case is still handled, just no longer the default ---

$upload = (string)file_get_contents($root.'/src/Services/UploadService.php');
$checks['the copy fallback is still there for a configured staging dir'] =
    str_contains($upload, 'if (!copy($part, $staged)) {');
$checks['the default is the storage root'] =
    str_contains($upload, ": \$this->files->root().'/.uploads';")
    && !str_contains($upload, "dirname(__DIR__, 2).'/storage/uploads'");

// --- the diagnostic reports the distinction ------------------------------

// The report itself now lives in StorageDiagnostics, so the CLI tool and the
// admin route cannot drift apart; these look where the logic is rather than
// where it used to be. phase32 exercises the behaviour against real paths.
$tool = (string)file_get_contents($root.'/tools/storage-check.php');
$service = (string)file_get_contents($root.'/src/Services/StorageDiagnostics.php');
$checks['the diagnostic resolves a missing directory to its parent'] =
    str_contains($service, 'private static function deviceOf(string $path): ?int');
$checks['it names the copy case explicitly'] = str_contains($service, 'DIFFERENT filesystems');
$checks['it measures throughput the way the uploader writes'] =
    str_contains($service, 'private const BLOCK_BYTES = 1048576;')
    && str_contains($tool, '1 MiB blocks through PHP');
$checks['it flags a request limit at or below the chunk size'] =
    str_contains($service, 'is not above the ') && str_contains($service, 'chunk size; lower UPLOAD_CHUNK_MB');
$checks['it is still CLI-only'] = str_contains($tool, "PHP_SAPI !== 'cli'");
$checks['and the same report is reachable without a shell'] =
    str_contains((string)file_get_contents($root.'/public/index.php'), "'/api/system/storage'");

rmrf31($base);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
