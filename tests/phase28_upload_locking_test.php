<?php
declare(strict_types=1);

/**
 * Chunked append on a filesystem without advisory locking.
 *
 * Requiring flock() made every chunk return 500 on Android emulated storage,
 * which is the platform this application targets and which this service's own
 * ensureWritableDirectory() docblock already describes as having no reliable
 * advisory flock() semantics. These checks run the real append() against a real
 * staging tree, including with the lock genuinely unavailable -- held by a
 * second process -- rather than asserting on the shape of the source.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/UploadService.php';

use CloudHub\Services\FileService;
use CloudHub\Services\UploadService;

function rmrf28(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf28($p.'/'.$n); @rmdir($p); }
}

/**
 * A filesystem that behaves normally except that it has no advisory locking.
 *
 * Every operation proxies to a real directory; stream_lock() is deliberately
 * not implemented, so flock() on this stream returns false -- which is what
 * Android shared storage does and what ext4 here will never do on its own.
 * This is the difference between inferring the production failure and
 * reproducing it.
 */
class NoLockFs {
    public $context;
    public static string $realBase = '';
    private $fh = null;
    private $dh = null;

    private static function real(string $path): string {
        return self::$realBase.'/'.ltrim(substr($path, strlen('nolock://')), '/');
    }
    public function stream_open($path, $mode, $options, &$opened) {
        $this->fh = @fopen(self::real($path), $mode);
        return $this->fh !== false;
    }
    public function stream_read($n) { return fread($this->fh, $n); }
    public function stream_write($d) { return fwrite($this->fh, $d); }
    public function stream_eof() { return feof($this->fh); }
    public function stream_seek($o, $w = SEEK_SET) { return fseek($this->fh, $o, $w) === 0; }
    public function stream_tell() { return ftell($this->fh); }
    public function stream_stat() { return fstat($this->fh); }
    public function stream_flush() { return fflush($this->fh); }
    public function stream_close() { return fclose($this->fh); }
    public function stream_truncate($size) { return ftruncate($this->fh, $size); }
    // stream_lock() is intentionally absent.
    public function url_stat($path, $flags) { return @stat(self::real($path)) ?: false; }
    public function unlink($path) { return @unlink(self::real($path)); }
    public function rename($from, $to) { return @rename(self::real($from), self::real($to)); }
    public function mkdir($path, $mode, $options) {
        return @mkdir(self::real($path), $mode, (bool)($options & STREAM_MKDIR_RECURSIVE));
    }
    public function rmdir($path, $options) { return @rmdir(self::real($path)); }
    public function dir_opendir($path, $options) { $this->dh = @opendir(self::real($path)); return $this->dh !== false; }
    public function dir_readdir() { return readdir($this->dh); }
    public function dir_rewinddir() { rewinddir($this->dh); return true; }
    public function dir_closedir() { closedir($this->dh); return true; }
    public function stream_metadata($path, $option, $value) {
        $real = self::real($path);
        return match ($option) {
            STREAM_META_TOUCH => @touch($real),
            STREAM_META_ACCESS => @chmod($real, $value),
            default => false,
        };
    }
}

$root = dirname(__DIR__);
$checks = [];

$errorLog = sys_get_temp_dir().'/cloudhub-p28-'.bin2hex(random_bytes(4)).'.log';
ini_set('error_log', $errorLog);

$base = sys_get_temp_dir().'/cloudhub-p28-'.bin2hex(random_bytes(5));
mkdir($base.'/files', 0775, true);
$stage = $base.'/staging';
mkdir($stage, 0775, true);

$_SESSION['user_id'] = 7;

$chunkMb = 1;                       // keep the fixtures small; the logic is size-agnostic
$chunkBytes = $chunkMb * 1024 * 1024;

$fs = new FileService(['root_dir' => $base.'/files', 'read_only' => false]);
$config = [
    'root_dir' => $base.'/files', 'read_only' => false,
    'upload_abandon_hours' => 24, 'max_upload_mb' => 64,
    'upload_chunk_mb' => $chunkMb, 'upload_staging_dir' => $stage,
    'upload_conflict' => 'rename',
];
$svc = new UploadService($config, $fs);

/** Feed one chunk through append() from a real file on disk. */
$sendChunk = static function(UploadService $svc, string $id, int $offset, string $bytes) use ($base): array {
    $chunkFile = $base.'/chunk-'.bin2hex(random_bytes(4)).'.bin';
    file_put_contents($chunkFile, $bytes);
    try { return $svc->append($id, $offset, $chunkFile); }
    finally { @unlink($chunkFile); }
};

// --- a multi-chunk upload assembles the original bytes exactly ------------

$payload = random_bytes($chunkBytes * 2 + 4096);   // two full chunks and a short one
$id = 'multichunk'.bin2hex(random_bytes(6));
$svc->init('/', 'movie.bin', strlen($payload), $id, 'rename');

$offset = 0;
$statuses = [];
while ($offset < strlen($payload)) {
    $slice = substr($payload, $offset, $chunkBytes);
    $statuses[] = $sendChunk($svc, $id, $offset, $slice);
    $offset += strlen($slice);
}
$checks['every chunk was accepted'] = count($statuses) === 3;
$checks['the server agrees on the final size'] = ($statuses[2]['received'] ?? -1) === strlen($payload);

$done = $svc->complete($id);
$landed = $base.'/files'.$done['path'];
$checks['the finished upload is byte-identical to the source'] =
    is_file($landed) && hash_file('sha256', $landed) === hash('sha256', $payload);

// --- the lock being unavailable must not fail the upload -----------------

$payload2 = random_bytes($chunkBytes);
$id2 = 'nolock'.bin2hex(random_bytes(6));
$svc->init('/', 'locked.bin', strlen($payload2), $id2, 'rename');
$part2 = $stage.'/'.$id2.'/data.part';
touch($part2);

// A second process holds an exclusive lock for the duration, so the
// LOCK_NB acquisition inside append() genuinely fails every attempt --
// the same observable behaviour as a filesystem that has no locking.
$holder = $base.'/holder.php';
file_put_contents($holder, "<?php\n".
    '$h = fopen($argv[1], "c+b");'."\n".
    'flock($h, LOCK_EX);'."\n".
    'echo "HELD\n"; flush();'."\n".
    'sleep((int)$argv[2]);'."\n".
    'flock($h, LOCK_UN); fclose($h);'."\n");

$proc = proc_open([PHP_BINARY, $holder, $part2, '25'],
    [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$ready = is_resource($proc) ? fgets($pipes[1]) : false;

if ($ready !== false && trim((string)$ready) === 'HELD') {
    $started = microtime(true);
    $failure = null;
    try { $status = $sendChunk($svc, $id2, 0, $payload2); }
    catch (Throwable $e) { $failure = $e; $status = []; }
    $elapsed = microtime(true) - $started;

    $checks['a chunk still succeeds when the lock cannot be taken'] = $failure === null;
    if ($failure !== null) echo '       threw: '.$failure->getMessage().PHP_EOL;
    $checks['and the bytes landed'] = ($status['received'] ?? -1) === strlen($payload2);
    $checks['the part file holds exactly the chunk'] = @filesize($part2) === strlen($payload2);
    // Bounded wait, not a blocking flock(): a filesystem whose lock never
    // returns must not hold the request open until the browser gives up.
    $checks['the attempt gives up rather than blocking'] = $elapsed < 10.0;
    $checks['the fallback is recorded in the log'] =
        str_contains((string)@file_get_contents($errorLog), 'no advisory lock available');

    proc_terminate($proc);
    if (is_resource($proc)) proc_close($proc);
} else {
    echo "[SKIP] lock-contention checks could not start the holder process\n";
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
}

// --- the production failure, reproduced ----------------------------------

// A staging directory on a filesystem with no advisory locking at all. On the
// committed version this exact setup raises
//   RuntimeException 500 "Unable to lock the upload for writing"
// on the first chunk, which is what the browser reported as "An internal
// server error occurred" after 3 retries, with nothing written server-side.
NoLockFs::$realBase = $base.'/nolock-real';
mkdir(NoLockFs::$realBase, 0775, true);
mkdir($base.'/nolock-files', 0775, true);
stream_wrapper_register('nolock', 'NoLockFs');

$lockFreeFs = new FileService(['root_dir' => $base.'/nolock-files', 'read_only' => false]);
$lockFree = new UploadService([
    'root_dir' => $base.'/nolock-files', 'read_only' => false,
    'upload_abandon_hours' => 24, 'max_upload_mb' => 64,
    'upload_chunk_mb' => $chunkMb, 'upload_staging_dir' => 'nolock://stage',
    'upload_conflict' => 'rename',
], $lockFreeFs);

$payload3 = random_bytes($chunkBytes + 2048);
$id5 = 'lockfree'.bin2hex(random_bytes(6));
$lockFree->init('/', 'lockfree.bin', strlen($payload3), $id5, 'rename');

$lockFreeFailure = null;
$offset3 = 0;
try {
    while ($offset3 < strlen($payload3)) {
        $slice = substr($payload3, $offset3, $chunkBytes);
        $lockFree->append($id5, $offset3, (static function() use ($base, $slice): string {
            $f = $base.'/lf-'.bin2hex(random_bytes(4)).'.bin';
            file_put_contents($f, $slice);
            return $f;
        })());
        $offset3 += strlen($slice);
    }
    $lockFreeDone = $lockFree->complete($id5);
} catch (Throwable $e) {
    $lockFreeFailure = $e;
    $lockFreeDone = [];
}

$checks['a filesystem without flock() still accepts every chunk'] = $lockFreeFailure === null;
if ($lockFreeFailure !== null) {
    echo '       threw: '.get_class($lockFreeFailure).' '.$lockFreeFailure->getCode()
        .' '.$lockFreeFailure->getMessage().PHP_EOL;
}
$lockFreeLanded = $base.'/nolock-files'.($lockFreeDone['path'] ?? '/missing');
$checks['and the file it assembles is byte-identical'] =
    is_file($lockFreeLanded) && hash_file('sha256', $lockFreeLanded) === hash('sha256', $payload3);

// --- the positioned write, not the lock, is what prevents doubling -------

/*
 * Two requests that computed the same offset, which is what the deterministic
 * upload id made possible: the same file in two tabs produces two chunk
 * requests that both read offset 0.
 *
 * Stated as the two write modes rather than as a timing race, because the
 * property is not about who wins: an append lands at the end wherever the end
 * happens to be, while a positioned write lands where it was told. Racing two
 * processes tested the scheduler as much as the code, and did not always
 * arrange for both to observe the same starting size.
 */
$block = str_repeat('a', 65536);

$appendPart = $base.'/append-mode.part';
file_put_contents($appendPart, '');
foreach ([1, 2] as $ignored) {
    $h = fopen($appendPart, 'ab');   // the mode append() used before d75a835
    fwrite($h, $block);
    fclose($h);
}
clearstatcache(true, $appendPart);
$checks['the old append mode doubles the file'] = filesize($appendPart) === 131072;

$positionedPart = $base.'/positioned.part';
file_put_contents($positionedPart, '');
foreach ([1, 2] as $ignored) {
    $h = fopen($positionedPart, 'c+b');
    fseek($h, 0);                    // both computed the same offset
    fwrite($h, $block);
    fclose($h);
}
clearstatcache(true, $positionedPart);
$checks['a positioned write at one offset does not'] = filesize($positionedPart) === 65536;
$checks['and the bytes are the ones that were written'] =
    hash_file('sha256', $positionedPart) === hash('sha256', $block);

// --- the guards that must survive ----------------------------------------

$id3 = 'guards'.bin2hex(random_bytes(6));
$svc->init('/', 'guards.bin', $chunkBytes * 2, $id3, 'rename');
$sendChunk($svc, $id3, 0, str_repeat('x', $chunkBytes));

$mismatch = 0;
try { $sendChunk($svc, $id3, 0, str_repeat('y', 16)); }
catch (Throwable $e) { $mismatch = (int)$e->getCode(); }
$checks['a stale offset is still refused'] = $mismatch === 409;

$oversized = 0;
try { $sendChunk($svc, $id3, $chunkBytes, str_repeat('z', $chunkBytes + 1024)); }
catch (Throwable $e) { $oversized = (int)$e->getCode(); }
$checks['an oversized chunk is still refused'] = $oversized === 413;

// A part file that cannot be opened at all still fails, and names itself.
if (posix_getuid() !== 0) {
    $id4 = 'unopenable'.bin2hex(random_bytes(6));
    $svc->init('/', 'unopenable.bin', 1024, $id4, 'rename');
    $dir4 = $stage.'/'.$id4;
    touch($dir4.'/data.part');
    chmod($dir4.'/data.part', 0000);
    chmod($dir4, 0500);
    $msg = '';
    try { $sendChunk($svc, $id4, 0, str_repeat('q', 512)); }
    catch (Throwable $e) { $msg = $e->getMessage(); }
    $checks['an unopenable part file fails with its path'] = str_contains($msg, 'data.part');
    chmod($dir4, 0755);
    chmod($dir4.'/data.part', 0644);
} else {
    echo "[SKIP] unopenable-part check needs an unprivileged user\n";
}

// --- source-level: nothing in this path insists on a lock ----------------

$upload = (string)file_get_contents($root.'/src/Services/UploadService.php');
$checks['the lock is never required'] =
    !str_contains($upload, "throw new RuntimeException('Unable to lock the upload for writing', 500)");
$checks['the lock is taken without blocking'] = str_contains($upload, '@flock($out, LOCK_EX | LOCK_NB)');
$checks['it is only released when it was taken'] = str_contains($upload, 'if ($locked) @flock($out, LOCK_UN);');
$checks['a refused open mode falls back'] = str_contains($upload, "reopened r+b");

// The log has somewhere to go, so the next 500 is readable on the device.
$bootstrap = (string)file_get_contents($root.'/config/bootstrap.php');
$checks['the error log has a default destination'] =
    str_contains($bootstrap, "ini_set('error_log', \$logDir.'/php-error.log');");
$checks['an explicit error_log still wins'] =
    str_contains($bootstrap, "if (trim((string)ini_get('error_log')) === '') {");

rmrf28($base);
@unlink($errorLog);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
