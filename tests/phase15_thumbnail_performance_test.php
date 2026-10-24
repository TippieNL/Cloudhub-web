<?php
declare(strict_types=1);

/**
 * Thumbnail loading performance.
 *
 * Three things made a gallery slow, and each is pinned here:
 *
 *  1. PHP's files session handler holds an exclusive lock for the whole
 *     request, so forty concurrent thumbnail requests were served one at a
 *     time. Measured: eight concurrent thumbnails took 533ms with the lock
 *     held, 206ms without.
 *  2. The server had no way to keep a video frame, so every visitor re-fetched
 *     and re-decoded every video on every load.
 *  3. renderFiles() discarded decoded frames, so each search keystroke, sort
 *     change or view toggle decoded every visible video again. Measured: four
 *     re-render events cost 16 extra decodes before, 0 after.
 */
$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
// The thumbnails themselves moved into a service of their own; see phase 56.
$service = (string)file_get_contents($root.'/src/Services/ThumbnailService.php');

$checks = [];

// --- 1. session lock ------------------------------------------------------
$checks['a helper exists to release the session lock'] =
    str_contains($index, 'function release_session_lock(): void') && str_contains($index, 'session_write_close()');
foreach (['thumbnail', 'stream', 'preview', 'download', 'list'] as $route) {
    $checks["the $route route releases the lock"] = (function () use ($index, $route): bool {
        $needle = match ($route) {
            'thumbnail' => "\$path === '/api/thumbnail' && \$method === 'GET'",
            'stream' => "\$path === '/api/files/stream')",
            'preview' => "\$path === '/api/files/preview')",
            // HEAD joined GET when the web client began asking before it
            // hands a download to the browser.
            'download' => "\$path === '/api/files/download' && (\$method === 'GET' || \$method === 'HEAD')",
            'list' => "\$path === '/api/files/list' && \$method === 'GET'",
        };
        $at = strpos($index, $needle);
        if ($at === false) return false;
        // The release must come before anything else the handler does.
        $body = substr($index, $at, 400);
        return str_contains($body, 'release_session_lock();');
    })();
}
$checks['mutating routes keep their session lock'] =
    !preg_match('/api\/files\/(?:delete|rename|mkdir)[^;]{0,200}release_session_lock/s', $index);

// --- 2. cached video thumbnails ------------------------------------------
// One place decides where every thumbnail lives -- photos', and the frames
// clients contribute for videos -- so the two can never disagree.
$checks['thumbnails share one cache-path helper'] =
    str_contains($index, 'function thumbs(): ThumbnailService {')
    && str_contains($service, 'public function pathFor(string $relative): ?string')
    && !str_contains($index, 'function thumbnail_cache_path(');
// A listing row already carries the file's time, so flagging a video costs no
// second stat -- on FUSE storage that is what a cached listing saves.
$checks['listing rows flag their frame from the time they carry'] =
    str_contains($index, "\$modified = strtotime((string)(\$entry['modified'] ?? ''));")
    && str_contains($index, "thumbs()->hasFrame(\$root.\$entry['path'], \$modified === false ? null : \$modified)")
    && str_contains($service, 'public function hasFrame(string $file, ?int $mtime = null): bool')
    && str_contains($service, '$mtime ??= @filemtime($file);');
$checks['a cached entry is served whatever produced it'] =
    str_contains($index, "\$cache = \$thumbs->current(\$f, true);\n        if (\$cache !== null)send_thumbnail(\$cache);");
$checks['browsers can contribute a video frame'] = str_contains($index, "\$path === '/api/thumbnail/video' && \$method === 'POST'");
$checks['contributed frames are validated as images'] =
    str_contains($index, 'getimagesizefromstring($raw)') && str_contains($index, 'IMAGETYPE_WEBP');
$checks['contributed frames are size-bounded'] =
    str_contains($index, "strlen(\$raw) > 262144") && str_contains($index, '$info[0] > 1280');
$checks['contributed frames are normalised to WebP'] =
    str_contains($index, 'if ($info[2] !== IMAGETYPE_WEBP)') && str_contains($index, 'imagewebp($decoded, null, 75)');
$checks['contributing needs no write permission'] =
    str_contains($index, "'/api/thumbnail/video'") && str_contains($index, '$writeExemptPost');
$checks['the client posts frames back'] = str_contains($app, "api('/api/thumbnail/video'");
$checks['the client tries the server cache first'] = str_contains($app, 'function loadCachedVideoThumb(');

// A video with no cached frame yet is an ordinary state, not an error. It used
// to answer 415, so every uncached video logged a failed request and a console
// error on first load. The listing now says which videos have one.
$checks['the listing reports which videos have a cached frame'] =
    str_contains($index, "\$entry['hasThumbnail'] = thumbs()->hasFrame(");
$checks['the client only asks when a frame exists'] =
    str_contains($app, "button.dataset.hasThumb === '1'") && str_contains($app, 'data-has-thumb=');
$checks['an uncached video reports 404, not 415'] =
    str_contains($index, "'No thumbnail has been generated for this video yet', 404")
    && !str_contains($index, 'use the media stream endpoint');
// const is not hoisted: a route that runs and exits earlier in the file would
// never reach a definition placed further down. The extension lists are class
// constants now, autoloaded wherever they are first used, so only the
// helpers' position is left to hold.
$checks['thumbnail helpers are defined before any route uses them'] = (function () use ($index): bool {
    $helper = strpos($index, 'function thumbs(): ThumbnailService');
    $send = strpos($index, 'function send_thumbnail(string $cache): never');
    $firstRoute = strpos($index, "if (\$path === '/api/auth/login'");
    return $helper !== false && $send !== false && $firstRoute !== false
        && $helper < $firstRoute && $send < $firstRoute
        && !str_contains($index, 'const THUMBNAIL_VIDEO_EXTENSIONS');
})();

// --- 3. caching and re-render --------------------------------------------
$checks['thumbnails are cached for a year, immutable'] =
    str_contains($index, 'Cache-Control: private,max-age=31536000,immutable');
$checks['thumbnails answer conditional requests'] =
    str_contains($index, 'HTTP_IF_NONE_MATCH') && str_contains($index, 'http_response_code(304)');
$checks['thumbnails are written atomically'] =
    str_contains($service, "\$tmp = self::temporary(\$thumb);")
    && str_contains($service, "return \$path.'.'.bin2hex(random_bytes(4)).'.tmp';")
    && str_contains($service, "!@rename(\$tmp, \$thumb)");
$checks['decoded frames survive a re-render'] =
    str_contains($app, 'const videoThumbCache = new Map()') && !str_contains($app, 'cleanupVideoThumbnailUrls');
$checks['only frames for vanished files are released'] = str_contains($app, 'releaseStaleVideoThumbs(new Set(');
$checks['capture fetches metadata, not the whole video'] =
    str_contains($app, "video.preload = 'metadata'") && !str_contains($app, "video.preload = 'auto'");
$checks['images keep native lazy loading'] = str_contains($app, 'loading="lazy"');
$checks['images declare intrinsic size'] = str_contains($app, 'width="300" height="300"');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
