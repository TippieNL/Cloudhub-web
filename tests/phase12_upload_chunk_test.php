<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/UploadService.php';

use CloudHub\Services\FileService;
use CloudHub\Services\UploadService;

/**
 * UploadService::append() looped on !feof($in) while sizing each read as
 * min(1 MiB, $chunkLimit - $written). A body of exactly $chunkLimit bytes
 * leaves the stream short of EOF, so the next iteration called fread($in, 0) --
 * a ValueError on PHP 8, surfaced as HTTP 500. The browser slices exactly
 * chunkBytes per chunk, so every chunk but the last hit it.
 */
$base = sys_get_temp_dir().'/cloudhub-chunk-'.bin2hex(random_bytes(5));
mkdir($base.'/storage/files', 0775, true);
mkdir($base.'/storage/uploads', 0775, true);

$chunkMb = 1;
$chunkLimit = $chunkMb * 1024 * 1024;
$config = [
    'root_dir' => $base.'/storage/files',
    'read_only' => false,
    'allow_overwrite' => true,
    'upload_staging_dir' => $base.'/storage/uploads',
    'upload_abandon_hours' => 24,
    'max_upload_mb' => 16,
    'max_upload_files' => 20,
    'upload_chunk_mb' => $chunkMb,
    'upload_conflict' => 'rename',
];

$_SESSION = ['user_id' => 3];
$files = new FileService($config);
$uploads = new UploadService($config, $files);

$pass = 0; $fail = 0;
function check(string $name, callable $fn): void {
    global $pass, $fail;
    try { $ok = (bool)$fn(); $note = ''; }
    catch (Throwable $e) { $ok = false; $note = ' ('.get_class($e).': '.$e->getMessage().')'; }
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.$note.PHP_EOL;
    $ok ? $pass++ : $fail++;
}

/** Stand in for php://input with a body of exactly $bytes bytes. */
$bodyStream = function (int $bytes) use ($base): string {
    $path = $base.'/body-'.bin2hex(random_bytes(4)).'.bin';
    file_put_contents($path, str_repeat('a', $bytes));
    return $path;
};

check('a full-size chunk is accepted', function () use ($uploads, $bodyStream, $chunkLimit) {
    $uploads->init('/', 'full.bin', $chunkLimit * 2, 'fullchunk1', 'rename');
    $state = $uploads->append('fullchunk1', 0, $bodyStream($chunkLimit));
    return $state['received'] === $chunkLimit && $state['complete'] === false;
});

check('the following chunk resumes at the confirmed offset', function () use ($uploads, $bodyStream, $chunkLimit) {
    $state = $uploads->append('fullchunk1', $chunkLimit, $bodyStream($chunkLimit));
    return $state['received'] === $chunkLimit * 2 && $state['complete'] === true;
});

check('the upload completes and lands in storage', function () use ($uploads, $base, $chunkLimit) {
    $done = $uploads->complete('fullchunk1');
    $target = $base.'/storage/files/full.bin';
    return ($done['success'] ?? false) && is_file($target) && filesize($target) === $chunkLimit * 2;
});

check('a short final chunk is accepted', function () use ($uploads, $bodyStream) {
    $uploads->init('/', 'short.bin', 10, 'shortchunk', 'rename');
    $state = $uploads->append('shortchunk', 0, $bodyStream(10));
    $ok = $state['received'] === 10 && $state['complete'] === true;
    $uploads->cancel('shortchunk');
    return $ok;
});

check('an oversized chunk is still rejected with 413', function () use ($uploads, $bodyStream, $chunkLimit) {
    $uploads->init('/', 'over.bin', $chunkLimit * 4, 'overchunk1', 'rename');
    try {
        $uploads->append('overchunk1', 0, $bodyStream($chunkLimit + 1024));
        $ok = false;
    } catch (RuntimeException $e) {
        $ok = $e->getCode() === 413;
    }
    $uploads->cancel('overchunk1');
    return $ok;
});

check('a chunk past the declared size is rejected', function () use ($uploads, $bodyStream) {
    $uploads->init('/', 'tiny.bin', 4, 'tinychunk1', 'rename');
    try {
        $uploads->append('tinychunk1', 0, $bodyStream(64));
        $ok = false;
    } catch (RuntimeException $e) {
        $ok = $e->getCode() === 413;
    }
    $uploads->cancel('tinychunk1');
    return $ok;
});

function rmrf(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf($p.'/'.$n); @rmdir($p); }
}
rmrf($base);
exit($fail ? 1 : 0);
