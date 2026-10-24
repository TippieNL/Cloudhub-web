<?php
declare(strict_types=1);

/**
 * Thumbnails over real HTTP, against a real database: the API the Android app
 * (Cloudhub-2, 5.0 and later) speaks, and the routes that keep thumbnails in
 * step with their photos.
 *
 *   php tests/http/thumbnails_run.php
 *
 * Needs the MySQL/MariaDB database .env points at, migrated with
 * php database/migrate.php, and PHP's GD with JPEG and WebP. It creates its own
 * accounts (th<random>_...) and deletes them when it is done, works in a
 * folder of its own in the store, and starts its own CloudHub on PHP's
 * built-in server -- with four workers, so two scans can race -- from this
 * checkout, so it reads and writes this checkout's storage/. It runs the
 * thumbnail scan over the whole store, which makes thumbnails and never
 * changes a photo; background tasks are run with tools/worker.php --once.
 *
 * tests/phase56_thumbnails_test.php covers the service and the scan in one
 * process; this covers what only a deployment has: sessions, CSRF, roles, the
 * routes' bookkeeping, WebDAV, the worker, a reply finished before its
 * thumbnail is made, and the shape the Android client reads.
 */
require dirname(__DIR__, 2).'/config/bootstrap.php';
require __DIR__.'/Client.php';

use CloudHub\Helpers\Db;
use CloudHub\Repositories\UserRepository;
use CloudHub\Tests\Http\Client;
use CloudHub\Tests\Http\Response;

$root = dirname(__DIR__, 2);
if (!extension_loaded('gd') || empty(gd_info()['WebP Support']) || empty(gd_info()['JPEG Support'])) {
    fwrite(STDERR, "These scenarios need PHP's GD with JPEG and WebP support.\n");
    exit(1);
}
$work = sys_get_temp_dir().'/cloudhub-thumbs-http-'.bin2hex(random_bytes(4));
mkdir($work.'/sessions', 0775, true);

/* ---- accounts of our own -------------------------------------------------- */

$db = Db::connection();
$tag = 'th'.bin2hex(random_bytes(3));
$repo = new UserRepository($db);
$accounts = [
    'editor' => [$tag.'_ed', 'editor-pass-12345', 'editor'],
    'viewer' => [$tag.'_vi', 'viewer-pass-12345', 'viewer'],
];
$ids = [];
foreach ($accounts as $key => [$name, $password, $role]) $ids[$key] = $repo->create($name, $password, $role)['id'];

/* ---- a server of our own ---------------------------------------------------- */

$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
// The same settings for the server and for the worker that runs its tasks.
$env = ['APP_ENV' => 'production', 'TRASH_ENABLED' => 'true', 'QUEUE_RUNNER' => 'worker',
    'LOGIN_RATE_IP_ATTEMPTS' => '1000'];
$server = proc_open([PHP_BINARY, '-d', 'session.save_path='.$work.'/sessions', '-S', '127.0.0.1:'.$port, 'router.php'],
    [1 => ['file', $work.'/server.log', 'w'], 2 => ['file', $work.'/server.log', 'a']], $pipes, $root,
    array_merge(getenv(), $env, ['PHP_CLI_SERVER_WORKERS' => '4']));
if (!is_resource($server)) { fwrite(STDERR, "Could not start the test server\n"); exit(1); }

$scratch = '/_thumbtest_'.bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($server, $db, $ids, $work): void {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($ids as $id) {
        foreach (['DELETE FROM jobs WHERE user_id = ?', 'DELETE FROM users WHERE id = ?'] as $sql) {
            try { $db->prepare($sql)->execute([$id]); } catch (Throwable) {}
        }
    }
    foreach (array_merge(glob($work.'/*/*') ?: [], glob($work.'/*') ?: []) as $path) is_dir($path) ? @rmdir($path) : @unlink($path);
    @rmdir($work);
});

$base = 'http://127.0.0.1:'.$port;
$up = false;
for ($i = 0; $i < 80 && !$up; $i++) {
    $up = @file_get_contents($base.'/?route='.rawurlencode('/api/auth/status')) !== false;
    if (!$up) usleep(100_000);
}
if (!$up) { fwrite(STDERR, "The test server never answered. Log:\n".@file_get_contents($work.'/server.log')."\n"); exit(1); }

/* ---- the harness ---------------------------------------------------------- */

$passed = 0;
$failures = [];
$currentCase = '';

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failures, $currentCase;
    if ($ok) { $passed++; return; }
    $failures[] = $currentCase.': '.$what.($detail !== '' ? ' -- '.$detail : '');
}

function scenario(string $name, callable $body): void
{
    global $currentCase, $failures;
    $currentCase = $name;
    try { $body(); } catch (Throwable $e) { $failures[] = $name.': threw '.get_class($e).' -- '.$e->getMessage(); }
    echo '  '.$name.PHP_EOL;
}

$client = new Client($base);
$signedIn = $client->signIn($accounts['editor'][0], $accounts['editor'][1]);
if (!$signedIn->ok()) { fwrite(STDERR, 'The editor could not sign in: '.$signedIn->describe()."\n"); exit(1); }
$client->post('/api/files/mkdir', ['path' => $scratch]);
$client->post('/api/files/mkdir', ['path' => $scratch.'/pics']);

echo "Thumbnails over HTTP on port $port".PHP_EOL;

/* ---- helpers ----------------------------------------------------------------- */

$store = $root.'/storage/files';
$thumbTree = $root.'/storage/.thumbnails/files';
$thumbOf = fn(string $path): string => $thumbTree.$path.'.webp';
$photo = function (int $w, int $h, int $tint = 0): string {
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x += 8) {
        imagefilledrectangle($im, $x, 0, $x + 7, $h - 1,
            imagecolorallocate($im, (int)($x * 255 / $w + $tint) % 256, 120, (int)(255 - $x * 255 / $w) % 256));
    }
    ob_start(); imagejpeg($im, null, 90); return (string)ob_get_clean();
};
$webp = function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 60, 90));
    ob_start(); imagewebp($im, null, 80); return (string)ob_get_clean();
};
$photoPut = function (string $path, string $bytes, string $conflict = 'overwrite') use ($client): ?string {
    $init = $client->post('/api/uploads/init', ['targetPath' => dirname($path), 'name' => basename($path),
        'size' => strlen($bytes), 'uploadId' => 'pic'.bin2hex(random_bytes(6)), 'conflict' => $conflict]);
    $id = (string)($init->json['id'] ?? '');
    if ($id === '') return null;
    $client->putChunk($id, 0, $bytes);
    $done = $client->post('/api/uploads/complete', ['id' => $id]);
    return $done->ok() ? (string)($done->json['path'] ?? '') : null;
};
// Made once the reply has gone, so it can land a moment after the reply does.
$soon = function (callable $ready, float $seconds = 5.0): bool {
    $until = microtime(true) + $seconds;
    do {
        clearstatcache();
        if ($ready()) return true;
        usleep(50_000);
    } while (microtime(true) < $until);
    return false;
};
$size = function (Response $response): ?array {
    if ($response->status !== 200) return null;
    $info = @getimagesizefromstring($response->body);
    return $info ? [$info[0], $info[1]] : null;
};
$entry = function (string $path) use ($client): ?array {
    foreach ($client->get('/api/files/list', ['path' => dirname($path)])->json ?? [] as $row) {
        if (($row['path'] ?? '') === $path) return $row;
    }
    return null;
};
// How the Android app and the web client both ask: the file's time and size
// in `v`, and the server's thumbnail format in `t`.
$askLikeTheApp = function (string $path, array $headers = []) use ($client, $entry): Response {
    $row = $entry($path) ?? ['modified' => '', 'size' => ''];
    return $client->get('/api/thumbnail', ['path' => $path, 'v' => $row['modified'].'-'.$row['size'], 't' => 2], $headers);
};
$worker = function () use ($root, $env): string {
    $command = [PHP_BINARY, $root.'/tools/worker.php', '--once', '--quiet'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), $env));
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    proc_close($process);
    return $out;
};

/* ---- what the server says it can do ------------------------------------------ */

scenario('the config says thumbnails are kept, and in which format', function () use ($client) {
    $config = $client->get('/api/files/config');
    check('the format is published', ($config->json['thumbnailFormat'] ?? null) === 2, $config->describe());
    check('and so is the scan, by the length of its slice', ($config->json['thumbnailScanSeconds'] ?? 0) > 0, $config->describe());
    check('beside what was there before', isset($config->json['duplicateScanSeconds'], $config->json['chunkMb']));
});

/* ---- a photo's thumbnail -------------------------------------------------------- */

scenario('a new photo gets a thumbnail without anyone asking', function () use ($client, $scratch, $store, $thumbOf, $photo, $photoPut, $soon, $size, $askLikeTheApp) {
    $bytes = $photo(1600, 1000);
    $path = $photoPut($scratch.'/pics/sunset.jpg', $bytes);
    check('the photo uploads', $path === $scratch.'/pics/sunset.jpg', (string)$path);

    // Looked for on disk before any thumbnail is asked for, so this is the
    // one the upload made and not one made on demand.
    check('its thumbnail is kept at the mirrored path', $soon(fn() => is_file($thumbOf($scratch.'/pics/sunset.jpg'))),
        $thumbOf($scratch.'/pics/sunset.jpg'));
    $kept = (string)@file_get_contents($thumbOf($scratch.'/pics/sunset.jpg'));
    check('as WebP', substr($kept, 0, 4) === 'RIFF' && substr($kept, 8, 4) === 'WEBP', bin2hex(substr($kept, 0, 12)));
    check('stamped with the photo\'s own time, which is how it is known to be current',
        @filemtime($thumbOf($scratch.'/pics/sunset.jpg')) === @filemtime($store.$scratch.'/pics/sunset.jpg'));

    $thumb = $askLikeTheApp($scratch.'/pics/sunset.jpg');
    check('the route serves it', $thumb->status === 200 && str_contains((string)$thumb->header('Content-Type'), 'image/webp'),
        $thumb->describe());
    check('fitted into 512 pixels with its shape kept', $size($thumb) === [512, 320], json_encode($size($thumb)));
    check('and it is the kept one, not a second decode', $thumb->body === $kept);
    check('far smaller than the photo', strlen($thumb->body) < strlen($bytes) / 4, strlen($thumb->body).' of '.strlen($bytes));
    check('cached by the browser for good, since its URL changes with the photo',
        str_contains((string)$thumb->header('Cache-Control'), 'immutable'), (string)$thumb->header('Cache-Control'));
    $etag = (string)$thumb->header('ETag');
    $again = $askLikeTheApp($scratch.'/pics/sunset.jpg', ['If-None-Match: '.$etag]);
    check('and asked again with its tag, answered with nothing', $etag !== '' && $again->status === 304, $again->describe());

    $original = $client->get('/api/files/download', ['path' => $scratch.'/pics/sunset.jpg']);
    check('the photo itself is untouched', $original->ok() && $original->body === $bytes,
        strlen($original->body).' bytes, '.$original->describe());

    // Replaced by a different picture: the old thumbnail is stale by its time
    // and the new one is the new picture's.
    $photoPut($scratch.'/pics/sunset.jpg', $photo(700, 700, 90));
    $square = $askLikeTheApp($scratch.'/pics/sunset.jpg');
    check('an overwritten photo gets a thumbnail of the new picture', $size($square) === [512, 512], json_encode($size($square)));
});

scenario('two photos with the same name keep their own thumbnails', function () use ($client, $scratch, $thumbOf, $photo, $photoPut, $soon, $size) {
    $first = $photoPut($scratch.'/pics/twin.jpg', $photo(800, 600));
    $second = $photoPut($scratch.'/pics/twin.jpg', $photo(600, 800), 'rename');
    check('the second is kept beside the first under a free name',
        $first === $scratch.'/pics/twin.jpg' && $second !== null && $second !== $first, (string)$second);
    if ($second === null || $second === $first) return;

    check('each has its own thumbnail', $soon(fn() => is_file($thumbOf($first)) && is_file($thumbOf($second))));
    check('the first is the landscape one', $size($client->get('/api/thumbnail', ['path' => $first])) === [512, 384]);
    check('the second the portrait one', $size($client->get('/api/thumbnail', ['path' => $second])) === [384, 512]);
});

scenario('a multipart upload gets its thumbnails too', function () use ($base, $client, $scratch, $thumbOf, $photo, $soon) {
    // The route scripts and API clients use; the browser sends chunks.
    $boundary = 'cloudhub'.bin2hex(random_bytes(8));
    $body = '';
    foreach (['harbour.jpg' => $photo(900, 600), 'pier.jpg' => $photo(600, 900, 40)] as $name => $bytes) {
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"$name\"\r\nContent-Type: image/jpeg\r\n\r\n$bytes\r\n";
    }
    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"targetPath\"\r\n\r\n$scratch/pics\r\n--$boundary--\r\n";
    $ch = curl_init($base.'/?route='.rawurlencode('/api/files/upload'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data; boundary='.$boundary, 'Accept: application/json',
            'Cookie: cloudhub_session='.$client->cookie('cloudhub_session'), 'X-CSRF-Token: '.$client->csrfToken()]]);
    $answer = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    check('both upload', ($answer['files'] ?? null) === ['harbour.jpg', 'pier.jpg'], json_encode($answer));
    check('and both have their thumbnails', $soon(fn() => is_file($thumbOf($scratch.'/pics/harbour.jpg')) && is_file($thumbOf($scratch.'/pics/pier.jpg'))));
});

scenario('a thumbnail follows its photo', function () use ($client, $scratch, $thumbOf, $photo, $photoPut, $soon) {
    $photoPut($scratch.'/pics/walk.jpg', $photo(900, 600));
    $soon(fn() => is_file($thumbOf($scratch.'/pics/walk.jpg')));

    $client->post('/api/files/rename', ['oldPath' => $scratch.'/pics/walk.jpg', 'newPath' => $scratch.'/pics/stroll.jpg']);
    clearstatcache();
    check('a rename moves it', is_file($thumbOf($scratch.'/pics/stroll.jpg')) && !is_file($thumbOf($scratch.'/pics/walk.jpg')));

    $client->post('/api/files/mkdir', ['path' => $scratch.'/pics/album']);
    $client->post('/api/files/copy', ['paths' => [$scratch.'/pics/stroll.jpg'], 'destination' => $scratch.'/pics/album']);
    clearstatcache();
    check('a copy gets one without a decode', is_file($thumbOf($scratch.'/pics/album/stroll.jpg'))
        && file_get_contents($thumbOf($scratch.'/pics/album/stroll.jpg')) === file_get_contents($thumbOf($scratch.'/pics/stroll.jpg')));

    $client->post('/api/files/move', ['paths' => [$scratch.'/pics/album'], 'destination' => $scratch]);
    clearstatcache();
    check('a folder moved takes its thumbnails along',
        is_file($thumbOf($scratch.'/album/stroll.jpg')) && !is_dir(dirname($thumbOf($scratch.'/pics/album/stroll.jpg'))));

    $gone = $client->delete('/api/files/delete', ['path' => $scratch.'/album/stroll.jpg']);
    clearstatcache();
    check('a delete to the trash removes it', ($gone->json['trashed'] ?? false) === true && !is_file($thumbOf($scratch.'/album/stroll.jpg')),
        $gone->describe());
    $client->post('/api/trash/restore', ['id' => (string)($gone->json['id'] ?? '')]);
    check('and a restore makes it again', $soon(fn() => is_file($thumbOf($scratch.'/album/stroll.jpg'))));
});

scenario('a missing or damaged thumbnail is made again', function () use ($client, $scratch, $thumbOf, $photo, $photoPut, $soon, $size) {
    $photoPut($scratch.'/pics/lost.jpg', $photo(1000, 500));
    $kept = $thumbOf($scratch.'/pics/lost.jpg');
    $soon(fn() => is_file($kept));

    @unlink($kept);
    $back = $client->get('/api/thumbnail', ['path' => $scratch.'/pics/lost.jpg']);
    clearstatcache();
    check('a deleted thumbnail is made when it is next shown', $size($back) === [512, 256] && is_file($kept), $back->describe());

    file_put_contents($kept, 'not a picture at all');
    $mended = $client->get('/api/thumbnail', ['path' => $scratch.'/pics/lost.jpg']);
    check('one written over is made again rather than served', $size($mended) === [512, 256], $mended->describe());

    // A file named like a photo that is not one: refused, and remembered so
    // the next gallery is answered without trying again.
    $client->post('/api/files/mkdir', ['path' => $scratch.'/pics/broken']);
    $photoPut($scratch.'/pics/broken/garbled.jpg', str_repeat('this is not a jpeg ', 200));
    $refused = $client->get('/api/thumbnail', ['path' => $scratch.'/pics/broken/garbled.jpg']);
    check('a damaged photo is refused, not a server error', $refused->status === 415, $refused->describe());
    check('and remembered', $soon(fn() => is_file($thumbOf($scratch.'/pics/broken/garbled.jpg').'.failed')));
    check('the file is still listed and still downloads',
        $client->get('/api/files/download', ['path' => $scratch.'/pics/broken/garbled.jpg'])->ok());

    $outside = $client->get('/api/thumbnail', ['path' => $scratch.'/../../config/config.php']);
    check('a path out of the store is refused', in_array($outside->status, [400, 403, 404], true), $outside->describe());
});

scenario('a thumbnail that cannot be kept is still served', function () use ($client, $scratch, $store, $thumbOf, $photo, $photoPut, $soon, $size) {
    // A full disk, or storage that will not take a file's time: made, not
    // kept. Played here by a folder of the mirror nothing can be written into.
    $client->post('/api/files/mkdir', ['path' => $scratch.'/stuck']);
    $photoPut($scratch.'/stuck/first.jpg', $photo(800, 400));
    $soon(fn() => is_file($thumbOf($scratch.'/stuck/first.jpg')));
    $dir = dirname($thumbOf($scratch.'/stuck/first.jpg'));
    exec('chattr +i '.escapeshellarg($dir).' 2>/dev/null', $out, $code);
    if ($code !== 0) { check('a folder can be made unwritable here (chattr +i); skipped', true); return; }
    try {
        file_put_contents($store.$scratch.'/stuck/second.jpg', $photo(400, 800));
        $served = $client->get('/api/thumbnail', ['path' => $scratch.'/stuck/second.jpg']);
        clearstatcache();
        check('it is answered with a thumbnail, not a 500', $size($served) === [256, 512], $served->describe());
        check('that was not kept', !is_file($thumbOf($scratch.'/stuck/second.jpg')));
        check('cacheable like any other, as it is right for its URL',
            str_contains((string)$served->header('Cache-Control'), 'immutable'), (string)$served->header('Cache-Control'));
        // The scan stops there and says why -- the app shows "Stopped: ..." --
        // rather than counting the photo, and every one after it, unreadable.
        $scan = $client->post('/api/thumbnails/scan', ['restart' => true]);
        check('the scan stops at it, saying why', $scan->status === 507
            && str_contains((string)($scan->json['error']['message'] ?? ''), 'cannot be written'), $scan->describe());
    } finally {
        exec('chattr -i '.escapeshellarg($dir));
    }
});

scenario('a share link that ends in the photo\'s extension works on the development server', function () use ($client, $base, $scratch, $photo, $photoPut) {
    $bytes = $photo(640, 360);
    $photoPut($scratch.'/pics/shared.jpg', $bytes);
    $share = $client->post('/api/shares/create', ['filePath' => $scratch.'/pics/shared.jpg']);
    $url = (string)($share->json['url'] ?? '');
    check('the link carries the extension', str_ends_with($url, '.jpg'), $url);
    // A photo's link is its viewer page; the bytes are at /raw.jpg beside it.
    $anyone = new Client($base);
    $page = $anyone->fetchUrl(preg_replace('#^https?://[^/]+#', $base, $url) ?? $url);
    $raw = preg_replace('#\.jpg$#', '/raw.jpg', preg_replace('#^https?://[^/]+#', $base, $url) ?? $url) ?? '';
    check('which, here as in production, opens its page for someone with no account',
        $page->status === 200 && str_contains($page->body, '/raw.jpg'), $page->describe());
    $fetched = $anyone->fetchUrl($raw);
    check('and the photo itself', $fetched->status === 200 && $fetched->body === $bytes, $raw.' '.$fetched->describe());
    $client->delete('/api/shares/revoke', ['token' => (string)($share->json['token'] ?? '')]);
});

/* ---- video frames ---------------------------------------------------------------- */

scenario('a video\'s frame, contributed by a client, is kept in the mirror', function () use ($client, $base, $accounts, $scratch, $thumbOf, $webp, $entry, $size) {
    $video = $scratch.'/pics/clip.mp4';
    $init = $client->post('/api/uploads/init', ['targetPath' => dirname($video), 'name' => basename($video), 'size' => 4096,
        'uploadId' => 'vid'.bin2hex(random_bytes(6)), 'conflict' => 'overwrite']);
    $client->putChunk((string)($init->json['id'] ?? ''), 0, str_repeat("\0", 4096));
    $client->post('/api/uploads/complete', ['id' => (string)($init->json['id'] ?? '')]);

    check('a video with no frame yet says so in the listing', ($entry($video)['hasThumbnail'] ?? null) === false, json_encode($entry($video)));
    $none = $client->get('/api/thumbnail', ['path' => $video]);
    check('and asking for one is a plain 404', $none->status === 404, $none->describe());

    // Any account that can watch the video may contribute its frame.
    $viewer = new Client($base);
    $viewer->signIn($accounts['viewer'][0], $accounts['viewer'][1]);
    $frame = $webp(320, 180);
    $stored = $viewer->post('/api/thumbnail/video', ['path' => $video, 'image' => 'data:image/webp;base64,'.base64_encode($frame)]);
    check('a viewer can contribute one', ($stored->json['stored'] ?? null) === true, $stored->describe());
    clearstatcache();
    check('kept at the mirrored path', is_file($thumbOf($video)) && file_get_contents($thumbOf($video)) === $frame);
    check('the listing now says it has one', ($entry($video)['hasThumbnail'] ?? null) === true);
    $served = $client->get('/api/thumbnail', ['path' => $video]);
    check('and it is served as any thumbnail is', $size($served) === [320, 180] && $served->body === $frame, $served->describe());
    $twice = $viewer->post('/api/thumbnail/video', ['path' => $video, 'image' => 'data:image/webp;base64,'.base64_encode($frame)]);
    check('a second contribution is not needed', ($twice->json['stored'] ?? null) === false, $twice->describe());
});

/* ---- WebDAV ----------------------------------------------------------------------- */

scenario('WebDAV keeps thumbnails in step', function () use ($client, $scratch, $thumbOf, $photo, $soon, $size) {
    // As a WebDAV client addresses it: a clean path ending in the file's name,
    // which PHP's built-in server mistook for a script until router.php told
    // the application otherwise (see dev_front_controller()).
    $dav = fn(string $path): string => '/webdav'.$path;
    $put = $client->dav('PUT', $dav($scratch.'/pics/dav.jpg'), ['Content-Type: image/jpeg'], $photo(1200, 600));
    check('a PUT stores the photo', $put->status === 201, $put->describe());
    check('and makes its thumbnail', $soon(fn() => is_file($thumbOf($scratch.'/pics/dav.jpg'))));

    // How WebDAV clients often write: once, then again straight after --
    // often within the same second, which leaves the file's time where it was.
    $client->dav('PUT', $dav($scratch.'/pics/dav.jpg'), ['Content-Type: image/jpeg'], $photo(600, 1200, 60));
    $redone = $client->get('/api/thumbnail', ['path' => $scratch.'/pics/dav.jpg']);
    check('a PUT over it gets the new picture\'s thumbnail', $size($redone) === [256, 512], json_encode($size($redone)));

    $move = $client->dav('MOVE', $dav($scratch.'/pics/dav.jpg'), ['Destination: /webdav'.$scratch.'/pics/moved-by-dav.jpg']);
    clearstatcache();
    check('a MOVE takes it along', $move->ok() && is_file($thumbOf($scratch.'/pics/moved-by-dav.jpg'))
        && !is_file($thumbOf($scratch.'/pics/dav.jpg')), $move->describe());
    $delete = $client->dav('DELETE', $dav($scratch.'/pics/moved-by-dav.jpg'));
    clearstatcache();
    check('a DELETE removes it', $delete->status === 204 && !is_file($thumbOf($scratch.'/pics/moved-by-dav.jpg')), $delete->describe());
});

/* ---- background tasks -------------------------------------------------------------- */

scenario('a background copy carries thumbnails along', function () use ($client, $scratch, $thumbOf, $photo, $photoPut, $soon, $worker) {
    $client->post('/api/files/mkdir', ['path' => $scratch.'/trip']);
    foreach (['a', 'b', 'c'] as $i => $name) $photoPut($scratch.'/trip/'.$name.'.jpg', $photo(640, 480, $i * 50));
    $soon(fn() => is_file($thumbOf($scratch.'/trip/c.jpg')));
    $client->post('/api/files/mkdir', ['path' => $scratch.'/backup']);

    $queued = $client->post('/api/files/copy', ['paths' => [$scratch.'/trip'], 'destination' => $scratch.'/backup', 'background' => true]);
    check('the copy is queued', $queued->status === 202 && isset($queued->json['job']['id']), $queued->describe());
    $out = $worker();
    $job = $client->get('/api/jobs/'.($queued->json['job']['id'] ?? 'none'));
    check('and the worker runs it', ($job->json['job']['status'] ?? '') === 'completed', $job->describe().' '.$out);
    clearstatcache();
    $carried = true;
    foreach (['a', 'b', 'c'] as $name) {
        $copy = $thumbOf($scratch.'/backup/trip/'.$name.'.jpg');
        $carried = $carried && is_file($copy) && file_get_contents($copy) === file_get_contents($thumbOf($scratch.'/trip/'.$name.'.jpg'));
    }
    check('every copy has its original\'s thumbnail, with no decode', $carried);
});

scenario('"Make thumbnails" on a folder writes the thumbnails the gallery serves', function () use ($client, $root, $store, $scratch, $thumbOf, $photo, $worker) {
    // Photos put there by hand, as a copy straight onto the disk would.
    @mkdir($store.$scratch.'/by-hand', 0775, true);
    foreach (['one', 'two'] as $i => $name) file_put_contents($store.$scratch.'/by-hand/'.$name.'.jpg', $photo(800, 400, $i * 70));
    $queued = $client->post('/api/jobs', ['type' => 'thumbnails', 'params' => ['path' => $scratch.'/by-hand']]);
    check('the task is queued', $queued->status === 201, $queued->describe());
    $worker();
    $job = $client->get('/api/jobs/'.($queued->json['job']['id'] ?? 'none'))->json['job'] ?? [];
    check('and made both', ($job['status'] ?? '') === 'completed' && ($job['result']['made'] ?? 0) === 2, json_encode($job));
    clearstatcache();
    check('in the mirror', is_file($thumbOf($scratch.'/by-hand/one.jpg')) && is_file($thumbOf($scratch.'/by-hand/two.jpg')));
    $served = $client->get('/api/thumbnail', ['path' => $scratch.'/by-hand/one.jpg']);
    check('where the route finds them', $served->ok() && $served->body === file_get_contents($thumbOf($scratch.'/by-hand/one.jpg')));
});

/* ---- the scan ---------------------------------------------------------------------- */

// As the Android app's worker drives it: a slice at a time until done, and a
// busy answer -- which did nothing -- waited out and asked again unchanged.
$runScan = function (Client $who, bool $restart): array {
    $scan = [];
    for ($i = 0; $i < 60; $i++) {
        $scan = $who->post('/api/thumbnails/scan', ['restart' => $restart])->json ?? [];
        if ($scan['busy'] ?? false) { usleep(500_000); continue; }
        $restart = false;
        if ($scan['done'] ?? false) break;
    }
    return $scan;
};

scenario('the scan gives every photo a thumbnail, once', function () use ($base, $accounts, $client, $scratch, $store, $thumbTree, $thumbOf, $photo, $photoPut, $soon, $runScan) {
    // A photo that was in the store before thumbnails were kept: put there
    // by hand, as an older build or a copy straight onto the disk would.
    file_put_contents($store.$scratch.'/pics/from-before.jpg', $photo(640, 480));
    $photoPut($scratch.'/pics/forgotten.jpg', $photo(500, 500));
    $soon(fn() => is_file($thumbOf($scratch.'/pics/forgotten.jpg')));
    @unlink($thumbOf($scratch.'/pics/forgotten.jpg'));
    // And one left behind by a photo that is gone.
    @mkdir($thumbTree.$scratch.'/pics', 0775, true);
    file_put_contents($thumbOf($scratch.'/pics/long-gone.jpg'), 'RIFF....WEBP');

    $viewer = new Client($base);
    $viewer->signIn($accounts['viewer'][0], $accounts['viewer'][1]);
    check('a viewer can read how far it got', $viewer->get('/api/thumbnails/scan')->ok());
    $refused = $viewer->post('/api/thumbnails/scan', ['restart' => true]);
    check('but cannot run it', $refused->status === 403, $refused->describe());
    $anonymous = (new Client($base))->get('/api/thumbnails/scan');
    check('and nobody signed out can read it', $anonymous->status === 401, $anonymous->describe());

    $scan = $runScan($client, true);
    check('it finishes', ($scan['done'] ?? false) === true && ($scan['busy'] ?? true) === false, json_encode($scan));
    check('having looked at everything it found', ($scan['processed'] ?? -1) === ($scan['found'] ?? -2), json_encode($scan));
    check('and made the missing ones', ($scan['generated'] ?? 0) >= 2, json_encode($scan));
    check('what it says adds up', ($scan['generated'] ?? 0) + ($scan['valid'] ?? 0) + ($scan['adopted'] ?? 0)
        + ($scan['failed'] ?? 0) + ($scan['skipped'] ?? 0) === ($scan['processed'] ?? -1), json_encode($scan));
    clearstatcache();
    check('a photo from before has one now', is_file($thumbOf($scratch.'/pics/from-before.jpg')));
    check('a photo that lost its thumbnail has it back', is_file($thumbOf($scratch.'/pics/forgotten.jpg')));
    check('the damaged photo is counted, not retried forever', ($scan['failed'] ?? 0) >= 1, json_encode($scan));
    check('a thumbnail with no photo is cleared away', !is_file($thumbOf($scratch.'/pics/long-gone.jpg')));

    // Every field the Android app reads, with the types it reads them as.
    $read = $client->get('/api/thumbnails/scan');
    $shape = true;
    foreach (['started', 'done', 'busy'] as $k) $shape = $shape && is_bool($read->json[$k] ?? null);
    foreach (['found', 'processed', 'generated', 'valid', 'adopted', 'failed', 'skipped', 'removed', 'format'] as $k) $shape = $shape && is_int($read->json[$k] ?? null);
    foreach (['phase', 'startedAt', 'finishedAt'] as $k) $shape = $shape && is_string($read->json[$k] ?? null);
    check('reading it back gives every field the app reads', $shape, $read->describe());
    check('and the same scan', ($read->json['done'] ?? false) === true && ($read->json['startedAt'] ?? '') === ($scan['startedAt'] ?? '-')
        && ($read->json['generated'] ?? -1) === ($scan['generated'] ?? -2), $read->describe());

    $stands = $client->post('/api/thumbnails/scan', ['restart' => false])->json ?? [];
    check('a finished scan stands: asked again, it does nothing', ($stands['done'] ?? false) === true
        && ($stands['startedAt'] ?? '') === ($scan['startedAt'] ?? '-'), json_encode($stands));
    $twice = $runScan($client, true);
    check('a second scan makes nothing', ($twice['done'] ?? false) === true && ($twice['generated'] ?? -1) === 0, json_encode($twice));
    check('and finds every thumbnail current', ($twice['valid'] ?? 0) >= 2, json_encode($twice));
});

scenario('a running slice holds up nothing but another slice', function () use ($base, $client, $scratch, $store, $photo) {
    // Enough work that a slice is still making thumbnails while we look.
    @mkdir($store.$scratch.'/many', 0775, true);
    for ($i = 0; $i < 120; $i++) file_put_contents($store.$scratch.'/many/p'.$i.'.jpg', $photo(1200, 900, $i));
    $before = (string)($client->get('/api/thumbnails/scan')->json['startedAt'] ?? '');

    // A slice of its own, begun again, on this account's session, left running.
    $multi = curl_multi_init();
    $slice = curl_init($base.'/?route='.rawurlencode('/api/thumbnails/scan'));
    curl_setopt_array($slice, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => '{"restart":true}', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json',
            'Cookie: cloudhub_session='.$client->cookie('cloudhub_session'), 'X-CSRF-Token: '.$client->csrfToken()]]);
    curl_multi_add_handle($multi, $slice);
    // Under way once the new scan has listed the store and is making thumbnails.
    $making = false;
    for ($i = 0; $i < 200 && !$making; $i++) {
        curl_multi_exec($multi, $running);
        usleep(25_000);
        $read = $client->get('/api/thumbnails/scan')->json ?? [];
        $making = ($read['startedAt'] ?? $before) !== $before && ($read['phase'] ?? '') === 'make';
    }
    check('the slice is under way', $making);

    $t = microtime(true);
    $listing = $client->get('/api/files/list', ['path' => $scratch]);
    $waited = microtime(true) - $t;
    check('the account\'s other requests are answered meanwhile', $listing->ok() && $waited < 2.0, sprintf('%.2fs', $waited));
    $second = $client->post('/api/thumbnails/scan', ['restart' => true]);
    check('a second slice is told the scan is busy, and does nothing', ($second->json['busy'] ?? null) === true
        && ($second->json['done'] ?? null) === false, $second->describe());

    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.5); } while ($running);
    $first = json_decode((string)curl_multi_getcontent($slice), true) ?? [];
    curl_multi_remove_handle($multi, $slice);
    curl_close($slice);
    curl_multi_close($multi);
    check('while the first carries on, and says how far it got', ($first['busy'] ?? null) === false
        && ($first['generated'] ?? 0) > 0, json_encode($first));
});

/* ---- tidy up -------------------------------------------------------------------------- */

scenario('tidying the scratch folder', function () use ($client, $scratch, $thumbTree, $runScan) {
    // Finished, so nothing is still writing thumbnails into the folder.
    $runScan($client, false);
    $client->delete('/api/files/delete', ['path' => $scratch]);
    clearstatcache();
    check('deleting the folder took its thumbnails', !file_exists($thumbTree.$scratch));
    foreach ($client->get('/api/trash')->json['entries'] ?? [] as $item) {
        if (str_starts_with((string)($item['originalPath'] ?? ''), $scratch)) $client->post('/api/trash/purge', ['id' => $item['id']]);
    }
});

/* ---- report ----------------------------------------------------------------- */

echo PHP_EOL.$passed.' checks passed, '.count($failures).' failed.'.PHP_EOL;
foreach ($failures as $failure) echo '  FAIL '.$failure.PHP_EOL;
exit($failures ? 1 : 0);
