<?php
declare(strict_types=1);

/**
 * The low-priority audit findings, pinned so they cannot creep back.
 */
$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$appView = (string)file_get_contents($root.'/views/pages/app.php');
$component = (string)file_get_contents($root.'/views/pages/player_component.php');
$htaccess = (string)file_get_contents($root.'/.htaccess');
$nginx = (string)file_get_contents($root.'/deploy/nginx-security.conf.example');
$readme = (string)file_get_contents($root.'/README.md');
$security = (string)file_get_contents($root.'/src/Services/Security.php');

$checks = [];

// L1 -- generated links must derive their scheme from the trust_proxy gate.
$checks['L1 only Security::isHttps reads X-Forwarded-Proto'] =
    substr_count($index, 'HTTP_X_FORWARDED_PROTO') === 0
    && substr_count($security, 'HTTP_X_FORWARDED_PROTO') === 1;
$checks['L1 share URLs go through public_origin()'] =
    str_contains($index, 'function public_origin(') && str_contains($index, 'Security::isHttps($config)');

// L2 -- a target that is not a directory is a bad request, not a server error.
$checks['L2 upload target error is accurate'] =
    str_contains($index, "throw new RuntimeException('The upload target is not a directory', 400)")
    && !str_contains($index, 'Unable to create the upload directory');

// L3/L4 -- the player component belongs to /play alone.
$checks['L3 unused nonceAttr is gone'] = !str_contains($component, '$nonceAttr');
$checks['L4 app.php no longer includes the player'] =
    !str_contains($appView, 'player_component.php') && !str_contains($appView, 'PlayerUI.js')
    && !str_contains($appView, 'player.css');
$checks['L4 play.php still loads the player'] = (function () use ($root): bool {
    $play = (string)file_get_contents($root.'/views/pages/play.php');
    return str_contains($play, 'player_component.php') && str_contains($play, 'PlayerUI.js')
        && str_contains($play, 'player.css');
})();

// L5 -- orphan stylesheets removed.
$checks['L5 orphan player stylesheets are gone'] =
    !is_file($root.'/public/assets/css/player/main.css') && !is_file($root.'/public/assets/css/player/overlays.css');

// L6 -- the storage diagnostic is CLI-only and out of the web root.
$checks['L6 web-reachable storage-check.php is gone'] = !is_file($root.'/storage-check.php');
$checks['L6 the diagnostic survives as a CLI tool'] = is_file($root.'/tools/storage-check.php');
$checks['L6 the CLI tool refuses web requests'] =
    str_contains((string)file_get_contents($root.'/tools/storage-check.php'), "PHP_SAPI !== 'cli'");

// L11 -- the ffmpeg diagnostic described an architecture that no longer exists.
// It read ffmpeg, ffprobe, projectBin and cacheDir from
// VideoThumbnailService::diagnostics(), which returns none of them, so it
// emitted warnings and reported nothing. Frames are extracted in the browser
// now and the vendored bin/ went with the initial import.
$checks['L11 the stale ffmpeg diagnostic is gone'] = !is_file($root.'/tools/ffmpeg-check.php');
$checks['L11 the thumbnail service is still browser-based'] =
    str_contains((string)file_get_contents($root.'/src/Services/VideoThumbnailService.php'), "'mode' => 'browser'");
// The KSWEB ffmpeg guide went with it: it documented copying binaries into
// bin/ (dropped at import), FFMPEG_PATH (not read by config.php) and a check
// tool that no longer exists, and pointed at GET /api/system/ffmpeg, which
// never existed at all. The README covers how video thumbnails actually work.
$checks['L11 the stale ffmpeg guide is gone'] = !is_file($root.'/docs_KSWEB_FFMPEG.md');
// Scanned over shipped files only: tests/ names removed things on purpose, to
// assert they stay removed, and this very check contains the string it looks
// for. A hygiene rule that trips on its own wording is measuring itself.
$advertised = [];
foreach (['public', 'src', 'views', 'config', 'tools', 'database', 'deploy'] as $dir) {
    if (!is_dir($root.'/'.$dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        if (!in_array($file->getExtension(), ['php', 'js', 'md', 'html'], true)) continue;
        if (str_contains((string)file_get_contents($file->getPathname()), 'api/system/ffmpeg')) {
            $advertised[] = $file->getFilename();
        }
    }
}
foreach (glob($root.'/*.md') ?: [] as $doc) {
    if (str_contains((string)file_get_contents($doc), 'api/system/ffmpeg')) $advertised[] = basename($doc);
}
$checks['L11 nothing still advertises the ffmpeg endpoint'] = $advertised === [];
if ($advertised !== []) echo '       still referenced in: '.implode(', ', $advertised).PHP_EOL;
// The diagnostic that replaced it is reachable and admin-gated.
$checks['L11 the storage diagnostic has a route'] =
    str_contains((string)file_get_contents($root.'/public/index.php'), "'/api/system/storage'");

// Every remaining tool refuses to run as a web request.
foreach (glob($root.'/tools/*.php') ?: [] as $tool) {
    $checks['L11 '.basename($tool).' refuses web requests'] =
        (bool)preg_match("/PHP_SAPI\s*!==\s*'cli'/", (string)file_get_contents($tool));
}

// L7 -- deny rules cover every application directory, on both server families.
foreach (['config', 'src', 'views', 'database', 'storage', 'logs', 'tests', 'tools', 'deploy'] as $dir) {
    $checks["L7 .htaccess denies /$dir"] = (bool)preg_match('/RewriteRule \^\(\?:[^)]*\b'.$dir.'\b[^)]*\)/', $htaccess);
}
$checks['L7 nginx example denies the same directories'] =
    str_contains($nginx, 'config|src|views|database|deploy|tests|tools|logs|storage');
$checks['L7 nginx example prefers document root = public/'] = str_contains($nginx, 'root /path/to/Cloud-File-Hub-PHP/public;');
$checks['L7 README explains why public/ is preferred'] = str_contains($readme, 'outside the web root');

// L8 -- the archive must not silently lose files or directories.
$checks['L8 ZipArchive::open() is checked'] =
    str_contains($index, 'if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true)');
$checks['L8 colliding basenames are made unique'] = str_contains($index, '$uniqueRoot = function');
$checks['L8 empty directories are preserved'] = str_contains($index, '$zip->addEmptyDir(');
$checks['L8 close() is checked'] = str_contains($index, 'if (!$zip->close())');
$checks['L8 a failed archive is cleaned up'] =
    str_contains($index, '@$zip->close();') && str_contains($index, '@unlink($tmp);');

// L9 -- dead frontend code removed.
$checks['L9 initCustomPlayers is gone'] = !str_contains($app, 'initCustomPlayers');
$checks['L9 unused cfh_auth state is gone'] = !str_contains($app, 'cfh_auth');

// L10 -- no duplicated headings.
preg_match_all('/^## (.+)$/m', $readme, $m);
$duplicates = array_filter(array_count_values($m[1]), static fn($n) => $n > 1);
$checks['L10 README has no duplicate sections'] = $duplicates === [];

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
