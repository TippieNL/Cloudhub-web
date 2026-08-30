<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Helpers/Http.php';
use CloudHub\Helpers\Http;

/**
 * Http::assetBase() must survive both document-root layouts.
 *
 * SCRIPT_NAME is '/index.php' whether the document root is the project root or
 * public/, so only SCRIPT_FILENAME can tell them apart. Emitting
 * basePath().'/public/assets' unconditionally 404s every stylesheet and script
 * whenever the document root is public/ — the layout the README recommends.
 */
$root = dirname(__DIR__);

$cases = [
    // [SCRIPT_NAME, SCRIPT_FILENAME, expected basePath, expected assetBase]
    ['/index.php',                     $root.'/index.php',                    '',                    '/public'],
    ['/index.php',                     $root.'/public/index.php',             '',                    ''],
    ['/Cloud-File-Hub-PHP/index.php',  $root.'/index.php',                    '/Cloud-File-Hub-PHP', '/Cloud-File-Hub-PHP/public'],
    ['/Cloud-File-Hub-PHP/index.php',  $root.'/public/index.php',             '/Cloud-File-Hub-PHP', '/Cloud-File-Hub-PHP'],
    ['/public/index.php',              $root.'/public/index.php',             '',                    ''],
    // router.php runs from the project root, so assets keep the /public prefix.
    ['/index.php',                     $root.'/router.php',                   '',                    '/public'],
];

$bad = false;
foreach ($cases as [$scriptName, $scriptFile, $expectedBase, $expectedAssets]) {
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $_SERVER['SCRIPT_FILENAME'] = $scriptFile;
    $base = Http::basePath();
    $assets = Http::assetBase();
    $ok = $base === $expectedBase && $assets === $expectedAssets;
    echo ($ok ? '[PASS] ' : '[FAIL] ')
        .$scriptName.' via '.str_replace($root, '.', $scriptFile)
        .' => base='.var_export($base, true).' assets='.var_export($assets, true)
        .($ok ? '' : ' (expected base='.var_export($expectedBase, true).' assets='.var_export($expectedAssets, true).')')
        .PHP_EOL;
    $bad = $bad || !$ok;
}

// The views must build asset URLs from assetBase, never from basePath.
foreach (['views/pages/app.php', 'views/pages/play.php'] as $view) {
    $src = (string)file_get_contents($root.'/'.$view);
    $ok = !str_contains($src, '$basePath, ENT_QUOTES) ?>/public/')
        && !str_contains($src, '$base ?>/public/')
        && str_contains($src, 'assets/js/');
    echo ($ok ? '[PASS] ' : '[FAIL] ').$view.' uses assetBase for static files'.PHP_EOL;
    $bad = $bad || !$ok;
}

exit($bad ? 1 : 0);
