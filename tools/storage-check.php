<?php
declare(strict_types=1);

/**
 * Report whether PHP can actually read and write the configured storage.
 *
 * This lived at the project root and was reachable over HTTP by anyone, with no
 * authentication, dumping absolute filesystem paths and a full listing of every
 * user file. It is a diagnostic, so it now runs from the command line only:
 *
 *   php tools/storage-check.php
 *
 * Permission checks are real create/write/delete probes rather than
 * is_writable(), which is unreliable on Android shared storage -- the same
 * reason UploadService probes instead of trusting the permission bits.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/config/bootstrap.php';

/** @var array $config from config/bootstrap.php */
$targets = [
    'ROOT_DIR (served files)' => (string)$config['root_dir'],
    'upload staging' => ((string)$config['upload_staging_dir']) ?: dirname(__DIR__).'/storage/uploads',
    'thumbnail cache' => dirname(__DIR__).'/storage/.thumbnails',
    'logs' => dirname(__DIR__).'/logs',
];

$problems = 0;

foreach ($targets as $label => $path) {
    echo $label.PHP_EOL;
    echo '  path      : '.$path.PHP_EOL;

    $real = realpath($path);
    echo '  resolved  : '.($real === false ? '(does not resolve)' : $real).PHP_EOL;
    echo '  exists    : '.(file_exists($path) ? 'yes' : 'no').PHP_EOL;
    echo '  directory : '.(is_dir($path) ? 'yes' : 'no').PHP_EOL;
    echo '  readable  : '.(is_readable($path) ? 'yes' : 'no').PHP_EOL;

    if (!is_dir($path)) {
        echo '  writable  : n/a'.PHP_EOL.PHP_EOL;
        $problems++;
        continue;
    }

    $probe = rtrim($path, '/').'/.storage-check-'.bin2hex(random_bytes(6));
    $written = @file_put_contents($probe, 'probe');
    $removed = $written !== false && @unlink($probe);
    echo '  writable  : '.($removed ? 'yes' : 'NO — PHP cannot create/remove files here').PHP_EOL;
    if (!$removed) {
        @unlink($probe);
        $problems++;
    }

    $entries = is_readable($path) ? (scandir($path) ?: []) : [];
    $visible = array_values(array_filter($entries, static fn($n) => $n !== '.' && $n !== '..'));
    echo '  entries   : '.count($visible).PHP_EOL.PHP_EOL;
}

echo $problems === 0
    ? "Storage looks usable.\n"
    : $problems." problem(s) found. The PHP/web-server user needs read and write access to the paths above.\n";

/* ------------------------------------------------------------------------
 * Throughput and finalise cost.
 *
 * Everything above answers "can PHP write here". These answer "how fast", and
 * they exist because an upload that is slow on a phone cannot be diagnosed
 * from a development container: different CPU, different filesystem, no FUSE.
 * Guessing at it from here has already been wrong twice.
 *
 * The write probe uses 1 MiB blocks because that is exactly what
 * UploadService::append() does, so the number is what an upload would get
 * rather than a synthetic best case.
 * ---------------------------------------------------------------------- */

$megabytes = max(1, min(512, (int)($argv[1] ?? 32)));
$rootDir = (string)$config['root_dir'];
$stagingDir = ((string)$config['upload_staging_dir']) ?: dirname(__DIR__).'/storage/uploads';

echo PHP_EOL.'Runtime'.PHP_EOL;
echo '  php       : '.PHP_VERSION.' ('.PHP_SAPI.')'.PHP_EOL;
foreach (['memory_limit', 'max_execution_time', 'output_buffering', 'post_max_size', 'upload_max_filesize'] as $key) {
    echo '  '.str_pad($key, 10).': '.var_export(ini_get($key), true).PHP_EOL;
}
echo '  chunk size: '.$config['upload_chunk_mb'].' MB'.PHP_EOL;

/** Bytes for a php.ini shorthand size such as "8M" or "512K". */
function ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower($value[strlen($value) - 1]);
    $n = (int)$value;
    return match ($unit) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
}

// Chunks are sent as a raw PUT body, which PHP does not measure against
// post_max_size -- but a web server in front of it may well have its own limit,
// and the README already asks for these to sit above the chunk size.
$chunkBytes = max(1, (int)$config['upload_chunk_mb']) * 1024 * 1024;
foreach (['post_max_size', 'upload_max_filesize'] as $key) {
    $limit = ini_bytes((string)ini_get($key));
    if ($limit > 0 && $limit <= $chunkBytes) {
        echo '  ! '.$key.' ('.ini_get($key).') is not above the '.$config['upload_chunk_mb']
            .' MB chunk size; lower UPLOAD_CHUNK_MB or raise it.'.PHP_EOL;
        $problems++;
    }
}

// So "is the fix actually deployed" stops being a question.
$head = @shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' rev-parse --short HEAD 2>/dev/null');
echo '  commit    : '.($head ? trim($head) : '(not a git checkout)').PHP_EOL;

/** Measured write, then read, in the same block size the uploader uses. */
function measure_io(string $dir, int $megabytes): ?array {
    if (!is_dir($dir)) return null;
    $file = rtrim($dir, '/').'/.storage-check-io-'.bin2hex(random_bytes(6));
    $block = str_repeat("\0", 1024 * 1024);

    $handle = @fopen($file, 'wb');
    if ($handle === false) return null;
    $start = microtime(true);
    for ($i = 0; $i < $megabytes; $i++) {
        if (fwrite($handle, $block) === false) { fclose($handle); @unlink($file); return null; }
    }
    fflush($handle);
    fclose($handle);
    $writeSeconds = microtime(true) - $start;

    clearstatcache(true, $file);
    $handle = @fopen($file, 'rb');
    if ($handle === false) { @unlink($file); return null; }
    $start = microtime(true);
    while (!feof($handle)) { if (fread($handle, 1024 * 1024) === false) break; }
    fclose($handle);
    $readSeconds = microtime(true) - $start;

    return ['file' => $file, 'writeMbps' => $megabytes / max($writeSeconds, 0.000001),
            'readMbps' => $megabytes / max($readSeconds, 0.000001)];
}

// Deliberately measured through PHP's own stream functions rather than with
// dd: this is the throughput an upload actually gets, interpreter overhead
// included, which is the number that matters here. Expect it to read lower
// than a raw device benchmark.
echo PHP_EOL.'Throughput ('.$megabytes.' MB, 1 MiB blocks through PHP, as the uploader writes)'.PHP_EOL;
$written = [];
foreach (['upload staging' => $stagingDir, 'ROOT_DIR' => $rootDir] as $label => $dir) {
    $io = measure_io($dir, $megabytes);
    if ($io === null) {
        echo '  '.str_pad($label, 15).': could not measure'.PHP_EOL;
        continue;
    }
    printf("  %-15s: write %6.1f MB/s | read %6.1f MB/s%s", $label, $io['writeMbps'], $io['readMbps'], PHP_EOL);
    $written[$label] = $io['file'];
}

/* ------------------------------------------------------------------------
 * Finishing an upload is a rename when staging and ROOT_DIR share a
 * filesystem and a whole-file copy when they do not -- UploadService::complete()
 * falls back to copy() on EXDEV. On a large file that is a second full write
 * of every byte, and it happens all at once at the end.
 * ---------------------------------------------------------------------- */
echo PHP_EOL.'Finishing an upload'.PHP_EOL;

/**
 * Which filesystem a path is on, resolving to the nearest existing ancestor.
 *
 * A staging directory that has not been created yet is not on a different
 * filesystem -- it is on whichever one its parent is, which is where it will be
 * created. Reporting a missing directory as a cross-device copy is a false
 * alarm, and this diagnostic exists to end guesswork rather than add to it.
 */
function device_of(string $path): ?int {
    $candidate = $path;
    for ($i = 0; $i < 32; $i++) {
        $stat = @stat($candidate);
        if ($stat !== false) return (int)$stat['dev'];
        $parent = dirname($candidate);
        if ($parent === $candidate) return null;
        $candidate = $parent;
    }
    return null;
}

$stagingDev = device_of($stagingDir);
$rootDev = device_of($rootDir);
echo '  staging device : '.($stagingDev === null ? 'unknown' : (string)$stagingDev)
    .(is_dir($stagingDir) ? '' : ' (directory not created yet; taken from its parent)').PHP_EOL;
echo '  ROOT_DIR device: '.($rootDev === null ? 'unknown' : (string)$rootDev).PHP_EOL;

if ($stagingDev === null || $rootDev === null) {
    echo '  verdict        : could not determine; one of the paths does not resolve.'.PHP_EOL;
} elseif ($stagingDev === $rootDev) {
    echo '  verdict        : same filesystem — an instant rename, nothing is copied.'.PHP_EOL;
} else {
    echo '  verdict        : DIFFERENT filesystems — every completed upload is copied in full,'.PHP_EOL;
    echo '                   a second write of the whole file. Point UPLOAD_STAGING_DIR at a'.PHP_EOL;
    echo '                   directory on the same filesystem as ROOT_DIR.'.PHP_EOL;
}

// Timed for real, since the verdict above is a prediction and this is the fact.
if (isset($written['upload staging'])) {
    $source = $written['upload staging'];
    $target = rtrim($rootDir, '/').'/.storage-check-move-'.bin2hex(random_bytes(6));
    $start = microtime(true);
    $renamed = @rename($source, $target);
    $moveSeconds = microtime(true) - $start;
    if ($renamed) {
        printf("  measured move  : %.2f s for %d MB (%s)%s", $moveSeconds, $megabytes,
            $moveSeconds < 0.05 ? 'a rename, as expected' : 'slower than a rename should be', PHP_EOL);
        @unlink($target);
        unset($written['upload staging']);
    } else {
        echo '  measured move  : rename refused; complete() would fall back to copying.'.PHP_EOL;
    }
}

foreach ($written as $file) @unlink($file);

exit($problems === 0 ? 0 : 1);
