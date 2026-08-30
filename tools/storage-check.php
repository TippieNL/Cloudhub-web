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

exit($problems === 0 ? 0 : 1);
