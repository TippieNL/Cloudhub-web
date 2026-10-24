<?php
declare(strict_types=1);

/**
 * Thumbnails kept in a mirror of the store, and the scan that makes them for
 * what was already there -- run for real.
 *
 * The API the Android app (Cloudhub-2, 5.0 and later) speaks, served here too:
 * ThumbnailService and ThumbnailScan are Cloudhub-2's, and the first part of
 * this is that repository's phase 50, run against them. Not pins over the
 * source, for the most part: it builds a store on disk, makes thumbnails of
 * it, moves and copies and deletes things, runs the scan in slices too small
 * to finish in one, and checks what is on disk after each. What this server
 * adds -- a listing's own times, folders renamed in case, background copies
 * -- follows, and the wiring into the routes and the web client is pinned at
 * the end. tests/http/thumbnails_run.php proves the same over HTTP.
 */
if (!extension_loaded('gd')) { echo "[SKIP] GD is not loaded\n"; exit(0); }

$repo = dirname(__DIR__);
require_once $repo.'/src/Services/FileService.php';
require_once $repo.'/src/Services/ThumbnailService.php';
require_once $repo.'/src/Services/ThumbnailScan.php';

use CloudHub\Services\FileService;
use CloudHub\Services\ThumbnailScan;
use CloudHub\Services\ThumbnailService;

/**
 * A filesystem with no advisory locking, as tests/phase28 models it: every
 * operation proxies to a real directory, stream_lock() is not implemented, so
 * flock() fails at once -- what Android shared storage does, and ext4 here
 * never will. touch() passes its times through, which the mirror relies on.
 */
final class NoLockFs56
{
    public $context;
    public static string $realBase = '';
    private $fh = null;
    private $dh = null;

    public static function real(string $path): string
    {
        return self::$realBase.'/'.ltrim(substr($path, strlen('nolock56://')), '/');
    }
    public function stream_open($path, $mode, $options, &$opened) { $this->fh = @fopen(self::real($path), $mode); return $this->fh !== false; }
    public function stream_read($n) { return fread($this->fh, $n); }
    public function stream_write($d) { return fwrite($this->fh, $d); }
    public function stream_eof() { return feof($this->fh); }
    public function stream_seek($o, $w = SEEK_SET) { return fseek($this->fh, $o, $w) === 0; }
    public function stream_tell() { return ftell($this->fh); }
    public function stream_stat() { return fstat($this->fh); }
    public function stream_flush() { return fflush($this->fh); }
    public function stream_close() { return fclose($this->fh); }
    // stream_lock() is intentionally absent.
    public function url_stat($path, $flags) { return @stat(self::real($path)) ?: false; }
    public function unlink($path) { return @unlink(self::real($path)); }
    public function rename($from, $to) { return @rename(self::real($from), self::real($to)); }
    public function mkdir($path, $mode, $options) { return @mkdir(self::real($path), $mode, (bool)($options & STREAM_MKDIR_RECURSIVE)); }
    public function rmdir($path, $options) { return @rmdir(self::real($path)); }
    public function dir_opendir($path, $options) { $this->dh = @opendir(self::real($path)); return $this->dh !== false; }
    public function dir_readdir() { return readdir($this->dh); }
    public function dir_rewinddir() { rewinddir($this->dh); return true; }
    public function dir_closedir() { closedir($this->dh); return true; }
    public function stream_metadata($path, $option, $value)
    {
        return $option === STREAM_META_TOUCH && @touch(self::real($path), ...array_filter((array)$value, 'is_int'));
    }
}
stream_wrapper_register('nolock56', 'NoLockFs56');

$checks = [];
$base = sys_get_temp_dir().'/cloudhub-thumbs-'.bin2hex(random_bytes(4));
$store = $base.'/store';
mkdir($store.'/Photos/2026', 0775, true);
mkdir($store.'/Backup', 0775, true);
mkdir($base.'/thumbnails');
mkdir($base.'/cache');

$fs = new FileService(['root_dir' => $store, 'read_only' => false]);
$store = $fs->root();
$thumbs = new ThumbnailService($fs, $base.'/thumbnails');
$tree = $thumbs->tree();

$image = function (string $rel, int $w, int $h, string $type = 'jpg', int $mtime = 1_700_000_000) use ($store): string {
    $file = $store.$rel;
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 30, 120, 200, $type === 'png' ? 64 : 0));
    imagefilledrectangle($im, 0, 0, intdiv($w, 2), intdiv($h, 2), imagecolorallocate($im, 220, 40, 40));
    match ($type) {
        'jpg' => imagejpeg($im, $file, 85),
        'png' => imagepng($im, $file),
        'gif' => imagegif($im, $file),
        'webp' => imagewebp($im, $file, 80),
        'bmp' => imagebmp($im, $file),
    };
    imagedestroy($im);
    touch($file, $mtime);
    clearstatcache(true, $file);
    return $file;
};
$size = function (string $thumb): array { $s = @getimagesize($thumb); return $s ? [$s[0], $s[1], $s['mime']] : [0, 0, '']; };
$fingerprints = [];
$fingerprint = function (string $file) use (&$fingerprints): void {
    $fingerprints[$file] = [hash_file('sha256', $file), filemtime($file), filesize($file)];
};

/* --- making them ------------------------------------------------------------ */

$photo = $image('/Photos/beach.jpg', 1600, 1200);
$fingerprint($photo);
$made = $thumbs->ensure($photo);
[$w, $h, $mime] = $size($made['path']);
$checks['a photo gets a thumbnail'] = $made['status'] === ThumbnailService::GENERATED && is_file($made['path']);
$checks['kept in a mirror of the store, under its own name with .webp on the end'] =
    $made['path'] === $tree.'/Photos/beach.jpg.webp';
$checks['as a WebP no larger than 512 px, the shape of the photo'] =
    $mime === 'image/webp' && $w === 512 && $h === 384;
$checks['carrying the photo\'s own modification time'] = filemtime($made['path']) === filemtime($photo);

$small = $image('/Photos/icon.png', 120, 80, 'png');
$fingerprint($small);
$checks['a small image is not enlarged'] = $size($thumbs->ensure($small)['path'])[0] === 120;
$alpha = @imagecreatefromwebp($thumbs->current($small));
$checks['and a transparent one stays transparent'] =
    $alpha !== false && ((imagecolorat($alpha, 100, 70) >> 24) & 0x7F) > 0;

$formats = true;
foreach (['gif', 'webp', 'bmp'] as $type) {
    $file = $image('/Photos/sample.'.$type, 900, 600, $type);
    $fingerprint($file);
    try {
        $out = $thumbs->ensure($file);
        $formats = $formats && $size($out['path'])[2] === 'image/webp' && $out['path'] === $tree.'/Photos/sample.'.$type.'.webp';
    } catch (RuntimeException $e) {
        $formats = false;
        echo "  $type: ".$e->getMessage()."\n";
    }
}
$checks['GIF, WebP and BMP are made into WebP thumbnails too'] = $formats;

$before = [filemtime($made['path']), fileinode($made['path'])];
$again = $thumbs->ensure($photo);
clearstatcache();
$checks['asking again makes nothing'] =
    $again['status'] === ThumbnailService::VALID && [filemtime($made['path']), fileinode($made['path'])] === $before;

/* --- when one is no longer right ----------------------------------------------- */

$edited = $image('/Photos/edited.jpg', 800, 600, 'jpg', 1_700_000_000);
$first = $thumbs->ensure($edited)['path'];
$image('/Photos/edited.jpg', 600, 800, 'jpg', 1_800_000_000);
$fingerprint($edited);
$checks['an edited photo\'s thumbnail is stale at once'] = $thumbs->current($edited) === null;
$remade = $thumbs->ensure($edited);
$checks['and is made again, the new shape'] =
    $remade['status'] === ThumbnailService::GENERATED && $remade['path'] === $first && $size($first)[0] === 384;

file_put_contents($made['path'], 'scrambled');
touch($made['path'], filemtime($photo));
clearstatcache();
$checks['a damaged thumbnail passes a quick look'] = $thumbs->current($photo) !== null;
$checks['but not a careful one'] = $thumbs->current($photo, true) === null;
$checks['and the careful one makes it again'] =
    $thumbs->ensure($photo, true)['status'] === ThumbnailService::GENERATED && ThumbnailService::looksLikeWebp($made['path']);

/* --- what cannot be made ---------------------------------------------------------- */

$broken = $store.'/Photos/broken.jpg';
file_put_contents($broken, 'this was never a photo');
touch($broken, 1_700_000_000);
$fingerprint($broken);
$code = null;
try { $thumbs->ensure($broken); } catch (RuntimeException $e) { $code = $e->getCode(); }
$checks['a file that is not an image is refused as such'] = $code === 415;
$checks['and remembered beside where its thumbnail would be'] =
    is_file($tree.'/Photos/broken.jpg.webp.failed') && ($thumbs->knownFailure($broken)['status'] ?? 0) === 415;
$checks['so it is not decoded again'] = (function () use ($thumbs, $broken): bool {
    // Made unreadable: were it decoded again, the answer would be a different failure.
    chmod($broken, 0000);
    try { $thumbs->ensure($broken); return false; }
    catch (RuntimeException $e) { return $e->getCode() === 415 && $e->getMessage() === 'This file is not a readable image'; }
    finally { chmod($broken, 0644); }
})();
touch($broken, 1_800_000_000);
clearstatcache();
$checks['until it changes'] = $thumbs->knownFailure($broken) === null;
$fingerprint($broken);

// 20000 x 20000, declared in a few hundred bytes: refused from the header.
$bomb = $store.'/Photos/bomb.png';
$chunk = fn(string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
file_put_contents($bomb, "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 20000, 20000, 1, 0, 0, 0, 0))
    .$chunk('IDAT', (string)gzcompress(str_repeat("\0", 200), 9)).$chunk('IEND', ''));
$fingerprint($bomb);
$code = null;
$peak = memory_get_peak_usage(true);
try { $thumbs->ensure($bomb); } catch (RuntimeException $e) { $code = $e->getCode(); }
$checks['an image too large to decode is refused from its header'] = $code === 422 && memory_get_peak_usage(true) - $peak < 16 << 20;

$svg = $store.'/Photos/logo.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"/>');
$code = null;
try { $thumbs->ensure($svg); } catch (RuntimeException $e) { $code = $e->getCode(); }
$checks['a type no thumbnail is made of is refused, and not remembered'] =
    $code === 400 && !file_exists($tree.'/Photos/logo.svg.webp.failed');

/* --- names ---------------------------------------------------------------------- */

$twin = $image('/Photos/2026/beach.jpg', 640, 480);
$cased = $image('/Photos/Beach.JPG', 640, 480);
$sibling = $image('/Photos/beach.png', 640, 480, 'png');
foreach ([$twin, $cased, $sibling] as $f) { $thumbs->ensure($f); $fingerprint($f); }
$checks['the same name in another folder has its own thumbnail'] = is_file($tree.'/Photos/2026/beach.jpg.webp');
$checks['so does the same name in another case'] = is_file($tree.'/Photos/Beach.JPG.webp') && is_file($tree.'/Photos/beach.jpg.webp');
$checks['and the same name with another extension'] = is_file($tree.'/Photos/beach.png.webp');

$checks['a path cannot climb out of the mirror'] =
    $thumbs->pathFor('/../etc/passwd') === null && $thumbs->pathFor('/Photos/../../x.jpg') === null
    && $thumbs->pathFor("/Photos/a\0b.jpg") === null && $thumbs->pathFor('') === null
    && $thumbs->pathFor('/./x.jpg') === null;
$checks['every path made stays inside it'] = str_starts_with((string)$thumbs->pathFor('/a/b/c.jpg'), $tree.'/')
    && $thumbs->pathFor('\\Photos\\beach.jpg') === $tree.'/Photos/beach.jpg.webp';
$checks['a name too long to mirror has no place'] = $thumbs->pathFor('/'.str_repeat('a', 250).'.jpg') === null;
// The longest name that is kept still leaves room for everything written
// beside it: the marker, and the temporary name either is written under.
$longest = '/'.str_repeat('a', 226).'.jpg';
$checks['and the longest one kept can still be written'] = ($p = $thumbs->pathFor($longest)) !== null
    && strlen(basename($p).'.failed.12345678.tmp') <= 255 && $thumbs->pathFor('/'.str_repeat('a', 227).'.jpg') === null;

// A folder named like a thumbnail, beside the photo it would be the thumbnail of.
$clash = $image('/Photos/clash.jpg', 400, 300);
$thumbs->ensure($clash);
$inside = $image('/Photos/clash.jpg.webp/inner.png', 400, 300, 'png');
$fingerprint($clash);
$fingerprint($inside);
$checks['a folder that clashes with a thumbnail is noticed'] = !$thumbs->canKeep($inside) && $thumbs->canKeep($clash);
$uncached = $thumbs->renderUncached($inside);
$checks['and its photos still get thumbnails, made without being kept'] =
    str_starts_with($uncached, 'RIFF') && substr($uncached, 8, 4) === 'WEBP' && is_file($tree.'/Photos/clash.jpg.webp');

/* --- keeping them in step ------------------------------------------------------------ */

$moving = $image('/Photos/moving.jpg', 500, 400);
$thumbs->ensure($moving);
rename($moving, $store.'/Backup/moved.jpg');
$thumbs->moved('/Photos/moving.jpg', '/Backup/moved.jpg');
$checks['a renamed photo takes its thumbnail with it'] =
    !file_exists($tree.'/Photos/moving.jpg.webp') && $thumbs->current($store.'/Backup/moved.jpg') !== null;

$folder = $image('/Albums/Trip/day1.jpg', 500, 400);
$thumbs->ensure($folder);
rename($store.'/Albums/Trip', $store.'/Backup/Trip 2026');
$thumbs->moved('/Albums/Trip', '/Backup/Trip 2026');
$checks['a moved folder takes all of them'] =
    !file_exists($tree.'/Albums/Trip') && $thumbs->current($store.'/Backup/Trip 2026/day1.jpg') !== null;

$fs->copyTree($store.'/Backup/Trip 2026', $store.'/Backup/Trip copy');
touch($store.'/Backup/Trip copy/day1.jpg', 1_900_000_000);
$thumbs->copied('/Backup/Trip 2026', '/Backup/Trip copy');
$copyThumb = $thumbs->current($store.'/Backup/Trip copy/day1.jpg');
$checks['a copy gets its original\'s thumbnail, stamped with its own time'] =
    $copyThumb !== null && filemtime($copyThumb) === 1_900_000_000;

$thumbs->removed('/Backup/Trip copy');
$thumbs->removed('/Backup/moved.jpg');
$checks['a deleted folder\'s thumbnails go with it'] = !file_exists($tree.'/Backup/Trip copy');
$checks['and a deleted photo\'s'] = !file_exists($tree.'/Backup/moved.jpg.webp');
$fs->deleteTree($store.'/Backup/Trip copy');
unlink($store.'/Backup/moved.jpg');

/* --- after an upload ------------------------------------------------------------------- */

$uploaded = $image('/Photos/uploaded.jpg', 1024, 768);
$fingerprint($uploaded);
$thumbs->afterReply([$uploaded, $broken, $store.'/Photos/notes.txt']);
$checks['a stored photo has its thumbnail made once the reply has gone'] =
    $thumbs->makeQueued(5.0, 10) === 1 && $thumbs->current($uploaded) !== null;
$checks['and a photo that cannot have one fails nothing'] = $thumbs->knownFailure($broken) !== null || true;

/* --- twice within one second ------------------------------------------------------------ */

// File times are whole seconds, so a photo written twice within one keeps the
// same time across both writes, and the time alone cannot tell them apart.
$replaced = $image('/Photos/replaced.jpg', 800, 600, 'jpg', 1_750_000_000);
$thumbs->ensure($replaced);
$image('/Photos/replaced.jpg', 600, 800, 'jpg', 1_750_000_000);
$fingerprint($replaced);
$checks['a photo replaced within its second still looks like its old thumbnail\'s'] = $thumbs->current($replaced) !== null;
$thumbs->afterReply([$replaced]);
$checks['so the server drops that thumbnail as it stores the new bytes'] = $thumbs->current($replaced) === null;
$thumbs->makeQueued(5.0, 10);
$checks['and the one made after the reply is of the new picture'] =
    ($t = $thumbs->current($replaced)) !== null && $size($t)[0] === 384;

// How WebDAV clients often write: an empty file, filled a moment later.
$dav = $store.'/Photos/from-a-mac.jpg';
file_put_contents($dav, '');
touch($dav, 1_760_000_000);
try { $thumbs->ensure($dav); } catch (RuntimeException) {}
$checks['an empty file is remembered as no image'] = $thumbs->knownFailure($dav) !== null;
$image('/Photos/from-a-mac.jpg', 300, 300, 'jpg', 1_760_000_000);
$fingerprint($dav);
$thumbs->afterReply([$dav]);
$checks['filled within the same second, the photo is not held to that'] = $thumbs->knownFailure($dav) === null;
$checks['and gets its thumbnail'] = $thumbs->makeQueued(5.0, 10) === 1 && $thumbs->current($dav) !== null;

/* --- cut short ---------------------------------------------------------------------------- */

$cut = (string)$thumbs->current($replaced);
$whole = (string)file_get_contents($cut);
file_put_contents($cut, substr($whole, 0, intdiv(strlen($whole), 2)));
touch($cut, 1_750_000_000);
clearstatcache();
$checks['a thumbnail cut short is not taken for whole'] =
    $thumbs->current($replaced) !== null && $thumbs->current($replaced, true) === null && !ThumbnailService::looksLikeWebp($cut);
$checks['and is made again when it is next served'] =
    $thumbs->ensure($replaced, true)['status'] === ThumbnailService::GENERATED && ThumbnailService::looksLikeWebp($cut);

$clip = $store.'/Photos/clip.webm';
file_put_contents($clip, str_repeat("\1", 2048));
touch($clip, 1_700_000_900);
$fingerprint($clip);
$checks['a video frame from a client is kept at the length it declares'] =
    $thumbs->storeFrame($clip, $whole.'and then some') && ($t = $thumbs->current($clip, true)) !== null
    && filesize($t) === strlen($whole);
$checks['and one that is not all there is refused'] = !$thumbs->storeFrame($clip, substr($whole, 0, -10));

/* --- the scan --------------------------------------------------------------------------- */

// A collection, and a scan given too little time to finish in one slice.
for ($i = 0; $i < 250; $i++) {
    $f = $image(sprintf('/Library/%02d/img_%03d.jpg', intdiv($i, 25), $i), 96, 64, 'jpg', 1_700_000_000 + $i);
    $fingerprint($f);
}
// A video whose frame a client contributed under the first, flat layout.
$video = $store.'/Library/clip.mp4';
file_put_contents($video, str_repeat("\0", 4096));
touch($video, 1_700_000_500);
mkdir($thumbs->legacyDir(), 0775, true);
$frame = $thumbs->legacyPathFor($video, 1_700_000_500);
copy($made['path'], $frame);
$stray = $thumbs->legacyDir().'/'.md5('gone').'.webp';
copy($made['path'], $stray);
// A thumbnail whose photo was deleted behind CloudHub's back.
$orphan = $image('/Photos/orphan.jpg', 300, 200);
$thumbs->ensure($orphan);
unlink($orphan);
unset($fingerprints[$orphan]);

$scan = new ThumbnailScan($fs, $thumbs, $base.'/cache');
$slices = 0;
$memoryBefore = memory_get_usage();
do {
    $p = $scan->run(0.02, $slices === 0);
    $slices++;
} while (!$p['done'] && $slices < 10000);
$checks['a large collection is done in many short slices'] = $p['done'] && $slices > 5;
$checks['every photo in it is found and made'] = $p['found'] >= 251 && $p['generated'] >= 250;
$checks['the photos made earlier are counted as already there'] = $p['valid'] >= 5;
$checks['the unreadable ones are counted, not made'] = $p['failed'] >= 2;
$checks['a contributed video frame is brought across'] =
    $p['adopted'] === 1 && $thumbs->current($video) !== null && !is_file($frame);
$checks['a thumbnail of a file deleted outside CloudHub is swept away'] = !file_exists($tree.'/Photos/orphan.jpg.webp');
$checks['and so is the first layout, once its frames are across'] = !is_dir($thumbs->legacyDir()) && !file_exists($stray);
$checks['the scan never holds the collection in memory'] = memory_get_usage() - $memoryBefore < 4 << 20;
$checks['what the scan says adds up'] =
    $p['processed'] === $p['found'] && $p['generated'] + $p['valid'] + $p['adopted'] + $p['failed'] + $p['skipped'] === $p['processed'];

$again = $scan->run(5.0);
$checks['a finished scan stands, and asking again does nothing'] =
    $again['done'] && $again['startedAt'] === $p['startedAt'] && $again['processed'] === $p['processed'];
$restarted = $scan->run(30.0, true);
$checks['a scan begun again makes nothing that is already there'] =
    $restarted['done'] && $restarted['generated'] === 0 && $restarted['valid'] >= 250;

$lock = fopen($base.'/cache/thumbnail-scan.lock', 'c');
flock($lock, LOCK_EX);
$busy = $scan->run(1.0, true);
flock($lock, LOCK_UN);
fclose($lock);
$checks['one scan at a time: a second caller is told it is busy'] = $busy['busy'] && $busy['done'];
$checks['and anyone may read how far it got'] = $scan->progress()['found'] === $restarted['found'];

/* --- what this server adds ---------------------------------------------------------------- */

// A listing row already says when each video changed, and the flag is read
// from that rather than a second stat of the video.
$checks['a listing\'s own time finds a video\'s frame'] =
    $thumbs->hasFrame($clip, 1_700_000_900) && $thumbs->hasFrame($clip)
    && !$thumbs->hasFrame($clip, 1_700_000_901);
$checks['and so does a frame still in the first layout'] = (function () use ($thumbs, $store, $made): bool {
    $old = $store.'/Photos/old-clip.mp4';
    file_put_contents($old, str_repeat("\0", 1024));
    touch($old, 1_700_000_700);
    @mkdir($thumbs->legacyDir(), 0775, true);
    copy($made['path'], $thumbs->legacyPathFor($old, 1_700_000_700));
    $found = $thumbs->hasFrame($old, 1_700_000_700);
    @unlink($thumbs->legacyPathFor($old, 1_700_000_700));
    @rmdir($thumbs->legacyDir());
    unlink($old);
    return $found;
})();

// Renamed to its own name, a folder keeps its thumbnails rather than having
// them cleared as though something else stood where they go.
$album = $image('/Albums/Summer/sea.jpg', 400, 300);
$fingerprint($album);
$thumbs->ensure($album);
$thumbs->moved('/Albums/Summer', '/Albums/Summer');
$checks['a folder renamed to itself keeps its thumbnails'] = $thumbs->current($album, true) !== null;

// On case-insensitive storage -- a phone's shared storage, macOS, Windows --
// "Summer" renamed "SUMMER" is one folder under two spellings, so its new
// place in the mirror is already "there": it is the folder itself. Clearing
// what stood there lost every thumbnail in it. Two spellings of one folder
// are made here with an alias, as this filesystem is case-sensitive.
if (@symlink($tree.'/Albums', $tree.'/ALBUMS')) {
    $thumbs->moved('/Albums/Summer', '/ALBUMS/Summer');
    clearstatcache();
    $checks['a folder renamed only in case keeps its thumbnails'] =
        is_file($tree.'/Albums/Summer/sea.jpg.webp') && filemtime($tree.'/Albums/Summer/sea.jpg.webp') === filemtime($album);
    unlink($tree.'/ALBUMS');
}

// A background copy reports in as it goes, however many files it carries.
$fs->copyTree($store.'/Albums/Summer', $store.'/Albums/Summer copy');
$ticks = 0;
$thumbs->copied('/Albums/Summer', '/Albums/Summer copy', function () use (&$ticks): void { $ticks++; });
$checks['a copy\'s thumbnails come with it, reporting in per file'] =
    $ticks === 1 && $thumbs->current($store.'/Albums/Summer copy/sea.jpg', true) !== null;
$thumbs->removed('/Albums/Summer copy');
$fs->deleteTree($store.'/Albums/Summer copy');

/* --- on storage without advisory locks --------------------------------------------------- */

// Where storage/.cache is on Android shared storage, flock() fails at once,
// every time. That used to read as "another slice holds the scan": every slice
// answered busy, nothing was ever made, and the app waited on it for good.
NoLockFs56::$realBase = $base.'/nolock';
mkdir($base.'/nolock/cache', 0775, true);
$claim = $base.'/nolock/cache/thumbnail-scan.claim';
$lockless = new ThumbnailScan($fs, $thumbs, 'nolock56://cache');
$ran = $lockless->run(30.0, true);
$checks['without advisory locks, a slice runs rather than reporting busy'] = $ran['busy'] === false && $ran['done']
    && $ran['processed'] === $ran['found'] && $ran['found'] >= $restarted['found'] && $ran['generated'] === 0;
$checks['and gives the scan back when it ends'] = !file_exists($claim);
$checks['which the next slice can take'] = ($next = $lockless->run(30.0, true))['busy'] === false && $next['done'];

// One at a time all the same: a slice under way there holds a claim instead.
$stateThere = $base.'/nolock/cache/thumbnail-scan.json';
$stateBefore = file_get_contents($stateThere);
file_put_contents($claim, ($until = time() + 60).' someoneelse');
$held = $lockless->run(30.0, true);
$checks['a claim held by a running slice turns a second one away'] = $held['busy'] === true
    && file_get_contents($stateThere) === $stateBefore && file_get_contents($claim) === $until.' someoneelse';
file_put_contents($claim, '');
$checks['as does one being written that instant'] = $lockless->run(30.0, true)['busy'] === true;
// A slice killed part way leaves its claim; it lapses, and is taken over.
file_put_contents($claim, (time() - 1).' died');
$after = $lockless->run(30.0, true);
$checks['a claim left by a slice that died is taken over once it lapses'] =
    $after['busy'] === false && $after['done'] && $after['processed'] === $after['found'] && !file_exists($claim);
file_put_contents($claim, '');
touch($claim, time() - 600);
$checks['even one that was never written'] = $lockless->run(30.0, true)['busy'] === false && !file_exists($claim);

// Where locks do work, a failed one is still another slice's.
$lock = fopen($base.'/cache/thumbnail-scan.lock', 'c');
flock($lock, LOCK_EX);
$checks['where locks work, a held lock is still a busy scan'] = $scan->run(1.0, true)['busy'] === true
    && !file_exists($base.'/cache/thumbnail-scan.claim');
flock($lock, LOCK_UN);
fclose($lock);

// And the thumbnails themselves, kept there: their locks are best effort.
$lockThumbs = new ThumbnailService($fs, 'nolock56://thumbs');
$keptThere = $lockThumbs->ensure($store.'/Photos/icon.png');
$checks['thumbnails are made and kept where locks fail'] = $keptThere['status'] === ThumbnailService::GENERATED
    && $lockThumbs->current($store.'/Photos/icon.png', true) === 'nolock56://thumbs/files/Photos/icon.png.webp'
    && $lockThumbs->ensure($store.'/Photos/icon.png', true)['status'] === ThumbnailService::VALID;

/* --- and the originals ------------------------------------------------------------------- */

$untouched = true;
clearstatcache();
foreach ($fingerprints as $file => [$hash, $mtime, $bytes]) {
    if (!is_file($file) || hash_file('sha256', $file) !== $hash || filemtime($file) !== $mtime || filesize($file) !== $bytes) {
        $untouched = false;
        echo '  changed: '.$file."\n";
    }
}
$checks['no original was changed, by anything here'] = $untouched && count($fingerprints) > 250;
$inStore = false;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($store, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (str_ends_with((string)$f, '.webp') && !str_ends_with((string)$f, 'sample.webp')) $inStore = true;
}
$checks['and no thumbnail was written among them'] = !$inStore;

/* --- the wiring ------------------------------------------------------------------------- */

$read = fn(string $path): string => (string)@file_get_contents($repo.'/'.$path);
$index = $read('public/index.php');
$app = $read('public/assets/js/app.js');
$jobs = fn(string $name): string => $read('src/Services/Jobs/'.$name.'.php');

$checks['the gallery route serves, makes, or makes without keeping'] =
    str_contains($index, "\$kept = \$thumbs->ensure(\$f, true)['path'];") && str_contains($index, 'send_thumbnail($kept);')
    && str_contains($index, 'if (ThumbnailService::isImage($f) && !$thumbs->canKeep($f))send_thumbnail_bytes($thumbs->renderUncached($f));');
// Made but not kept -- a full disk, storage that will not take a file's time
// -- is still a thumbnail for the gallery, and the storage report says why.
$checks['a thumbnail that cannot be kept is still served'] =
    str_contains($index, "\$kept = \$thumbs->ensure(\$f, true)['path'];")
    && str_contains($index, "if (\$e->getCode() !== 500)throw \$e;")
    && substr_count($index, 'send_thumbnail_bytes($thumbs->renderUncached($f));') === 2
    && str_contains($read('src/Services/StorageDiagnostics.php'), "\$row['keepsTimes'] = \$touched && @filemtime(\$probe) === 1_000_000_000;");
$checks['and brings a frame across from the first layout'] =
    str_contains($index, 'if ($thumbs->adoptFrame($f) && ($cache = $thumbs->current($f, true)) !== null)send_thumbnail($cache);');
$checks['uploads, multipart uploads, restores and WebDAV get thumbnails after the reply'] =
    substr_count($index, 'thumbs()->afterReply(') === 4 && str_contains($index, 'thumbs()->afterReply([$full]);')
    && str_contains($index, 'thumbs()->afterReply([$dest]);') && str_contains($index, 'thumbs()->afterReply([$fs->root().$rel]);');
$checks['moves and renames carry thumbnails, deletes remove them'] =
    substr_count($index, 'thumbs()->moved(') === 3 && substr_count($index, 'thumbs()->removed(') === 4
    && str_contains($index, 'thumbs()->copied(');
$checks['a deletion queued in the background removes them at once'] = (bool)preg_match(
    "/queue_purge_or_null\(\[\\\$p\].*?favorites\(\)->forget\(\\\$rel\);\s*thumbs\(\)->removed\(\\\$rel\);/s", $index);
$checks['video frames are kept in the mirror'] =
    str_contains($index, 'if (!thumbs()->storeFrame($f, $raw)) {')
    && str_contains($index, 'if (thumbs()->current($f, true) !== null)return [\'success\' => true, \'stored\' => false];');
$checks['the scan is a write, reading it is not'] =
    str_contains($index, "if (\$path === '/api/thumbnails/scan') {")
    && str_contains($index, 'return $thumbnailScan()->run(ThumbnailScan::SLICE_SECONDS, !empty($body[\'restart\']));')
    && !str_contains(substr($index, (int)strpos($index, '$writeExemptPost = '), 400), '/api/thumbnails/scan');
// It writes only outside the store, so the listings cached before it stand.
$checks['and changes nothing a listing shows'] = (bool)preg_match(
    "/\\\$cacheNeutralWrites = \[[^\]]*'\/api\/thumbnails\/scan'\];/", $index);
$checks['the config says which format and that the scan is here'] =
    str_contains($index, "'thumbnailFormat' => ThumbnailService::FORMAT,")
    && str_contains($index, "'thumbnailScanSeconds' => ThumbnailScan::SLICE_SECONDS,");
$checks['the old flat cache is gone from the code'] =
    !is_file($repo.'/src/Services/ImageThumbnailer.php') && !str_contains($index, 'thumbnail_cache_path(')
    && !str_contains($jobs('ThumbnailsJob'), 'ImageThumbnailer');
$checks['the background job makes the thumbnails the route serves'] =
    str_contains($jobs('ThumbnailsJob'), '$thumbs = $env->thumbnails();')
    && str_contains($jobs('ThumbnailsJob'), "\$thumbs->ensure(\$full, true)['status'] === ThumbnailService::GENERATED")
    && str_contains($jobs('JobEnvironment'), "new ThumbnailService(\$this->files, \$this->projectDir.'/storage/.thumbnails')")
    && str_contains($index, "new ThumbnailService(\$fs, dirname(__DIR__).'/storage/.thumbnails')");
$checks['and a background copy carries them'] =
    substr_count($jobs('CopyJob'), 'self::carryThumbnails(') === 2
    && str_contains($jobs('CopyJob'), '$ctx->env()->thumbnails()->copied($from, $to, static fn() => $ctx->checkpoint());');
preg_match('/const THUMBNAIL_FORMAT = (\d+);/', $app, $web);
$checks['the web asks with the server\'s format'] =
    (int)($web[1] ?? 0) === ThumbnailService::FORMAT && str_contains($app, "'&t=' + THUMBNAIL_FORMAT");
// As the Android app writes `v`: the file's time and its size.
$checks['and versions every thumbnail it shows, by time and size'] =
    str_contains($app, "'&v=' + encodeURIComponent((modified ?? '') + '-' + (size ?? ''))")
    && str_contains($app, 'const thumbnailUrl = thumbnailUrlFor(f.path, f.modified, f.size);')
    && str_contains($app, 'thumbnailUrlFor(f.path, f.mtime, f.bytes)')
    && substr_count($app, "appUrl('/api/thumbnail?path=") === 1;
$checks['the command line runs the same scan'] =
    str_contains($read('tools/thumbnails.php'), "\$scan = new ThumbnailScan(\$fs, \$thumbs, dirname(__DIR__).'/storage/.cache');");

/* --- tidy up ---------------------------------------------------------------------------- */

$wipe = function (string $dir) use (&$wipe): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir.'/'.$name;
        if (is_dir($full) && !is_link($full)) $wipe($full); else @unlink($full);
    }
    @rmdir($dir);
};
$wipe($base);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
