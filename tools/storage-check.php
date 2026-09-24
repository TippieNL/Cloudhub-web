<?php
declare(strict_types=1);

/**
 * Report whether PHP can read and write the configured storage, and how fast.
 *
 * This lived at the project root and was reachable over HTTP by anyone, with no
 * authentication, dumping absolute filesystem paths and a full listing of every
 * user file. It is a diagnostic, so it runs from the command line only:
 *
 *   php tools/storage-check.php          facts only
 *   php tools/storage-check.php 32       and measure 32 MB of throughput
 *
 * An install without PHP CLI -- which KSWEB may well be -- can get the same
 * report from GET /api/system/storage, which is administrator-only. Prefer that
 * one where the shell would be a different PHP from the one serving requests:
 * it would report its own ini and permissions rather than the server's.
 *
 * The report itself lives in StorageDiagnostics so the two renderings cannot
 * drift; this file only prints it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__).'/config/bootstrap.php';

use CloudHub\Services\StorageDiagnostics;

/** @var array $config from config/bootstrap.php */
$megabytes = max(0, min(512, (int)($argv[1] ?? 0)));
$report = (new StorageDiagnostics($config, dirname(__DIR__)))->report($megabytes);

foreach ($report['paths'] as $row) {
    echo $row['label'].PHP_EOL;
    echo '  path      : '.$row['path'].PHP_EOL;
    echo '  resolved  : '.($row['resolved'] ?? '(does not resolve)').PHP_EOL;
    echo '  exists    : '.($row['exists'] ? 'yes' : 'no').PHP_EOL;
    echo '  directory : '.($row['isDirectory'] ? 'yes' : 'no').PHP_EOL;
    echo '  readable  : '.($row['readable'] ? 'yes' : 'no').PHP_EOL;
    echo '  writable  : '.match ($row['writable']) {
        null => ($row['createdOnDemand'] ?? false)
            ? ('n/a — created on first use'.(($row['parentWritable'] ?? false) ? '' : ', BUT the directory above it is not writable'))
            : 'n/a',
        true => 'yes',
        false => 'NO — PHP cannot create/remove files here',
    }.PHP_EOL;
    if ($row['entries'] !== null) echo '  entries   : '.$row['entries'].PHP_EOL;
    echo PHP_EOL;
}

$runtime = $report['runtime'];
echo 'Runtime'.PHP_EOL;
echo '  php       : '.$runtime['php'].' ('.$runtime['sapi'].')'.PHP_EOL;
foreach ($runtime['limits'] as $key => $value) {
    echo '  '.str_pad($key, 10).': '.var_export($value, true).PHP_EOL;
}
echo '  chunk size: '.$runtime['chunkMb'].' MB'.PHP_EOL;
foreach ($runtime['warnings'] as $warning) echo '  ! '.$warning.PHP_EOL;
echo '  commit    : '.($runtime['commit'] ?? '(not a git checkout)').PHP_EOL;
foreach ($runtime['sources'] as $label => $hash) {
    echo '  '.str_pad($label, 10).': '.($hash ?? 'missing').PHP_EOL;
}

$finishing = $report['finishing'];
echo PHP_EOL.'Finishing an upload'.PHP_EOL;
echo '  staging device : '.($finishing['stagingDevice'] ?? 'unknown')
    .($finishing['stagingExists'] ? '' : ' (not created yet; taken from its parent)').PHP_EOL;
echo '  ROOT_DIR device: '.($finishing['rootDevice'] ?? 'unknown').PHP_EOL;
echo '  verdict        : '.$finishing['verdict'].PHP_EOL;

if ($report['throughput'] !== null) {
    // Measured through PHP's own stream functions rather than with dd: this is
    // the throughput an upload actually gets, interpreter overhead included.
    // Expect it to read lower than a raw device benchmark.
    echo PHP_EOL.'Throughput ('.$report['throughput']['megabytes'].' MB, 1 MiB blocks through PHP)'.PHP_EOL;
    foreach (['upload staging', 'ROOT_DIR (served files)'] as $label) {
        $io = $report['throughput'][$label] ?? null;
        echo '  '.str_pad($label, 24).': '
            .($io === null ? 'could not measure'
                : sprintf('write %6.1f MB/s | read %6.1f MB/s', $io['writeMbPerSecond'], $io['readMbPerSecond']))
            .PHP_EOL;
    }
} else {
    echo PHP_EOL.'Throughput: not measured. Pass a size in MB to measure it, e.g. '
        .basename(__FILE__).' 32'.PHP_EOL;
}

$cache = $report['cache'];
echo PHP_EOL.'Application cache'.PHP_EOL;
echo '  driver    : '.$cache['driver'].($cache['enabled'] ? '' : ' (off: '.($cache['reason'] ?? 'unknown').')').PHP_EOL;
echo '  path      : '.$cache['path'].PHP_EOL;
echo '  working   : '.match ($cache['working']) { true => 'yes', false => 'NO', null => 'n/a' }.PHP_EOL;
echo '  ttl       : '.$cache['ttlSeconds'].' s; answers under '.$cache['minComputeMs'].' ms are not kept'.PHP_EOL;

echo PHP_EOL.($report['problems'] === []
    ? "Storage looks usable.\n"
    : count($report['problems'])." problem(s) found:\n  - ".implode("\n  - ", $report['problems'])."\n");

exit($report['problems'] === [] ? 0 : 1);
