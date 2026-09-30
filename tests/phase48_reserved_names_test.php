<?php
declare(strict_types=1);

/**
 * CloudHub's own names cannot be created at the root by any route.
 *
 * sanitize() refused .trash, .uploads and .thumbnails on every path a client
 * asks for, but uploads, moves and copies build their target from a folder and
 * a name, and nothing checked the name. Reproduced before the fix:
 *
 *   - a resumable upload named ".trash" into the root, before anything had
 *     been deleted, became a file where the trash directory belongs, and every
 *     delete after it failed for every account with "Unable to open the trash";
 *   - a folder named ".thumbnails" moved into the root vanished from the
 *     listing and the storage report, and nothing could address it again.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/UploadService.php';
use CloudHub\Services\FileService;
use CloudHub\Services\UploadService;

function rmrf48(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf48($p.'/'.$n); @rmdir($p); }
}

$checks = [];
$root = sys_get_temp_dir().'/cloudhub-p48-'.bin2hex(random_bytes(5));
mkdir($root.'/x/.thumbnails', 0775, true);
file_put_contents($root.'/x/.thumbnails/hidden.bin', str_repeat('z', 1000));
$config = ['root_dir' => $root, 'read_only' => false, 'max_upload_mb' => 10, 'upload_chunk_mb' => 1,
    'upload_abandon_hours' => 24, 'upload_staging_dir' => '', 'allow_overwrite' => true];
$fs = new FileService($config);
$_SESSION = ['user_id' => 7];
$uploads = new UploadService($config, $fs);

$refused = static function(callable $fn): ?int {
    try { $fn(); return null; } catch (RuntimeException $e) { return $e->getCode(); }
};

// --- the guard itself --------------------------------------------------------
foreach (['.trash', '.uploads', '.thumbnails', '.Trash', '.trash.', '.thumbnails '] as $name) {
    $checks['childPath() refuses '.json_encode($name).' at the root'] =
        $refused(fn() => $fs->childPath($root, $name)) === 403;
}
$checks['but allows it below the root, where it is an ordinary name'] = $fs->childPath($root.'/x', '.trash') === $root.'/x/.trash';
$checks['and allows ordinary names at the root'] = $fs->childPath($root.'/', 'notes.txt') === $root.'/notes.txt';

// --- the resumable upload -----------------------------------------------------
$checks['an upload named .trash into the root is refused before any byte is staged'] =
    $refused(fn() => $uploads->init('/', '.trash', 3, 'p48-trash-upload-1', 'rename')) === 403;
$checks['so no trash-shaped file appears'] = !file_exists($root.'/.trash');

// complete() checks again: a session staged before this fix must not land.
$session = $uploads->init('/', 'plain.txt', 3, 'p48-legacy-upload', 'rename');
$metaFile = $root.'/.uploads/'.$session['id'].'/meta.json';
$meta = json_decode((string)file_get_contents($metaFile), true);
$meta['name'] = '.trash';
file_put_contents($metaFile, json_encode($meta));
$in = tempnam(sys_get_temp_dir(), 'p48');
file_put_contents($in, 'abc');
$uploads->append($session['id'], 0, $in);
@unlink($in);
$checks['complete() refuses a staged upload bound for a reserved name'] =
    $refused(fn() => $uploads->complete($session['id'])) === 403 && !file_exists($root.'/.trash');

// Deletion still works afterwards, which it did not once .trash was a file.
file_put_contents($root.'/victim.txt', 'data');
$trashed = $refused(fn() => $fs->trash($fs->existing('/victim.txt'), 'tester'));
$checks['deleting still reaches the trash'] = $trashed === null && is_dir($root.'/.trash');

// --- move and copy build their target the same way ------------------------------
// What /api/files/move and /api/files/copy do with each source.
$source = $fs->existing('/x/.thumbnails');
$checks['moving a folder called .thumbnails into the root is refused'] =
    $refused(fn() => $fs->childPath($fs->existing('/'), basename($source))) === 403;
$checks['and the folder stays where it was, visible'] = is_dir($root.'/x/.thumbnails')
    && in_array('.thumbnails', array_column($fs->list('/x'), 'name'), true);

// --- the routes use the guard ----------------------------------------------------
$index = (string)file_get_contents(dirname(__DIR__).'/public/index.php');
$uploadSrc = (string)file_get_contents(dirname(__DIR__).'/src/Services/UploadService.php');
$checks['move and copy targets come through childPath()'] =
    str_contains($index, '$target = $fs->childPath($destination, basename($source));')
    && !str_contains($index, "\$target = rtrim(\$destination, '/').'/'.basename(\$source);");
$checks['the multipart upload target comes through childPath()'] =
    str_contains($index, '$dest = $fs->childPath($target, $safe);') && !str_contains($index, "\$dest = \$target.'/'.\$safe;");
$checks['init() and complete() both check'] =
    str_contains($uploadSrc, '$this->files->childPath($targetDir, $safeName);')
    && str_contains($uploadSrc, "\$dest = \$this->files->childPath(\$targetDir, (string)\$meta['name']);");

rmrf48($root);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
