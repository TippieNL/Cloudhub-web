<?php
declare(strict_types=1);

/**
 * The autoloader reports a missing source file instead of hiding it.
 *
 * A KSWEB deployment whose src/ had not been copied across logged only:
 *
 *   Error: Class "CloudHub\Services\FileService" not found in .../public/index.php:16
 *
 * which names a class that was never the problem and no path at all. The
 * autoloader had skipped the missing file silently, leaving PHP to raise the
 * class error further down. It now throws where the fault actually is, naming
 * the path it tried.
 *
 * Both halves run for real, in a subprocess against a tree with src/ removed,
 * because the failure only reproduces when the file genuinely is not there.
 */
$root = dirname(__DIR__);
$checks = [];

function rmrf21(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf21($p.'/'.$n); @rmdir($p); }
}

// A minimal tree: config/ present, src/ absent -- exactly the deployed state.
$base = sys_get_temp_dir().'/cloudhub-p21-'.bin2hex(random_bytes(5));
mkdir($base.'/config', 0775, true);
copy($root.'/config/bootstrap.php', $base.'/config/bootstrap.php');
copy($root.'/config/config.php', $base.'/config/config.php');

$run = static function (string $base): array {
    $code = 'require '.var_export($base.'/config/bootstrap.php', true).';'
        .'try { new CloudHub\Services\FileService([]); echo "NO-THROW"; }'
        .'catch (Throwable $e) { echo get_class($e)."\n".$e->getMessage(); }';
    $cmd = escapeshellarg(PHP_BINARY).' -d error_reporting=E_ALL -d display_errors=1 -r '
        .escapeshellarg($code).' 2>&1';
    exec($cmd, $lines, $exit);
    return [implode(PHP_EOL, $lines), $exit];
};

[$out] = $run($base);

$checks['a missing class file throws rather than passing through'] =
    !str_contains($out, 'NO-THROW') && str_contains($out, 'RuntimeException');
$checks['the message names the class that could not be loaded'] =
    str_contains($out, 'CloudHub\Services\FileService');
$checks['the message names the path that was tried'] =
    str_contains($out, '/src/Services/FileService.php');
$checks['the message says what to check'] =
    str_contains($out, 'missing or unreadable') && str_contains($out, 'src/');
// The old symptom: PHP's own "Class not found", raised at the use site with
// no path. Reaching that again means the autoloader went quiet.
$checks['it is no longer reported as a bare Class-not-found'] =
    !str_contains($out, 'not found in Command line code');

// With src/ in place the autoloader must stay silent and simply load.
symlink($root.'/src', $base.'/src');
[$ok] = $run($base);
$checks['a class that does exist still loads'] =
    str_contains($ok, 'NO-THROW') || str_contains($ok, 'Storage root is unavailable');
$checks['loading a real class raises no autoload error'] =
    !str_contains($ok, 'Cannot load');

rmrf21($base);

// Classes outside the prefix belong to other autoloaders; throwing for them
// would break class_exists() probes such as the ZipArchive check.
$src = (string)file_get_contents($root.'/config/bootstrap.php');
$checks['non-CloudHub classes are left to other autoloaders'] =
    str_contains($src, "if (!str_starts_with(\$class, \$prefix)) return;");
$checks['the ZipArchive probe is unaffected'] =
    str_contains((string)file_get_contents($root.'/public/index.php'), "class_exists('ZipArchive')");

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
