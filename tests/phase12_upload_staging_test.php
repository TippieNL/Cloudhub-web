<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/UploadService.php';

use CloudHub\Services\FileService;
use CloudHub\Services\UploadService;

/**
 * Resumable upload staging lives in storage/uploads, outside ROOT_DIR
 * (storage/files). Deleting a staging session through FileService::deleteTree()
 * therefore tripped its ROOT_DIR containment check and raised
 * "Path escapes the configured storage root" every time, so cancel() always
 * failed and init() failed for everyone once any session aged past
 * UPLOAD_ABANDON_HOURS -- init() runs cleanupAbandoned() first.
 */
$base = sys_get_temp_dir().'/cloudhub-staging-'.bin2hex(random_bytes(5));
mkdir($base.'/storage/files', 0775, true);
mkdir($base.'/storage/uploads', 0775, true);
$outside = $base.'/outside';
mkdir($outside, 0775, true);
file_put_contents($outside.'/keep.txt', 'must survive');

$config = [
    'root_dir' => $base.'/storage/files',
    'read_only' => false,
    'allow_overwrite' => true,
    'upload_staging_dir' => $base.'/storage/uploads',
    'upload_abandon_hours' => 1,
    'max_upload_mb' => 16,
    'max_upload_files' => 20,
    'upload_chunk_mb' => 1,
    'upload_conflict' => 'rename',
];

$_SESSION = ['user_id' => 7];
$files = new FileService($config);
$uploads = new UploadService($config, $files);
$staging = $base.'/storage/uploads';

$pass = 0; $fail = 0;
function check(string $name, callable $fn): void {
    global $pass, $fail;
    try { $ok = (bool)$fn(); $note = ''; }
    catch (Throwable $e) { $ok = false; $note = ' ('.get_class($e).': '.$e->getMessage().')'; }
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.$note.PHP_EOL;
    $ok ? $pass++ : $fail++;
}

/** Build a staging session on disk, optionally aged past the TTL. */
$makeSession = function (string $id, bool $stale) use ($staging): string {
    $dir = $staging.'/'.$id;
    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/meta.json', json_encode([
        'id' => $id, 'name' => 'x.bin', 'size' => 4, 'targetPath' => '/',
        'conflict' => 'rename', 'ownerUserId' => 7, 'createdAt' => time(), 'updatedAt' => time(),
    ]));
    file_put_contents($dir.'/data.part', 'abcd');
    if ($stale) touch($dir, time() - 7200);
    return $dir;
};

check('cancel() removes a staging session', function () use ($uploads, $makeSession, $staging) {
    $dir = $makeSession('cancelme0', false);
    $uploads->cancel('cancelme0');
    clearstatcache();
    return !is_dir($dir);
});

check('cleanupAbandoned() removes stale sessions', function () use ($uploads, $makeSession) {
    $dir = $makeSession('stale00001', true);
    $removed = $uploads->cleanupAbandoned();
    clearstatcache();
    return $removed === 1 && !is_dir($dir);
});

check('cleanupAbandoned() keeps fresh sessions', function () use ($uploads, $makeSession) {
    $dir = $makeSession('fresh00001', false);
    $uploads->cleanupAbandoned();
    clearstatcache();
    $kept = is_dir($dir);
    $uploads->cancel('fresh00001');
    return $kept;
});

check('init() still works with a stale session present', function () use ($uploads, $makeSession) {
    $makeSession('stale00002', true);
    $state = $uploads->init('/', 'hello.bin', 4, 'initcheck1', 'rename');
    $ok = ($state['id'] ?? '') === 'initcheck1' && (int)($state['size'] ?? -1) === 4;
    $uploads->cancel('initcheck1');
    return $ok;
});

check('staging delete refuses paths outside the staging root', function () use ($uploads, $outside) {
    $method = new ReflectionMethod($uploads, 'deleteStagingTree');
    $method->setAccessible(true);
    try {
        $method->invoke($uploads, $outside);
        return false;
    } catch (RuntimeException $e) {
        return $e->getCode() === 403 && is_file($outside.'/keep.txt');
    }
});

check('staging delete refuses the staging root itself', function () use ($uploads, $staging) {
    $method = new ReflectionMethod($uploads, 'deleteStagingTree');
    $method->setAccessible(true);
    try {
        $method->invoke($uploads, $staging);
        return false;
    } catch (RuntimeException $e) {
        return $e->getCode() === 403 && is_dir($staging);
    }
});

check('FileService still guards its own root', function () use ($files, $outside) {
    try { $files->deleteTree($outside); return false; }
    catch (RuntimeException $e) { return $e->getCode() === 403 && is_file($outside.'/keep.txt'); }
});

function rmrf(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf($p.'/'.$n); @rmdir($p); }
}
rmrf($base);
exit($fail ? 1 : 0);
