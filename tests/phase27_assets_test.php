<?php
declare(strict_types=1);

/**
 * Thumbnail transparency, asset delivery and the player's module graph.
 */
$root = dirname(__DIR__);
$checks = [];

// --- thumbnails keep their alpha ------------------------------------------

$index = (string)file_get_contents($root.'/public/index.php');
$checks['the thumbnail canvas is prepared for alpha'] =
    str_contains($index, 'imagealphablending($im, false);')
    && str_contains($index, 'imagesavealpha($im, true);')
    && str_contains($index, 'imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));');

// Proved against real pixels rather than the API calls: a truecolor canvas
// starts opaque black, so without this a transparent PNG came out with black
// behind whatever should have shown through.
if (extension_loaded('gd')) {
    $tmp = sys_get_temp_dir().'/cloudhub-p27-'.bin2hex(random_bytes(5));
    mkdir($tmp, 0775, true);

    $src = imagecreatetruecolor(20, 20);
    imagesavealpha($src, true);
    imagealphablending($src, false);
    imagefill($src, 0, 0, imagecolorallocatealpha($src, 0, 0, 0, 127));
    imagefilledrectangle($src, 5, 5, 14, 14, imagecolorallocatealpha($src, 255, 0, 0, 0));
    imagepng($src, $tmp.'/in.png');
    imagedestroy($src);

    $create = imagecreatefrompng($tmp.'/in.png');
    $im = imagecreatetruecolor(10, 10);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagecopyresampled($im, $create, 0, 0, 0, 0, 10, 10, 20, 20);

    $corner = (imagecolorat($im, 0, 0) >> 24) & 0x7F;
    $centre = (imagecolorat($im, 5, 5) >> 24) & 0x7F;
    $checks['a transparent corner stays transparent'] = $corner === 127;
    $checks['and the opaque subject stays opaque'] = $centre === 0;

    // The same canvas survives a WebP encode, which is what actually ships.
    if (function_exists('imagewebp')) {
        imagewebp($im, $tmp.'/out.webp', 75);
        $back = @imagecreatefromwebp($tmp.'/out.webp');
        if ($back !== false) {
            $checks['the encoded thumbnail still carries alpha'] = ((imagecolorat($back, 0, 0) >> 24) & 0x7F) > 0;
            imagedestroy($back);
        }
    }
    imagedestroy($im);
    imagedestroy($create);
    foreach (glob($tmp.'/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
} else {
    echo "[SKIP] alpha round-trip needs the gd extension\n";
}

// --- asset delivery -------------------------------------------------------

$htaccess = (string)file_get_contents($root.'/.htaccess');
$checks['text assets are compressed'] =
    str_contains($htaccess, 'AddOutputFilterByType DEFLATE') && str_contains($htaccess, 'application/javascript');
$checks['static assets carry an expiry'] = str_contains($htaccess, 'ExpiresByType application/javascript');
// KSWEB's Apache is not guaranteed to build either module, and an unguarded
// directive for a missing module takes the whole site down with a 500.
foreach (['mod_deflate.c', 'mod_expires.c', 'mod_headers.c'] as $mod) {
    $checks["the $mod block is guarded"] = str_contains($htaccess, '<IfModule '.$mod.'>');
}
$checks['versionless assets must revalidate'] = str_contains($htaccess, 'Header append Cache-Control "must-revalidate"');
// The deny rules that keep application directories off the web must survive.
foreach (['config', 'src', 'storage', 'tools'] as $dir) {
    $checks["/$dir is still denied"] = (bool)preg_match('/RewriteRule \^\(\?:[^)]*\b'.$dir.'\b[^)]*\)/', $htaccess);
}

// --- the player module graph ----------------------------------------------

$playDir = $root.'/public/assets/js/player';
$play = (string)file_get_contents($root.'/views/pages/play.php');

/** Every module reachable from an entry point, by following real imports. */
function graph_from(string $dir, string $entry): array {
    $seen = [];
    $stack = [$entry];
    while ($stack) {
        $file = array_pop($stack);
        if (isset($seen[$file]) || !is_file($dir.'/'.$file)) continue;
        $seen[$file] = true;
        preg_match_all("~from '\./([A-Za-z0-9_]+\.js)'~", (string)file_get_contents($dir.'/'.$file), $m);
        foreach ($m[1] as $dep) $stack[] = $dep;
    }
    return array_keys($seen);
}

$graph = graph_from($playDir, 'PlayerUI.js');
$deps = array_values(array_filter($graph, static fn(string $f): bool => $f !== 'PlayerUI.js'));
$checks['the module graph was resolved'] = count($deps) >= 12;

// Every dependency is preloaded, so the browser is not discovering them one
// level at a time. The entry point itself is loaded by the script tag.
$missing = [];
foreach ($deps as $dep) {
    if (!str_contains($play, 'modulepreload" href="<?= $assets ?>/assets/js/player/'.$dep.'"')) $missing[] = $dep;
}
$checks['every dependency is preloaded'] = $missing === [];
if ($missing !== []) echo '       not preloaded: '.implode(', ', $missing).PHP_EOL;

// And nothing is preloaded that is not actually imported.
preg_match_all('~modulepreload" href="<\?= \$assets \?>/assets/js/player/([A-Za-z0-9_]+\.js)"~', $play, $pm);
$stalePreloads = array_diff($pm[1], $deps);
$checks['nothing is preloaded that is not imported'] = $stalePreloads === [];
if ($stalePreloads !== []) echo '       stale preload: '.implode(', ', $stalePreloads).PHP_EOL;

$checks['the entry point is still a module script'] =
    str_contains($play, '<script type="module" nonce="<?= $nonce ?>" src="<?= $assets ?>/assets/js/player/PlayerUI.js">');
// modulepreload is fetched under script-src, so the policy has to allow it.
$checks['the page CSP permits same-origin scripts'] =
    str_contains((string)file_get_contents($root.'/src/Services/Security.php'), "script-src 'self' 'nonce-");

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
