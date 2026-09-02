<?php
declare(strict_types=1);

/**
 * What one chunk costs the server.
 *
 * The interesting case is a filesystem with no advisory locking, because that
 * is the one this application actually runs on and the one where a retry loop
 * waits for something that can never happen. Driven through the same stream
 * wrapper phase28 uses, so the cost is measured rather than reasoned about.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/UploadService.php';

use CloudHub\Services\FileService;
use CloudHub\Services\UploadService;

function rmrf30(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf30($p.'/'.$n); @rmdir($p); }
}

/** A filesystem that works normally but has no advisory locking, as phase28 defines. */
class NoLockFs30 {
    public $context;
    public static string $realBase = '';
    private $fh = null;
    private $dh = null;
    private static function real(string $path): string {
        return self::$realBase.'/'.ltrim(substr($path, strlen('nolock30://')), '/');
    }
    public function stream_open($path, $mode, $options, &$opened) { $this->fh = @fopen(self::real($path), $mode); return $this->fh !== false; }
    public function stream_read($n) { return fread($this->fh, $n); }
    public function stream_write($d) { return fwrite($this->fh, $d); }
    public function stream_eof() { return feof($this->fh); }
    public function stream_seek($o, $w = SEEK_SET) { return fseek($this->fh, $o, $w) === 0; }
    public function stream_tell() { return ftell($this->fh); }
    public function stream_stat() { return fstat($this->fh); }
    public function stream_flush() { return fflush($this->fh); }
    public function stream_close() { return fclose($this->fh); }
    public function stream_truncate($size) { return ftruncate($this->fh, $size); }
    // stream_lock() intentionally absent.
    public function url_stat($path, $flags) { return @stat(self::real($path)) ?: false; }
    public function unlink($path) { return @unlink(self::real($path)); }
    public function rename($from, $to) { return @rename(self::real($from), self::real($to)); }
    public function mkdir($path, $mode, $options) { return @mkdir(self::real($path), $mode, (bool)($options & STREAM_MKDIR_RECURSIVE)); }
    public function rmdir($path, $options) { return @rmdir(self::real($path)); }
    public function dir_opendir($path, $options) { $this->dh = @opendir(self::real($path)); return $this->dh !== false; }
    public function dir_readdir() { return readdir($this->dh); }
    public function dir_rewinddir() { rewinddir($this->dh); return true; }
    public function dir_closedir() { closedir($this->dh); return true; }
    public function stream_metadata($path, $option, $value) {
        $real = self::real($path);
        return match ($option) {
            STREAM_META_TOUCH => @touch($real, ...(array)($value ?: [])),
            STREAM_META_ACCESS => @chmod($real, $value),
            default => false,
        };
    }
}

$root = dirname(__DIR__);
$checks = [];

$errorLog = sys_get_temp_dir().'/cloudhub-p30-'.bin2hex(random_bytes(4)).'.log';
ini_set('error_log', $errorLog);

$base = sys_get_temp_dir().'/cloudhub-p30-'.bin2hex(random_bytes(5));
mkdir($base.'/files', 0775, true);
mkdir($base.'/realstage', 0775, true);
NoLockFs30::$realBase = $base.'/realstage';
stream_wrapper_register('nolock30', 'NoLockFs30');

$_SESSION['user_id'] = 7;
$chunkMb = 1;
$chunk = $chunkMb * 1024 * 1024;
$chunks = 6;

$fs = new FileService(['root_dir' => $base.'/files', 'read_only' => false]);
$svc = new UploadService([
    'root_dir' => $base.'/files', 'read_only' => false, 'upload_abandon_hours' => 24,
    'max_upload_mb' => 64, 'upload_chunk_mb' => $chunkMb,
    'upload_staging_dir' => 'nolock30://stage', 'upload_conflict' => 'rename',
], $fs);

$payload = random_bytes($chunk * $chunks);
$id = 'thr'.bin2hex(random_bytes(6));
$svc->init('/', 'throughput.bin', strlen($payload), $id, 'rename');

$chunkFile = $base.'/chunk.bin';
$elapsed = [];
$statuses = [];
for ($i = 0; $i < $chunks; $i++) {
    file_put_contents($chunkFile, substr($payload, $i * $chunk, $chunk));
    $t = microtime(true);
    $statuses[] = $svc->append($id, $i * $chunk, $chunkFile);
    $elapsed[] = (microtime(true) - $t) * 1000;
}
$worst = max($elapsed);

/*
 * The regression this file exists for: lockForWriting() retried twenty times
 * with a 50 ms sleep, so on a filesystem without locking every chunk cost a
 * flat second waiting for a lock that could never be granted -- about 78
 * seconds of a 605 MB upload. A generous ceiling, since the point is to catch a
 * reintroduced sleep, not to police normal I/O variation.
 */
$checks['a chunk does not wait on a lock that cannot exist'] = $worst < 400;
if ($worst >= 400) printf("       worst chunk %.0f ms (per-chunk: %s)\n", $worst, implode(', ', array_map(fn($m) => round($m).'ms', $elapsed)));

$checks['the bytes still land in order'] = ($statuses[$chunks - 1]['received'] ?? -1) === strlen($payload);
$done = $svc->complete($id);
$landed = $base.'/files'.$done['path'];
$checks['the assembled file is byte-identical'] =
    is_file($landed) && hash_file('sha256', $landed) === hash('sha256', $payload);

// Reported so a client can separate its own waiting from the server's work.
$checks['each chunk reports the time the server spent'] =
    isset($statuses[0]['serverMs']) && is_int($statuses[0]['serverMs']);
$checks['and the figure is plausible'] =
    ($statuses[0]['serverMs'] ?? -1) >= 0 && ($statuses[0]['serverMs'] ?? 99999) < 5000;

// One line per upload, not one per chunk.
$logged = substr_count((string)@file_get_contents($errorLog), 'no advisory lock available');
$checks['the missing lock is reported once, not per chunk'] = $logged === 1;
if ($logged !== 1) echo "       logged $logged times across $chunks chunks\n";

// --- the abandon TTL still sees an upload in progress --------------------

$ttlId = 'ttl'.bin2hex(random_bytes(6));
$svc->init('/', 'ttl.bin', $chunk * 2, $ttlId, 'rename');
$sessionDir = $base.'/realstage/stage/'.$ttlId;
touch($sessionDir, time() - 86400 * 3);          // pretend it has been idle for days
clearstatcache();
$before = filemtime($sessionDir);
file_put_contents($chunkFile, substr($payload, 0, $chunk));
$svc->append($ttlId, 0, $chunkFile);
clearstatcache();
$checks['a chunk keeps the session directory fresh'] = filemtime($sessionDir) > $before;

// --- the per-chunk metadata rewrite is gone ------------------------------

$upload = (string)file_get_contents($root.'/src/Services/UploadService.php');
$checks['metadata is no longer rewritten per chunk'] =
    !str_contains($upload, "\$meta['updatedAt'] = time();\n        \$this->writeMeta(\$id, \$meta);");
$checks['the session directory is touched instead'] = str_contains($upload, '@touch($this->sessionDir($id));');
// init and complete still manage real metadata.
$checks['init still writes metadata'] = str_contains($upload, '$this->writeMeta($id, $meta);');

$checks['the lock is attempted once'] =
    str_contains($upload, 'if (@flock($out, LOCK_EX | LOCK_NB)) return true;')
    && !str_contains($upload, 'usleep(50000)');
$checks['no sleep remains in the upload path'] =
    !preg_match('/\b(usleep|sleep)\s*\(/', $upload);

$index = (string)file_get_contents($root.'/public/index.php');
$chunkRoute = (static function(string $i): string {
    $at = strpos($i, "if (\$path === '/api/uploads/chunk'");
    return $at === false ? '' : substr($i, $at, (int)strpos($i, "\n});", $at) - $at);
})($index);
$checks['the chunk route releases the session lock'] =
    $chunkRoute !== '' && str_contains($chunkRoute, 'release_session_lock();');

// --- the client reports both halves --------------------------------------

$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$checks['the client accumulates the server time'] = str_contains($app, 'uploadTiming.serverMs += Number(state.serverMs) || 0;');
$checks['and reports it against the wall clock'] =
    str_contains($app, 'in the server, ') && str_contains($app, 'in transfer');

rmrf30($base);
@unlink($errorLog);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
