<?php
declare(strict_types=1);

/**
 * Run every check script in this directory.
 *
 * Usage: php tests/run.php
 *
 * A script fails the run if it exits non-zero *or* emits a warning/notice:
 * several checks previously interpolated undefined variables into their
 * needles, which made them assert on a truncated string and pass by accident.
 */
$dir = __DIR__;
$scripts = glob($dir.'/*_test.php') ?: [];
sort($scripts);

$php = PHP_BINARY;
$failed = [];

foreach ($scripts as $script) {
    $name = basename($script);
    $cmd = escapeshellarg($php).' -d error_reporting=E_ALL -d display_errors=1 '.escapeshellarg($script).' 2>&1';
    exec($cmd, $lines, $code);
    $output = implode(PHP_EOL, $lines);
    $lines = [];

    $noisy = (bool)preg_match('/\b(Warning|Notice|Deprecated|Fatal error)\b/', $output);
    $ok = $code === 0 && !$noisy;
    if (!$ok) $failed[$name] = $output;

    printf("%-44s %s%s%s".PHP_EOL, $name, $ok ? 'ok' : 'FAILED',
        $code !== 0 ? ' (exit '.$code.')' : '',
        $noisy ? ' (diagnostics emitted)' : '');
}

if ($failed) {
    echo PHP_EOL.str_repeat('-', 60).PHP_EOL;
    foreach ($failed as $name => $output) {
        echo PHP_EOL.$name.':'.PHP_EOL.$output.PHP_EOL;
    }
    echo PHP_EOL.count($failed).' of '.count($scripts).' scripts failed.'.PHP_EOL;
    exit(1);
}

echo PHP_EOL.'All '.count($scripts).' check scripts passed.'.PHP_EOL;
exit(0);
