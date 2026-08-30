<?php
declare(strict_types = 1);
require dirname(__DIR__).'/config/bootstrap.php';
use CloudHub\Helpers\Http;
use CloudHub\Services\FileService;
use CloudHub\Repositories\ServerRepository;
use CloudHub\Repositories\UserRepository;
use CloudHub\Repositories\StorageLedger;
use CloudHub\Services\Auth;
use CloudHub\Services\UploadService;
use CloudHub\Services\Security;
use CloudHub\Services\LoginRateLimiter;
use CloudHub\Services\Authorization;
use CloudHub\Services\AuditLog;

$fs = new FileService($config); $basePath = Http::basePath(); $assetBase = Http::assetBase(); $path = Http::requestPath($basePath); $method = $_SERVER['REQUEST_METHOD']??'GET';
$frontController = ($basePath === '' ? '/' : $basePath.'/');

/**
 * Public share links are viewed by people who have no account here, and are
 * routinely fetched by link-preview bots. Starting a session for them would
 * hand every anonymous viewer a cookie and leave a session file behind on the
 * server for each visit, so these routes run session-less. They authenticate
 * on the token alone and never read $_SESSION.
 */
$isPublicShare = (bool)preg_match('#^/share/[A-Za-z0-9_-]{20,128}(?:/(?:raw|download))?$#', $path);
if (!$isPublicShare) Auth::startSession($config);
Security::applyHeaders($config);
Security::assertProductionConfig($config);
header('X-Request-ID: '.Http::requestId());
function mime_type(string $f): string {
    return function_exists('mime_content_type')?(mime_content_type($f)?:'application/octet-stream'):'application/octet-stream';
}

/**
 * Resolve a browser media MIME type reliably on Android/KSWEB.
 *
 * mime_content_type() is not guaranteed to recognise every extension on
 * Android builds, so media streaming and share links must not depend on
 * libmagic alone -- a misdetected image would be offered as a download
 * instead of being shown.
 */
function media_mime_type(string $f): string {
    $extensionMime = match (strtolower((string)pathinfo($f, PATHINFO_EXTENSION))) {
        'mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv', 'ogg' => 'video/ogg',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'mpeg', 'mpg' => 'video/mpeg',
        '3gp' => 'video/3gpp',
        '3g2' => 'video/3gpp2',
        'ts', 'm2ts', 'mts' => 'video/mp2t',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        default => '',
    };

    if ($extensionMime !== '') return $extensionMime;

    $mime = mime_type($f);
    return $mime !== '' ? $mime : 'application/octet-stream';
}

/**
 * Stream a file to the browser, honouring a single HTTP byte range.
 *
 * Media players request ranges to read metadata, start playback quickly and
 * seek without pulling the whole file, so this is what makes video seeking
 * work. Only one range is served per request; malformed, multiple or
 * unsatisfiable ranges get 416. Bytes are copied in bounded buffers, so memory
 * stays flat no matter how large the file is.
 *
 * $disposition is the full Content-Disposition type ("inline" or "attachment");
 * $extraHeaders are emitted verbatim, which is how callers layer on their own
 * cache and content-security policy.
 */
function serve_file_range(string $file, string $mime, string $disposition, string $method, array $extraHeaders = []): never {
    $size = filesize($file);
    if ($size === false)throw new RuntimeException('Unable to determine file size', 500);

    $unsatisfiable = static function() use ($size): never {
        http_response_code(416);
        header('Content-Range: bytes */'.$size);
        header('Accept-Ranges: bytes');
        exit;
    };

    $start = 0;
    $end = max(0, $size-1);
    $status = 200;

    $range = trim((string)($_SERVER['HTTP_RANGE']??''));
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m))$unsatisfiable();
        $first = $m[1];
        $last = $m[2];
        if ($first === '' && $last === '')$unsatisfiable();

        if ($first === '') {
            // Suffix range: bytes=-500 requests the final 500 bytes.
            $suffix = (int)$last;
            if ($suffix <= 0)$unsatisfiable();
            $start = $size-min($suffix, $size);
            $end = $size-1;
        } else {
            $start = (int)$first;
            $end = $last === ''?$size-1:min((int)$last, $size-1);
            if ($start >= $size || $start > $end)$unsatisfiable();
        }
        $status = 206;
    }

    $length = $size === 0?0:($end-$start+1);
    http_response_code($status);
    header('Content-Type: '.$mime);
    header('Content-Disposition: '.$disposition.'; filename="'.str_replace(['"', "\r", "\n"], '_', basename($file)).'"');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.$length);
    header('X-Content-Type-Options: nosniff');
    foreach ($extraHeaders as $header)header($header);
    if ($status === 206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);

    if ($method === 'HEAD' || $length === 0)exit;

    @set_time_limit(0);
    while (ob_get_level() > 0)@ob_end_clean();

    $handle = @fopen($file, 'rb');
    if ($handle === false)throw new RuntimeException('Unable to open file for streaming', 500);
    if ($start > 0 && fseek($handle, $start) !== 0) {
        fclose($handle);
        throw new RuntimeException('Unable to seek file for streaming', 500);
    }

    $remaining = $length;
    $bufferSize = 1024*1024; // 1 MiB server-side streaming buffer.
    while ($remaining > 0&&!feof($handle)) {
        if (connection_aborted())break;
        $data = fread($handle, (int)min($bufferSize, $remaining));
        if ($data === false || $data === '')break;
        echo $data;
        $remaining -= strlen($data);
        flush();
    }
    fclose($handle);
    exit;
}
/** Minimum password length, matching tools/create-admin.php. */
const USER_PASSWORD_MIN_LENGTH = 12;

/**
 * Release the session lock once authorization has been decided.
 *
 * PHP's files session handler holds an exclusive lock on the session file for
 * the whole request, so a page that asks for forty thumbnails at once has those
 * requests served strictly one after another no matter how many workers are
 * free. Measured on a cold cache: eight concurrent thumbnails took 533ms with
 * the lock held and 201ms without it.
 *
 * Only safe once nothing further writes to $_SESSION. $_SESSION stays readable
 * afterwards; later writes simply are not persisted, which is why this is
 * called from read-only routes only.
 */
function release_session_lock(): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

/**
 * Where a file's cached thumbnail lives.
 *
 * Keyed by absolute path and modification time, so editing or replacing a
 * file yields a new key and the stale thumbnail is simply never read again.
 */
function thumbnail_cache_path(string $file): ?string {
    $mtime = @filemtime($file);
    if ($mtime === false)return null;
    $dir = dirname(__DIR__).'/storage/.thumbnails/images';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))return null;
    return $dir.'/'.md5($file.':'.$mtime).'.webp';
}

const THUMBNAIL_VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v', 'avi', 'mkv', 'mpeg', 'mpg', '3gp', '3g2', 'ts', 'm2ts', 'mts'];
const THUMBNAIL_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

/**
 * Send a cached thumbnail, answering conditional requests with 304.
 *
 * The URL carries the file's modification time, so a cached entry is
 * immutable for its URL and can be held for a year. The ETag still matters
 * for a reload, where the browser revalidates regardless.
 */
function send_thumbnail(string $cache): never {
    $etag = '"'.substr(basename($cache, '.webp'), 0, 32).'"';
    header('Content-Type: image/webp');
    header('Cache-Control: private,max-age=31536000,immutable');
    header('X-Content-Type-Options: nosniff');
    header('ETag: '.$etag);

    $seen = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($seen !== '' && (str_contains($seen, $etag) || $seen === '*')) {
        http_response_code(304);
        exit;
    }

    header('Content-Length: '.filesize($cache));
    readfile($cache);
    exit;
}

function api_try(callable $fn): never {
    try {
        $r = $fn(); if ($r !== null)Http::json($r);
    }catch(RuntimeException $e) {
        $status = (int)$e->getCode(); if ($status < 400 || $status > 599)$status = 500; $codes = [400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            410 => 'GONE',
            413 => 'REQUEST_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            419 => 'CSRF_FAILED',
            422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED',
            507 => 'INSUFFICIENT_STORAGE'];
        /*
         * 5xx messages are hidden because they can carry internal detail, but a
         * status that appears in this map was chosen deliberately and its
         * message is written for the user. 507 ("you are over quota") is
         * useless as "an internal server error occurred" -- the caller cannot
         * act on what they are not told.
         */
        $known = isset($codes[$status]);
        $msg = ($status >= 500 && !$known)?'An internal server error occurred':$e->getMessage();
        if ($status >= 500)error_log('['.Http::requestId().'] '.$e->getMessage());
        Http::error($status, $codes[$status]??'INTERNAL_ERROR', $msg);
    }catch(Throwable $e) {
        error_log('['.Http::requestId().'] '.get_class($e).': '.$e->getMessage()); Http::error(500, 'INTERNAL_ERROR', 'An internal server error occurred');
    }exit;
}
/**
 * Upload staging, built on first use.
 *
 * The constructor probes the staging directory with a real create/write/delete,
 * so building it eagerly made every request -- including anonymous share views
 * -- perform filesystem writes.
 */
function uploads(): UploadService {
    static $uploads; global $config, $fs;
    return $uploads ??= new UploadService($config, $fs);
}
/**
* The upload ledger, sharing the request's database connection.
*/
function ledger(): StorageLedger {
    static $ledger = null;
    return $ledger ??= new StorageLedger(db());
}
/**
* Measured storage use, cached.
*
* Measuring means walking the whole store, which is far too expensive to do on
* every upload. The result is written to a small cache file and reused for
* usage_cache_seconds; $force recomputes it for the "Recalculate" button.
*
* The cache lives outside the storage root so it is neither listed, searched,
* nor counted in the figure it holds.
*/
function storage_report(FileService $fs, array $config, bool $force = false): array {
    $cache = dirname(__DIR__).'/storage/.cache/usage.json';
    $ttl = max(0, (int)$config['usage_cache_seconds']);

    if (!$force && $ttl > 0 && is_file($cache) && time() - (int)filemtime($cache) < $ttl) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached) && isset($cached['bytes'])) {
            $cached['cached'] = true;
            return $cached;
        }
    }

    $report = $fs->storageReport();
    $report['cached'] = false;
    if (!is_dir(dirname($cache)))@mkdir(dirname($cache), 0775, true);
    @file_put_contents($cache, json_encode($report, JSON_UNESCAPED_SLASHES));
    return $report;
}
/**
* Refuse an upload that would breach the whole-store limit or the caller's own
* quota, before a single byte is staged.
*
* Both limits are opt-in (0 means unlimited) and both fail open: if the figure
* cannot be obtained the upload proceeds, because blocking a legitimate upload
* over a bookkeeping problem is worse than letting one through.
*/
function human_bytes(int $n): string {
    // Limits are configured in GB but can be set low; "0 GB of 0 GB used" is
    // not an answer, so the unit follows the number.
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)$n;
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return ($v >= 100 || $i === 0?round($v):round($v, 1)).' '.$units[$i];
}
function assert_upload_fits(FileService $fs, array $config, int $size): void {
    $limit = (int)round((float)$config['storage_limit_gb'] * 1073741824);
    if ($limit > 0) {
        $report = storage_report($fs, $config);
        $used = (int)($report['bytes'] ?? 0);
        if ($used + $size > $limit) {
            throw new RuntimeException('The file store is full ('.
                human_bytes($used).' of '.human_bytes($limit).' used)', 507);
        }
    }

    $quota = (int)round((float)$config['user_quota_gb'] * 1073741824);
    $user = Auth::user();
    if ($quota > 0 && $user !== null) {
        ledger()->sweep($fs);
        $used = ledger()->usage($user['id']);
        if ($used + $size > $quota) {
            throw new RuntimeException('You have used '.human_bytes($used).
                ' of your '.human_bytes($quota).' quota', 507);
        }
    }
}
function db(): PDO {
    static $pdo; if (!$pdo) {
        $c = require dirname(__DIR__).'/config/database.php'; $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }return $pdo;
}
/**
 * Absolute origin (scheme://host) for links handed to other people.
 *
 * X-Forwarded-Proto is only believed when TRUST_PROXY is set, matching
 * Security::isHttps(); otherwise anyone could flip a generated share link to
 * http:// by sending a header.
 */
function public_origin(array $config): string {
    $scheme = Security::isHttps($config)?'https':'http';
    $host = (string)($_SERVER['HTTP_HOST']??'');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(?::\d+)?$/', $host)) {
        $appUrl = trim((string)($config['app_url']??''));
        if ($appUrl !== '')return rtrim($appUrl, '/');
        $host = 'localhost';
    }
    return $scheme.'://'.$host;
}

/** Public URL of a share token, e.g. https://host/base/share/TOKEN. */
function share_url(array $config, string $basePath, string $token): string {
    return public_origin($config).$basePath.'/share/'.$token;
}

/**
 * Resolve a share token to an on-disk file for an anonymous visitor.
 *
 * Returns [row, absolute path]. Expiry is enforced here so every share route --
 * viewer page, raw bytes and download alike -- goes through the same check.
 */
function share_resolve(FileService $fs, string $token): array {
    $stmt = db()->prepare('SELECT token,file_path,created_at,expires_at FROM share_links WHERE token=?');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row)throw new RuntimeException('Share link not found or expired', 404);
    if ($row['expires_at'] && strtotime((string)$row['expires_at']) < time())throw new RuntimeException('Share link has expired', 410);
    $file = $fs->sanitize((string)$row['file_path']);
    if (!is_file($file))throw new RuntimeException('The shared file no longer exists', 404);
    return [$row, $file];
}

/**
 * How a shared file should be presented to a logged-out visitor.
 *
 * Only these kinds render inline. Anything else -- documents, archives, and in
 * particular script-capable text such as HTML or SVG -- is downloaded instead,
 * so a share link can never execute markup on this origin.
 */
function share_media_kind(string $file): string {
    $mime = media_mime_type($file);
    if ($mime === 'image/svg+xml')return 'other';
    if (str_starts_with($mime, 'image/'))return 'image';
    if (str_starts_with($mime, 'video/'))return 'video';
    if (str_starts_with($mime, 'audio/'))return 'audio';
    return 'other';
}

function mask_server(array $s): array {
    foreach (['password', 'privateKey', 'apiKey'] as $k)if (!empty($s['config'][$k]))$s['config'][$k] = '••••••••'; return $s;
}

if ($path === '/api/auth/login' && $method === 'POST') api_try(function()use($config) {
    $b = Http::body(16384); $u = Http::string($b, 'username', 1, 100); $p = Http::string($b, 'password', 1, 4096);
    $limiter = new LoginRateLimiter(db(), $config); $limiter->assertAllowed($u);
    if (!Auth::login(db(), $u, $p)) {
        $limiter->recordFailure($u); AuditLog::write(db(), 'auth.login', 'failure', ['username' => $u]); throw new RuntimeException('Invalid username or password', 401);
    }
    $limiter->clearUserFailures($u);
    AuditLog::write(db(), 'auth.login', 'success');
    return ['success' => true,
        'user' => Auth::user(),
        'csrfToken' => $_SESSION['csrf']];
});
if ($path === '/api/auth/status' && $method === 'GET') Http::json(['authenticated' => Auth::user() !== null, 'user' => Auth::user(), 'csrfToken' => $_SESSION['csrf']]);
if ($path === '/api/auth/logout' && $method === 'POST') {
    Authorization::requireRead(); Auth::verifyCsrf(); AuditLog::write(db(), 'auth.logout'); Auth::logout(); Http::json(['success' => true]);
}
$isAuthEndpoint = str_starts_with($path, '/api/auth/');
$isProtectedApi = (str_starts_with($path, '/api/')&&!$isAuthEndpoint) || str_starts_with($path, '/webdav');
if ($isProtectedApi && $method !== 'OPTIONS') {
    Authorization::requireRead();
    /*
     * These POST routes do not write to the file store, so the write
     * capability does not apply to them. They still verify CSRF, and each
     * carries its own authorization:
     *
     *   download-zip     reads only; it POSTs because it takes a JSON body,
     *                    and demanding write locked viewers out of bulk download
     *   thumbnail/video  contributes a derived frame for a file the caller can
     *                    already watch
     *   users/me/password  changes the caller's own password, proven by
     *                    supplying the current one -- a viewer must be able to
     *                    rotate their own credentials
     */
    $writeExemptPost = ['/api/files/download-zip', '/api/thumbnail/video', '/api/users/me/password'];
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        Auth::verifyCsrf();
        if (str_starts_with($path, '/api/servers'))Authorization::requireAdmin();
        elseif (!in_array($path, $writeExemptPost, true))Authorization::requireWrite();
    }
}
if ($path === '/api/files/config') Http::json([
    'readOnly' => $config['read_only'], 'allowDelete' => $config['allow_delete'], 'allowOverwrite' => $config['allow_overwrite'],
    'maxUploadMb' => $config['max_upload_mb'], 'maxUploadFiles' => $config['max_upload_files'],
    'chunkMb' => $config['upload_chunk_mb'], 'retryCount' => $config['upload_retry_count'],
    'conflict' => $config['upload_conflict']
]);
if ($path === '/api/files/list' && $method === 'GET') api_try(function()use($fs) {
    release_session_lock();
    $entries = $fs->list((string)($_GET['path']??'/'));

    /*
     * Flag videos that already have a cached frame.
     *
     * Without this the browser had to ask for every video thumbnail and treat
     * the failure as "not cached yet", which logged a failed request and a
     * console error for each one, and wasted a round trip. One is_file() per
     * video here replaces all of that.
     */
    $root = $fs->root();
    foreach ($entries as &$entry) {
        if (!empty($entry['isDirectory']))continue;
        $ext = strtolower((string)pathinfo((string)$entry['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, THUMBNAIL_VIDEO_EXTENSIONS, true))continue;
        $cache = thumbnail_cache_path($root.$entry['path']);
        $entry['hasThumbnail'] = $cache !== null && is_file($cache);
    }
    unset($entry);

    return $entries;
});
if ($path === '/api/files/download' && $method === 'GET') api_try(function()use($fs) {
    release_session_lock();
    $f = $fs->existing((string)($_GET['path']??'')); if (!is_file($f))throw new RuntimeException('File not found', 404); header('Content-Type: '.mime_type($f)); header('Content-Disposition: attachment; filename="'.str_replace(['"', "\r", "\n"], '_', basename($f)).'"'); header('Content-Length: '.filesize($f)); readfile($f); exit;
});
/**
* Streams a file for the authenticated preview dialog.
*
* The browser receives an inline disposition for previewable media. The route
* still uses FileService::sanitize() and the normal authenticated API guard, so
* arbitrary filesystem paths cannot be requested by the client.
*/

/**
* Streams authenticated media for the dedicated cfh-player route.
*
* This keeps video playback on the custom player while allowing the rest of
* the UI to use the generic preview endpoint for non-video assets.
*/
if (($path === '/api/files/stream') && ($method === 'GET' || $method === 'HEAD')) api_try(function()use($fs, $method) {
    release_session_lock();
    $f = $fs->existing((string)($_GET['path']??''));
    if (!is_file($f))throw new RuntimeException('File not found', 404);

    $mime = media_mime_type($f);
    if (!str_starts_with($mime, 'video/') && !str_starts_with($mime, 'audio/')) {
        throw new RuntimeException('This file type does not support media streaming', 415);
    }

    serve_file_range($f, $mime, 'inline', $method, ['Cache-Control: private,max-age=300']);
});
if (($path === '/api/files/preview') && ($method === 'GET' || $method === 'HEAD')) api_try(function()use($fs, $method) {
    release_session_lock();
    $f = $fs->existing((string)($_GET['path']??''));
    if (!is_file($f))throw new RuntimeException('File not found', 404);

    $mime = mime_type($f);
    $inline = str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || $mime === 'application/pdf' || $mime === 'text/plain';
    if (!$inline)throw new RuntimeException('This file type does not support inline preview', 415);

    serve_file_range($f, $mime, 'inline', $method, ['Cache-Control: private,max-age=300']);
});
if ($path === '/api/files/mkdir' && $method === 'POST') api_try(function()use($fs) {
    global $config; $fs->writable(); $b = Http::body(); $p = $fs->destination((string)($b['path']??'')); if (file_exists($p))throw new RuntimeException('Directory already exists', 409); if (!mkdir($p, 0775, true)&&!is_dir($p))throw new RuntimeException('Unable to create directory', 500); return ['success' => true,
        'message' => 'Directory created'];
});
if ($path === '/api/files/delete' && $method === 'DELETE') api_try(function()use($fs, $config) {
    $fs->writable();
    if (!$config['allow_delete'])throw new RuntimeException('Delete is not allowed', 403);
    $b = Http::body();
    $p = $fs->existing((string)($b['path']??''));
    if (!file_exists($p))throw new RuntimeException('File or directory not found', 404);

    // Permanent deletion is one keystroke away and irreversible, so unless the
    // deployment has opted out the item goes to the trash instead. The reply
    // says which happened, so the UI never claims the wrong thing.
    if (!$config['trash_enabled']) {
        $rel = $fs->relative($p);
        $fs->deleteTree($p);
        ledger()->forget($rel);
        AuditLog::write(db(), 'file.delete', 'success', ['path' => $rel]);
        return ['success' => true, 'trashed' => false, 'message' => 'Deleted permanently'];
    }
    $meta = $fs->trash($p, Auth::user()['username'] ?? null);
    ledger()->forget($meta['originalPath']);
    $fs->trashPurgeExpired((int)$config['trash_retention_days']);
    AuditLog::write(db(), 'file.trash', 'success', ['path' => $meta['originalPath'], 'id' => $meta['id']]);
    return ['success' => true, 'trashed' => true, 'id' => $meta['id'], 'message' => 'Moved to trash'];
});
/**
* Move and copy.
*
* Both take a list of source paths and one destination folder, because these
* are selection operations: the interesting case is twenty files at once. A
* failure on one item does not abandon the rest -- every source is attempted
* and the failures come back named, so the caller can report exactly what did
* not make it rather than a single misleading "failed".
*/
$relocate = function(callable $apply, string $verb)use($fs, $config): array {
    $fs->writable();
    $b = Http::body(65536);
    $paths = array_values(array_filter((array)($b['paths']??[]), 'is_string'));
    if (!$paths)throw new RuntimeException('No items were given to '.$verb, 400);
    if (count($paths) > 500)throw new RuntimeException('Too many items in one request', 413);

    $destination = $fs->existing((string)($b['destination']??'/'));
    if (!is_dir($destination))throw new RuntimeException('The destination is not a folder', 400);

    $done = 0; $failed = [];
    foreach ($paths as $rel) {
        try {
            $source = $fs->existing($rel);
            $target = rtrim($destination, '/').'/'.basename($source);

            // Copying into the same folder is a legitimate way to duplicate
            // something; moving into it is a no-op worth reporting.
            if ($verb === 'move' && dirname($source) === rtrim($destination, '/'))throw new RuntimeException('It is already in that folder', 409);
            // Moving or copying a folder into itself would either lose the
            // folder or recurse forever, depending on the operation.
            if (is_dir($source) && str_starts_with($destination.'/', $source.'/'))throw new RuntimeException('A folder cannot be moved into itself', 409);
            if (file_exists($target)) {
                if (!$config['allow_overwrite'])throw new RuntimeException('An item with that name is already there', 409);
                $target = $fs->freeName($target);
            }

            $apply($source, $target);
            // A move carries its attribution with it. A copy creates new bytes,
            // so it is charged to whoever made it -- otherwise a quota is
            // avoided by uploading one file and copying it a hundred times.
            if ($verb === 'move') {
                ledger()->relocate($fs->relative($source), $fs->relative($target));
            } else {
                foreach ($fs->copiedFiles($target) as $copied) {
                    ledger()->record($fs->relative($copied), basename($copied),
                        (int)(filesize($copied)?:0), null, Auth::user()['id'] ?? null);
                }
            }
            $done++;
        }catch(RuntimeException $e) {
            $failed[] = ['path' => $rel, 'message' => $e->getMessage()];
        }
    }
    AuditLog::write(db(), 'file.'.$verb, $failed?'partial':'success',
        ['destination' => $fs->relative($destination), 'completed' => $done, 'failed' => count($failed)]);
    return ['success' => $failed === [], 'completed' => $done, 'failed' => $failed];
};
if ($path === '/api/files/move' && $method === 'POST') api_try(function()use($relocate) {
    return $relocate(function(string $src, string $dst) {
        if (!rename($src, $dst))throw new RuntimeException('The move failed', 500);
    }, 'move');
});
if ($path === '/api/files/copy' && $method === 'POST') api_try(function()use($relocate, $fs) {
    return $relocate(fn(string $src, string $dst) => $fs->copyTree($src, $dst), 'copy');
});
/**
* Recursive search.
*
* The client-side filter only ever saw the folder already on screen, so a file
* one folder over looked like it did not exist. This walks the tree under a
* starting folder, under both a result cap and a node-visit cap so a deep tree
* cannot turn one keystroke into an unbounded walk.
*/
if ($path === '/api/files/search' && $method === 'GET') api_try(function()use($fs) {
    release_session_lock();
    $q = trim((string)($_GET['q']??''));
    if (mb_strlen($q) < 2)throw new RuntimeException('Enter at least two characters to search', 400);
    if (mb_strlen($q) > 255)throw new RuntimeException('That search term is too long', 400);
    $limit = max(1, min(500, (int)($_GET['limit']??200)));

    $found = $fs->search((string)($_GET['path']??'/'), $q, $limit);
    return ['query' => $q, 'results' => $found['results'], 'truncated' => $found['truncated'], 'scanned' => $found['scanned']];
});
/**
* Trash.
*
* Listing is a read: anyone who could see a file before it was deleted can see
* that it is in the trash. Restoring and purging change the file store, so the
* generic write check above already applies to them.
*/
/**
* Storage usage, for the administrator dashboard.
*
* Administrator-only: it names the largest files in the store and how much
* every account has uploaded, which is more than a viewer needs to know.
*
* Measuring walks the whole tree, so the result is cached; ?refresh=1 forces a
* fresh measurement for the Recalculate button.
*/
if ($path === '/api/storage/usage' && $method === 'GET') api_try(function()use($fs, $config) {
    Authorization::requireAdmin();
    release_session_lock();

    $report = storage_report($fs, $config, !empty($_GET['refresh']));

    // Drop ledger rows whose file has since gone by a route CloudHub does not
    // see. Without this the per-account figures below drift upwards forever on
    // an installation that has no quota configured, because nothing else calls
    // the sweep.
    ledger()->sweep($fs);

    // Usage per account, with names resolved here rather than in the ledger so
    // that it stays a pure accounting table.
    $names = [];
    foreach ((new UserRepository(db()))->all() as $u)$names[(int)$u['id']] = $u['username'];
    $byUser = array_map(fn(array $r) => [
        'userId' => $r['userId'],
        'username' => $r['userId'] === null?null:($names[$r['userId']] ?? 'deleted account'),
        'bytes' => $r['bytes'], 'files' => $r['files'],
    ], ledger()->usageByUser());

    return $report + [
        'storageLimitBytes' => (int)round((float)$config['storage_limit_gb'] * 1073741824),
        'userQuotaBytes' => (int)round((float)$config['user_quota_gb'] * 1073741824),
        'cacheSeconds' => (int)$config['usage_cache_seconds'],
        'byUser' => $byUser,
    ];
});
if ($path === '/api/trash' && $method === 'GET') api_try(function()use($fs, $config) {
    release_session_lock();
    return ['enabled' => (bool)$config['trash_enabled'], 'retentionDays' => (int)$config['trash_retention_days'],
        'entries' => $fs->trashList()];
});
if ($path === '/api/trash/restore' && $method === 'POST') api_try(function()use($fs) {
    $fs->writable();
    $b = Http::body();
    $restored = $fs->restore(Http::string($b, 'id', 1, 64));
    // The file is back on disk but its ledger row went when it was trashed;
    // the periodic sweep leaves the rest consistent.
    ledger()->sweep($fs);
    AuditLog::write(db(), 'file.restore', 'success', ['path' => $restored['path']]);
    return ['success' => true, 'path' => $restored['path'],
        'message' => $restored['renamed']
            ? 'Restored as "'.basename($restored['path']).'" because the original name was taken'
            : 'Restored to '.$restored['path']];
});
if ($path === '/api/trash/purge' && $method === 'POST') api_try(function()use($fs) {
    $fs->writable();
    $b = Http::body();
    $all = !empty($b['all']);
    $n = $fs->trashPurge($all?null:Http::string($b, 'id', 1, 64));
    AuditLog::write(db(), 'file.purge', 'success', ['entries' => $n, 'all' => $all]);
    return ['success' => true, 'purged' => $n,
        'message' => $n === 1?'Permanently deleted 1 item':'Permanently deleted '.$n.' items'];
});
if ($path === '/api/files/rename' && $method === 'POST') api_try(function()use($fs, $config) {
    $fs->writable(); $b = Http::body(); $a = $fs->existing((string)($b['oldPath']??'')); $z = $fs->destination((string)($b['newPath']??'')); if (!file_exists($a))throw new RuntimeException('Source not found', 404); if (file_exists($z)&&!$config['allow_overwrite'])throw new RuntimeException('Destination already exists', 409);
    $from = $fs->relative($a);
    if (!rename($a, $z))throw new RuntimeException('Rename failed', 500);
    ledger()->relocate($from, $fs->relative($z));
    return ['success' => true,
        'message' => 'Renamed successfully'];
});
/**
* Resumable upload protocol.
*
* init -> repeated raw chunk PUTs -> complete. The browser can query status at
* any point and continue from the server-confirmed byte offset. This avoids
* PHP's normal multipart/post_max_size limit because each request contains only
* one small chunk.
*/
if ($path === '/api/uploads/init' && $method === 'POST') api_try(function()use($config, $fs) {
    $b = Http::body();
    $size = (int)($b['size']??-1);
    // Checked before anything is staged, so a doomed upload does not first
    // consume the disk it is about to be refused for.
    if ($size > 0)assert_upload_fits($fs, $config, $size);
    return uploads()->init(
        (string)($b['targetPath']??'/'), (string)($b['name']??''), $size,
        (string)($b['uploadId']??''), (string)($b['conflict']??$config['upload_conflict'])
    );
});
if ($path === '/api/uploads/status' && $method === 'GET') api_try(fn() => uploads()->status((string)($_GET['id']??'')));
if ($path === '/api/uploads/chunk' && $method === 'PUT') api_try(function() {
    $id = (string)($_GET['id']??''); $offset = (int)($_SERVER['HTTP_X_UPLOAD_OFFSET']??-1);
    return uploads()->append($id, $offset, 'php://input');
});
if ($path === '/api/uploads/complete' && $method === 'POST') api_try(function()use($fs) {
    $b = Http::body();
    $done = uploads()->complete((string)($b['id']??''));

    // Attribute the finished file to whoever uploaded it, so a per-account
    // quota has something to count. Best-effort, like the audit log.
    $full = $fs->root().$done['path'];
    ledger()->record((string)$done['path'], (string)$done['name'],
        is_file($full)?(int)(filesize($full)?:0):0, is_file($full)?mime_type($full):null,
        Auth::user()['id'] ?? null);
    return $done;
});
if ($path === '/api/uploads/cancel' && $method === 'DELETE') api_try(function() {
    $b = Http::body(); return uploads()->cancel((string)($b['id']??''));
});
if ($path === '/api/uploads/cleanup' && $method === 'POST') api_try(fn() => ['success' => true, 'removed' => uploads()->cleanupAbandoned()]);

/**
* Upload one or more files into the requested storage directory, in a single
* multipart request.
*
* The browser does not use this route -- the file manager uploads through the
* resumable chunk protocol above, which is not bound by post_max_size. It is
* kept for API clients and scripted uploads of small files.
*
* Browser-side validation improves feedback, but all limits are repeated here
* because request data is untrusted. PHP upload error codes are translated into
* useful API messages so the upload dialog can explain failures to the user.
*/
if ($path === '/api/files/upload' && $method === 'POST') api_try(function()use($fs, $config) {
    $fs->writable();
    // existing() already 404s on a path that is not there, so reaching this
    // check means the path resolved to a file. Reporting that as "unable to
    // create the upload directory", with a 500, blamed the server for what is
    // a bad request.
    $target = $fs->existing((string)($_POST['targetPath']??'/'));
    if (!is_dir($target)) throw new RuntimeException('The upload target is not a directory', 400);

    $names = $_FILES['files']['name']??[];
    $tmp = $_FILES['files']['tmp_name']??[];
    $errs = $_FILES['files']['error']??[];
    $sizes = $_FILES['files']['size']??[];
    if (!is_array($names)) {
        $names = [$names]; $tmp = [$tmp]; $errs = [$errs]; $sizes = [$sizes];
    }
    if (!$names) throw new RuntimeException('No files were selected', 400);
    if (count($names) > $config['max_upload_files']) throw new RuntimeException('Maximum '.$config['max_upload_files'].' files can be uploaded at once', 400);

    $maxBytes = max(1, (int)$config['max_upload_mb'])*1024*1024;
    $saved = [];
    $uploadError = function(int $code, string $name): string {
        return match($code) {
            UPLOAD_ERR_INI_SIZE => 'The server rejected '.$name.' because it exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'The file '.$name.' exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'The upload of '.$name.' was interrupted before it completed.',
            UPLOAD_ERR_NO_FILE => 'No file data was received for '.$name.'.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write '.$name.' to temporary storage.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload of '.$name.'.',
            default => 'Upload failed for '.$name.'.'
            };
        };

        foreach ($names as $i => $name) {
            $safe = $fs->safeName((string)$name);
            $error = (int)($errs[$i]??UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) throw new RuntimeException($uploadError($error, $safe), 400);
            if ((int)($sizes[$i]??0) > $maxBytes) throw new RuntimeException($safe.' exceeds the '.$config['max_upload_mb'].' MB per-file limit', 413);
            if (!is_uploaded_file((string)($tmp[$i]??''))) throw new RuntimeException('Invalid upload data received for '.$safe, 400);
            $dest = $target.'/'.$safe;
            if (file_exists($dest)&&!$config['allow_overwrite']) throw new RuntimeException('File already exists: '.$safe, 409);
            if (!move_uploaded_file((string)$tmp[$i], $dest)) throw new RuntimeException('Unable to save '.$safe, 500);
            $saved[] = $safe;
        }
        return ['success' => true,
            'message' => count($saved).' file(s) uploaded successfully',
            'files' => $saved];
    });
    if ($path === '/api/files/download-zip' && $method === 'POST') api_try(function()use($fs) {
        if (!class_exists('ZipArchive'))throw new RuntimeException('PHP zip extension is required', 500);

        $b = Http::body(262144);
        $files = $b['files']??[];
        if (!is_array($files)||!array_is_list($files)||!$files)throw new RuntimeException('No files specified', 400);
        if (count($files) > 500)Http::error(422, 'VALIDATION_FAILED', 'A maximum of 500 files can be downloaded at once');
        foreach ($files as $item)if (!is_string($item) || strlen($item) > 4096)Http::error(422, 'VALIDATION_FAILED', 'Invalid file path in selection');

        $tmp = tempnam(sys_get_temp_dir(), 'cfhzip');
        if ($tmp === false)throw new RuntimeException('Unable to create a temporary archive', 500);

        $zip = new ZipArchive();
        // tempnam() has already created the file, so OVERWRITE is required.
        // The result used to go unchecked: a failed open made every later
        // addFile() a no-op and shipped an empty archive as if it had worked.
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Unable to create the archive', 500);
        }

        /*
         * Selections are addressed by full path but enter the archive under
         * their basename, so picking report.pdf from two different folders
         * produced one entry and silently dropped a file. Top-level names are
         * therefore made unique; everything below a unique root is already
         * unique because a directory cannot hold two entries of one name.
         */
        $roots = [];
        $uniqueRoot = function(string $name)use(&$roots): string {
            $key = strtolower($name);
            if (!isset($roots[$key])) {
                $roots[$key] = true;
                return $name;
            }
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            for ($i = 2; $i < 10000; $i++) {
                $candidate = $stem.' ('.$i.')'.($ext !== ''?'.'.$ext:'');
                if (!isset($roots[strtolower($candidate)])) {
                    $roots[strtolower($candidate)] = true;
                    return $candidate;
                }
            }
            throw new RuntimeException('Unable to name the archive entries uniquely', 500);
        };

        $add = function(string $full, string $prefix)use(&$add, $zip, $fs): void {
            if (is_dir($full)) {
                $entries = array_values(array_filter(scandir($full)?:[], fn($n) => $n !== '.' && $n !== '..'));
                // An empty directory has no files to imply it, so without this
                // it disappeared from the archive entirely.
                if (!$entries) {
                    $zip->addEmptyDir(ltrim($prefix, '/'));
                    return;
                }
                foreach ($entries as $n) {
                    if ($fs->escapingSymlink($full.'/'.$n))continue;
                    $add($full.'/'.$n, $prefix.'/'.$n);
                }
                return;
            }
            if (is_file($full))$zip->addFile($full, ltrim($prefix, '/'));
        };

        try {
            foreach ($files as $p) {
                $f = $fs->existing((string)$p);
                if (file_exists($f))$add($f, $uniqueRoot(basename($f)));
            }
            if ($zip->numFiles === 0) throw new RuntimeException('None of the selected items could be read', 404);
            if (!$zip->close())throw new RuntimeException('Unable to finalise the archive', 500);
        } catch (Throwable $e) {
            // A rejected path must not leave the staging archive on disk.
            @$zip->close();
            @unlink($tmp);
            throw $e;
        }

        clearstatcache(true, $tmp);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="download.zip"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    });

    if ($path === '/api/shares/create' && $method === 'POST') api_try(function()use($fs, $config, $basePath) {
        $b = Http::body(16384);
        $rel = Http::string($b, 'filePath', 1, 4096);
        $f = $fs->existing($rel);
        if (!is_file($f))throw new RuntimeException('File not found', 404);

        $hours = Http::optionalInt($b, 'expiresInHours', 0, 8760, (int)$config['share_expiry_hours']);
        $pdo = db();

        // Reuse a live link for the same file so repeated shares stay stable,
        // unless the caller asked for a different lifetime.
        $s = $pdo->prepare('SELECT token,expires_at FROM share_links WHERE file_path=? AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1');
        $s->execute([$rel]);
        $r = $s->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $exp = $hours > 0?gmdate('Y-m-d H:i:s', time()+$hours*3600):null;
            $s = $pdo->prepare('INSERT INTO share_links(token,file_path,expires_at) VALUES(?,?,?)');
            $s->execute([$token, $rel, $exp]);
            $r = ['token' => $token, 'expires_at' => $exp];
            AuditLog::write($pdo, 'share.create', 'success', ['path' => $rel, 'expiresInHours' => $hours]);
        }

        return ['token' => $r['token'],
            'url' => share_url($config, $basePath, (string)$r['token']),
            'name' => basename($f),
            'kind' => share_media_kind($f),
            'expiresAt' => $r['expires_at']?gmdate('c', strtotime((string)$r['expires_at'])):null];
    });
    if ($path === '/api/shares/list' && $method === 'GET') api_try(function()use($config, $basePath, $fs) {
        Authorization::requireAdmin();
        $pdo = db();
        $pdo->exec('DELETE FROM share_links WHERE expires_at IS NOT NULL AND expires_at<UTC_TIMESTAMP()');
        $rows = $pdo->query('SELECT token,file_path,created_at,expires_at FROM share_links ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($x) => [
            'token' => $x['token'],
            'filePath' => $x['file_path'],
            'name' => basename((string)$x['file_path']),
            'url' => share_url($config, $basePath, (string)$x['token']),
            'createdAt' => gmdate('c', strtotime((string)$x['created_at'])),
            'expiresAt' => $x['expires_at']?gmdate('c', strtotime((string)$x['expires_at'])):null,
        ], $rows);
    });
    if ($path === '/api/shares/revoke' && $method === 'DELETE') api_try(function() {
        $b = Http::body(8192);
        $token = Http::string($b, 'token', 20, 128);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $token))Http::error(422, 'VALIDATION_FAILED', 'token has an invalid format');
        $s = db()->prepare('DELETE FROM share_links WHERE token=?');
        $s->execute([$token]);
        if (!$s->rowCount())throw new RuntimeException('Token not found', 404);
        AuditLog::write(db(), 'share.revoke', 'success', ['token' => substr($token, 0, 8).'...']);
        return ['success' => true, 'message' => 'Share link revoked'];
    });

    /**
     * Public share endpoints. These are deliberately outside the authenticated
     * API guard: possession of the 256-bit token is the credential, so a
     * recipient never needs an account.
     *
     *   /share/{token}           human-readable viewer page
     *   /share/{token}/raw       the bytes, inline and range-capable
     *   /share/{token}/download  the bytes, as an attachment
     */
    if (preg_match('#^/share/([A-Za-z0-9_-]{20,128})(?:/(raw|download))?$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) api_try(function()use($m, $fs, $config, $basePath, $assetBase, $method) {
        [$share, $file] = share_resolve($fs, $m[1]);
        $variant = $m[2]??'';
        $kind = share_media_kind($file);

        // Shared links point at private files: keep them out of search results.
        header('X-Robots-Tag: noindex, nofollow');

        if ($variant === '' && $kind !== 'other') {
            // Viewer page. Rendered from our own origin, so it gets the normal
            // application CSP rather than the sandbox applied to the bytes.
            $shareFile = [
                'name' => basename($file),
                'kind' => $kind,
                'size' => filesize($file)?:0,
                'mime' => media_mime_type($file),
                'rawUrl' => $basePath.'/share/'.$share['token'].'/raw',
                'downloadUrl' => $basePath.'/share/'.$share['token'].'/download',
                'pageUrl' => share_url($config, $basePath, (string)$share['token']),
                'expiresAt' => $share['expires_at']?gmdate('c', strtotime((string)$share['expires_at'])):null,
            ];
            header('Cache-Control: private,no-store');
            require dirname(__DIR__).'/views/pages/share.php';
            exit;
        }

        // Raw bytes. A sandbox CSP plus nosniff keeps anything script-capable
        // inert even when a browser is talked into rendering it, and the range
        // support is what lets a recipient seek within a shared video.
        $disposition = ($variant === 'raw' && $kind !== 'other')?'inline':'attachment';
        serve_file_range($file, media_mime_type($file), $disposition, $method, [
            'Cache-Control: private,max-age=300',
            "Content-Security-Policy: default-src 'none'; sandbox; img-src 'self' data:; media-src 'self'; style-src 'none'; script-src 'none'",
        ]);
    });

    if ($path === '/api/thumbnail' && $method === 'GET') api_try(function()use($fs) {
        // Nothing below writes to the session, and a gallery asks for dozens of
        // these at once, so let the other requests through immediately.
        release_session_lock();

        $f = $fs->existing((string)($_GET['path'] ?? ''));
        if (!is_file($f))throw new RuntimeException('File not found', 404);

        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $cache = thumbnail_cache_path($f);
        if ($cache === null)throw new RuntimeException('Unable to prepare the thumbnail cache', 500);

        // A cached entry is served the same way whatever produced it, so a
        // video whose frame the browser has already contributed costs no more
        // than a photo.
        if (is_file($cache))send_thumbnail($cache);

        if (in_array($ext, THUMBNAIL_VIDEO_EXTENSIONS, true)) {
            // Nothing has contributed a frame for this video yet. That is an
            // ordinary state, not a broken request: the file listing carries a
            // hasThumbnail flag so the browser knows to decode one instead of
            // asking for it, and only reaches here if the cache entry vanished
            // in between.
            throw new RuntimeException('No thumbnail has been generated for this video yet', 404);
        }

        if (!in_array($ext, THUMBNAIL_IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('Not a supported thumbnail type', 400);
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required for image thumbnails', 503);
        }

        $create = match($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($f),
            'png' => @imagecreatefrompng($f),
            'gif' => @imagecreatefromgif($f),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f) : false,
            'bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($f) : false,
            default => false
        };
        if (!$create)throw new RuntimeException('Failed to generate thumbnail', 500);

        // Dimensions come from the decoded image rather than a second
        // getimagesize() read of the file.
        $w = imagesx($create);
        $h = imagesy($create);
        if (!$w || !$h) {
            imagedestroy($create);
            throw new RuntimeException('Failed to generate thumbnail', 500);
        }

        $scale = min(300 / $w, 300 / $h, 1);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $im = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($im, $create, 0, 0, 0, 0, $nw, $nh, $w, $h);

        // Write through a temporary file: two browsers asking for the same new
        // thumbnail at once must not read a half-written one.
        $tmp = $cache.'.'.bin2hex(random_bytes(4)).'.tmp';
        $ok = @imagewebp($im, $tmp, 75);
        imagedestroy($im);
        imagedestroy($create);
        if (!$ok || !@rename($tmp, $cache)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to store image thumbnail', 500);
        }

        send_thumbnail($cache);
    });

    /**
     * Store a video thumbnail decoded by the browser.
     *
     * The server has no video decoder, so a frame can only come from a client.
     * Without somewhere to keep it, every visitor re-fetched and re-decoded the
     * video on every single page load; caching it makes that a one-off.
     *
     * The payload is a derived image for a file the caller can already watch,
     * so any signed-in user may contribute one, but it is validated as a real
     * image of sane size before it is written.
     */
    if ($path === '/api/thumbnail/video' && $method === 'POST') api_try(function()use($fs) {
        $b = Http::body(512 * 1024);
        $rel = Http::string($b, 'path', 1, 4096);

        $f = $fs->existing($rel);
        if (!is_file($f))throw new RuntimeException('File not found', 404);
        if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), THUMBNAIL_VIDEO_EXTENSIONS, true)) {
            throw new RuntimeException('Only video files take a browser-generated thumbnail', 400);
        }

        $cache = thumbnail_cache_path($f);
        if ($cache === null)throw new RuntimeException('Unable to prepare the thumbnail cache', 500);
        if (is_file($cache))return ['success' => true, 'stored' => false];

        $image = Http::string($b, 'image', 32, 400000);
        if (preg_match('#^data:image/(webp|jpeg|png);base64,#i', $image, $m)) {
            $image = substr($image, strlen($m[0]));
        }
        $raw = base64_decode(strtr($image, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 32 || strlen($raw) > 262144) {
            throw new RuntimeException('The thumbnail image is missing or too large', 422);
        }

        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info[2], [IMAGETYPE_WEBP, IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new RuntimeException('The thumbnail must be a WebP, JPEG or PNG image', 422);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 1280 || $info[1] > 1280) {
            throw new RuntimeException('The thumbnail dimensions are out of range', 422);
        }

        // Cached thumbnails are always served as image/webp, so anything else
        // is re-encoded rather than stored under a mime type it is not. The
        // browser sends WebP when it can and falls back to JPEG when it cannot.
        if ($info[2] !== IMAGETYPE_WEBP) {
            if (!extension_loaded('gd') || !function_exists('imagewebp')) {
                throw new RuntimeException('A WebP thumbnail is required on this server', 415);
            }
            $decoded = @imagecreatefromstring($raw);
            if ($decoded === false)throw new RuntimeException('The thumbnail image could not be read', 422);
            ob_start();
            $encoded = @imagewebp($decoded, null, 75);
            $raw = (string)ob_get_clean();
            imagedestroy($decoded);
            if (!$encoded || $raw === '')throw new RuntimeException('Unable to convert the thumbnail', 500);
        }

        $tmp = $cache.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $raw) !== strlen($raw) || !@rename($tmp, $cache)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to store the video thumbnail', 500);
        }

        return ['success' => true, 'stored' => true];
    });

    /**
     * Account management.
     *
     * The roles in Authorization have always been enforced, but nothing could
     * create an account with one: tools/create-admin.php only ever made
     * administrators, so viewer and editor were unreachable in practice.
     *
     * Every route here is administrator-only. The guard above has already
     * verified CSRF for the mutating methods.
     */
    if ($path === '/api/users/me/password' && $method === 'POST') api_try(function() {
        Authorization::requireRead();
        $b = Http::body(16384);
        $current = Http::string($b, 'currentPassword', 1, 4096);
        $next = Http::string($b, 'newPassword', USER_PASSWORD_MIN_LENGTH, 4096);

        $id = (int)($_SESSION['user_id'] ?? 0);
        $users = new UserRepository(db());
        // Knowing the current password is what stops a borrowed session from
        // taking the account over.
        if ($id <= 0 || !$users->verifyPassword($id, $current)) {
            AuditLog::write(db(), 'user.password', 'failure');
            throw new RuntimeException('The current password is incorrect', 403);
        }

        $users->update($id, ['password' => $next]);
        AuditLog::write(db(), 'user.password', 'success');
        return ['success' => true, 'message' => 'Password changed'];
    });

    if ($path === '/api/users' && $method === 'GET') api_try(function() {
        Authorization::requireAdmin();
        return (new UserRepository(db()))->all();
    });

    if ($path === '/api/users' && $method === 'POST') api_try(function() {
        Authorization::requireAdmin();
        $b = Http::body(16384);
        $username = Http::string($b, 'username', 1, 100);
        if (!preg_match('/^[A-Za-z0-9._@-]+$/', $username)) {
            Http::error(422, 'VALIDATION_FAILED', 'A username may contain letters, digits and . _ @ - only');
        }
        $password = Http::string($b, 'password', USER_PASSWORD_MIN_LENGTH, 4096);
        $role = UserRepository::normaliseRole(Http::string($b, 'role', 1, 20));

        $created = (new UserRepository(db()))->create($username, $password, $role);
        AuditLog::write(db(), 'user.create', 'success', ['username' => $username, 'role' => $role]);
        return $created;
    });

    if (preg_match('#^/api/users/(\d+)$#', $path, $m) && in_array($method, ['GET', 'PATCH', 'DELETE'], true)) api_try(function()use($m, $method) {
        Authorization::requireAdmin();

        $id = (int)$m[1];
        $self = (int)($_SESSION['user_id'] ?? 0);
        $users = new UserRepository(db());

        $target = $users->get($id);
        if ($target === null)throw new RuntimeException('Account not found', 404);

        if ($method === 'GET')return $target;

        if ($method === 'DELETE') {
            // An administrator who deletes themselves, or the last remaining
            // administrator, locks everyone out of the settings for good.
            if ($id === $self)throw new RuntimeException('You cannot delete your own account', 409);
            if ($target['role'] === Authorization::ADMIN && $users->activeAdminCount($id) === 0) {
                throw new RuntimeException('This is the last administrator; promote another account first', 409);
            }
            $users->delete($id);
            AuditLog::write(db(), 'user.delete', 'success', ['username' => $target['username']]);
            return ['success' => true, 'message' => 'Account deleted'];
        }

        $b = Http::body(16384);
        $changes = [];
        if (array_key_exists('role', $b))$changes['role'] = UserRepository::normaliseRole(Http::string($b, 'role', 1, 20));
        if (array_key_exists('isActive', $b))$changes['isActive'] = (bool)$b['isActive'];
        if (array_key_exists('password', $b))$changes['password'] = Http::string($b, 'password', USER_PASSWORD_MIN_LENGTH, 4096);
        if (!$changes)throw new RuntimeException('No changes were supplied', 400);

        $losesAdmin = (array_key_exists('role', $changes) && $changes['role'] !== Authorization::ADMIN)
            || (array_key_exists('isActive', $changes) && $changes['isActive'] === false);

        if ($id === $self && $losesAdmin) {
            throw new RuntimeException('You cannot remove your own administrator access', 409);
        }
        if ($losesAdmin && $target['role'] === Authorization::ADMIN && $users->activeAdminCount($id) === 0) {
            throw new RuntimeException('This is the last administrator; promote another account first', 409);
        }

        $updated = $users->update($id, $changes);
        AuditLog::write(db(), 'user.update', 'success', [
            'username' => $target['username'],
            'role' => $updated['role'],
            'isActive' => $updated['isActive'],
            'passwordReset' => array_key_exists('password', $changes),
        ]);
        return $updated;
    });

    if ($path === '/api/security/events' && $method === 'GET') api_try(function() {
        Authorization::requireAdmin();
        $limit = max(1, min(200, (int)($_GET['limit']??100)));
        $stmt = db()->prepare('SELECT id,user_id,username,event_type,outcome,ip_address,user_agent,request_id,context_json,created_at FROM security_events ORDER BY id DESC LIMIT '.$limit);
        $stmt->execute(); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function($r) {
            $r['context'] = $r['context_json']?json_decode($r['context_json'], true):null; unset($r['context_json']); return $r;
        }, $rows);
    });

    if (str_starts_with($path, '/api/servers')) api_try(function()use($path, $method) {
        $repo = new ServerRepository();
        if ($path === '/api/servers/active' && $method === 'GET')return array_map('mask_server', $repo->all(true));
        Authorization::requireAdmin();
        if ($path === '/api/servers' && $method === 'GET')return array_map('mask_server', $repo->all()); if ($path === '/api/servers' && $method === 'POST') {
            $b = Http::body(); if (empty($b['name']) || empty($b['type'])||!isset($b['config']))throw new RuntimeException('name, type, and config are required', 400); return mask_server($repo->create(['name' => $b['name'], 'type' => $b['type'], 'config' => $b['config'], 'isActive' => $b['isActive']??true, 'isDefault' => $b['isDefault']??false]));
        }if (preg_match('#^/api/servers/(\d+)(?:/(toggle|set-default))?$#', $path, $m)) {
            $id = (int)$m[1]; $s = $repo->get($id); if (!$s)throw new RuntimeException('Server not found', 404); if ($method === 'GET')return mask_server($s); if ($method === 'DELETE') {
                $repo->delete($id); return ['success' => true,
                    'message' => 'Server deleted'];
            }if ($method === 'PUT') {
                $b = Http::body(); if (isset($b['config']))foreach ($b['config'] as $k => $v)if ($v === '••••••••')$b['config'][$k] = $s['config'][$k]??$v; return mask_server($repo->update($id, $b));
            }if (($m[2]??'') === 'toggle' && $method === 'POST')return mask_server($repo->update($id, ['isActive'=>!$s['isActive']])); if (($m[2]??'') === 'set-default' && $method === 'POST') {
                $repo->setDefault($id); return ['success' => true,
                    'message' => $s['name'].' set as default'];
            }}throw new RuntimeException('Endpoint not implemented', 404);
    });

    if (str_starts_with($path, '/webdav')) {
        require dirname(__DIR__).'/src/Services/WebDav.php'; \CloudHub\Services\handle_webdav($fs, $config, $path, $method); exit;
    }
    if (str_starts_with($path, '/api/') && $method === 'OPTIONS') {
        http_response_code(204); header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS'); exit;
    }
    if (str_starts_with($path, '/api/'))Http::error(404, 'NOT_FOUND', 'API endpoint not found');
    if (in_array($path, ['/', '/servers', '/browse', '/users', '/trash', '/storage'], true)) {
        require dirname(__DIR__).'/views/pages/app.php'; exit;
    }
/**
* Handles media playback requests by validating the file,
* populating the $mediaFile payload, and rendering the app view.
*/
if ($path === '/play' && $method === 'GET') {
    Authorization::requireRead();

    $relPath = (string)($_GET['path'] ?? '');
    $mediaFile = null;

    if ($relPath !== '') {
        try {
            $f = $fs->existing($relPath);
            if (is_file($f)) {
                $mime = mime_type($f);
                if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
                    $size = filesize($f);
                    $mediaFile = [
                        'name' => basename($f),
                        'path' => $relPath,
                        'formatted_size' => method_exists($fs, 'formatSize') ? $fs->formatSize($size) : round($size / 1024 / 1024, 2) . ' MB',
                        'stream_url' => $frontController . '?route=%2Fapi%2Ffiles%2Fstream&path=' . urlencode($relPath),
                        'sprite_url' => ''
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('['.Http::requestId().'] Playback error: '.$e->getMessage());
        }
    }

    require dirname(__DIR__) . '/views/pages/play.php';
    exit;
}
    http_response_code(404); echo 'Not found';

