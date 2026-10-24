<?php
declare(strict_types=1);
namespace CloudHub\Services;

use RuntimeException;

/**
 * Thumbnails for the files in the store: made once, kept in a mirror of the
 * store's folders, and kept in step with the files they belong to.
 *
 * Where they live. `storage/.thumbnails/files` mirrors the store, so the
 * thumbnail of `/Photos/2026/beach.jpg` is
 * `storage/.thumbnails/files/Photos/2026/beach.jpg.webp`: the same folders,
 * the same name, `.webp` on the end. Appending rather than replacing the
 * extension keeps the mapping one to one -- `beach.jpg` and `beach.png` side
 * by side get `beach.jpg.webp` and `beach.png.webp`, never one file between
 * them. Originals are only ever read.
 *
 * When one is current. A thumbnail carries its original's modification time
 * as its own, set before it is moved into place, so "is this the thumbnail of
 * these bytes?" is two stats and a comparison. An edited or replaced file has a
 * new time and its thumbnail is stale at once, with nothing to clean up first.
 * File times are whole seconds, though, and a file written twice within one --
 * a WebDAV client creating it empty and then filling it, a photo replaced
 * straight after it arrived -- keeps one time across both writes. So whenever
 * the server writes a file itself it also drops what is held for that file,
 * there and then (see afterReply()). Only a file changed twice within one
 * second by something other than CloudHub can keep the first one's thumbnail.
 *
 * When one cannot be made. A file that is not a readable image, or is too large
 * to decode safely, is remembered as such beside where its thumbnail would be
 * (`beach.jpg.webp.failed`, carrying the same modification time), so neither a
 * gallery nor the background scan decodes it again until the file changes.
 *
 * The one name that cannot be mirrored: a folder called `beach.jpg.webp` next
 * to `beach.jpg` wants the same place in the mirror as that photo's thumbnail.
 * Whichever arrives second is not kept -- its thumbnail is still made and
 * served, just made again on the next request -- rather than either one being
 * overwritten.
 */
final class ThumbnailService
{
    /**
     * The thumbnail's format, in the URL every client asks with.
     *
     * Raised when the size or encoding changes, so a cache that holds a
     * thumbnail for a year -- the browser's, the app's -- asks again rather
     * than showing the old one for the rest of that year. 1 was 300 px.
     */
    public const FORMAT = 2;

    /**
     * The longest side, in pixels.
     *
     * The Android grid crops photos into square tiles about 490 px wide on an
     * ordinary phone. A 300 px box gave a 4:3 photo 225 px of height to fill
     * that, more than twice enlarged and visibly soft; 512 gives 384, close to
     * what is drawn, for about two and a half times the bytes.
     */
    public const MAX_EDGE = 512;

    public const QUALITY = 80;

    /**
     * The most pixels a thumbnail will decode.
     *
     * GD holds a decoded image at up to four bytes a pixel whatever the file's
     * size, so a 285 KB PNG declaring 10000x10000 pixels took one thumbnail
     * request to 704 MB -- measured. memory_limit is no defence: Debian and
     * Ubuntu build PHP against the system libgd, whose allocations PHP never
     * counts, and `php -S` runs with no limit at all. 50 megapixels still
     * covers a phone's full-resolution photo.
     */
    public const MAX_SOURCE_PIXELS = 50_000_000;

    /** What a stored thumbnail may weigh before it is taken for something else. */
    public const MAX_THUMBNAIL_BYTES = 4 * 1024 * 1024;

    /** Images a thumbnail is made from here -- where this server's GD can read them. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];

    /** Videos: the server has no decoder, so their frames come from clients. */
    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v', 'avi', 'mkv', 'mpeg', 'mpg', '3gp', '3g2', 'ts', 'm2ts', 'mts'];

    public const VALID = 'valid';
    public const GENERATED = 'generated';
    public const ADOPTED = 'adopted';

    /** The suffix of the marker that says a thumbnail could not be made. */
    private const FAILED = '.failed';

    /** What a thumbnail or marker is first written under: ".<8 hex>.tmp" on the end. */
    private const TEMP_SUFFIX_LENGTH = 13;

    private const LOCK_SLOTS = 64;

    private string $base;
    private string $tree;
    private string $legacy;

    /** Files waiting for a thumbnail once the reply has gone. */
    private array $queued = [];
    private bool $hooked = false;

    /**
     * @param string $base the thumbnail directory, `storage/.thumbnails`
     */
    public function __construct(private FileService $fs, string $base)
    {
        $this->base = rtrim(str_replace('\\', '/', $base), '/');
        $this->tree = $this->base.'/files';
        // The first layout: one flat folder of md5(absolute path:mtime).webp.
        // Read only to keep the video frames clients contributed to it.
        $this->legacy = $this->base.'/images';
    }

    public function tree(): string { return $this->tree; }

    public function legacyDir(): string { return $this->legacy; }

    /* ---- what is a thumbnail of what ------------------------------------ */

    public static function extensionOf(string $path): string
    {
        return strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    }

    public static function isImage(string $path): bool
    {
        return in_array(self::extensionOf($path), self::IMAGE_EXTENSIONS, true);
    }

    public static function isVideo(string $path): bool
    {
        return in_array(self::extensionOf($path), self::VIDEO_EXTENSIONS, true);
    }

    /** Whether this server's GD reads files with this extension. */
    public static function canDecode(string $extension): bool
    {
        if (!extension_loaded('gd')) return false;
        static $info = null;
        $info ??= gd_info();
        return match ($extension) {
            'jpg', 'jpeg' => !empty($info['JPEG Support']),
            'png' => !empty($info['PNG Support']),
            'gif' => !empty($info['GIF Read Support']),
            'webp' => !empty($info['WebP Support']) && function_exists('imagecreatefromwebp'),
            'bmp' => !empty($info['BMP Support']) && function_exists('imagecreatefrombmp'),
            'avif' => !empty($info['AVIF Support']) && function_exists('imagecreatefromavif'),
            default => false,
        };
    }

    /**
     * Where the thumbnail of a stored file belongs, from its path in the store
     * ("/Photos/beach.jpg"), or null for a path no thumbnail can be kept for.
     *
     * Made from the path's own segments and checked to stay inside the tree,
     * so nothing a client can name -- "..", a NUL, an absolute path -- can
     * point a thumbnail anywhere else. FileService has already refused those;
     * this does not rely on it.
     */
    public function pathFor(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, "\0")) return null;
        $parts = [];
        foreach (explode('/', ltrim($relative, '/')) as $part) {
            if ($part === '') continue;
            if ($part === '.' || $part === '..' || preg_match('/[\x00-\x1F\x7F]/', $part)) return null;
            $parts[] = $part;
        }
        if (!$parts) return null;
        $name = array_pop($parts).'.webp';
        // The file system's own limit on one name, with room for ".failed" and
        // for the temporary name each is written under first.
        if (strlen($name) + strlen(self::FAILED) + self::TEMP_SUFFIX_LENGTH > 255) return null;
        $path = $this->tree.'/'.($parts ? implode('/', $parts).'/' : '').$name;
        return str_starts_with($path, $this->tree.'/') ? $path : null;
    }

    /** The same, for an absolute path inside the store. */
    public function pathForFile(string $file): ?string
    {
        try {
            return $this->pathFor($this->fs->relative($file));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether this file's thumbnail can be kept in the mirror: it has a name
     * that can be mirrored, and nothing in the mirror stands in its way -- no
     * folder where the thumbnail would go, no thumbnail where a folder of the
     * mirror would.
     */
    public function canKeep(string $file): bool
    {
        $thumb = $this->pathForFile($file);
        if ($thumb === null || is_dir($thumb)) return false;
        // The nearest part of the path that exists decides: a folder, and
        // everything above it is one too; a file, and the way is blocked.
        for ($dir = dirname($thumb); strlen($dir) > strlen($this->tree); $dir = dirname($dir)) {
            if (file_exists($dir)) return is_dir($dir);
        }
        return true;
    }

    /** Where the first layout kept this file's thumbnail. */
    public function legacyPathFor(string $file, int $mtime): string
    {
        return $this->legacy.'/'.md5($file.':'.$mtime).'.webp';
    }

    /**
     * The thumbnail of $file, if one is current: there, and made from the
     * bytes the file holds now. $verify also checks it is a whole WebP --
     * everything that serves, copies or scans one asks for that, so a file
     * emptied or cut short by a crash is made again rather than sent; only
     * the listing's "is there a frame for this video?" goes without.
     */
    public function current(string $file, bool $verify = false): ?string
    {
        $thumb = $this->pathForFile($file);
        if ($thumb === null) return null;
        $mtime = @filemtime($file);
        if ($mtime === false) return null;
        clearstatcache(true, $thumb);
        if (!is_file($thumb) || @filemtime($thumb) !== $mtime) return null;
        if ($verify && !self::looksLikeWebp($thumb)) return null;
        return $thumb;
    }

    /**
     * Whether a frame is waiting for this video, here or in the first layout.
     *
     * $mtime is the video's time when the caller already has it: a listing
     * row says when the file changed, and asking the disk again cost a stat
     * per video, which on FUSE storage is what a cached listing saves.
     */
    public function hasFrame(string $file, ?int $mtime = null): bool
    {
        $mtime ??= @filemtime($file);
        if ($mtime === false) return false;
        $thumb = $this->pathForFile($file);
        if ($thumb !== null) {
            clearstatcache(true, $thumb);
            if (is_file($thumb) && @filemtime($thumb) === $mtime) return true;
        }
        return is_file($this->legacyPathFor($file, $mtime));
    }

    /**
     * Whether $file is a whole WebP: the signature, and a RIFF length that is
     * the file's own -- which a file cut short, or emptied by a crash before
     * its bytes reached the disk, does not have.
     */
    public static function looksLikeWebp(string $file): bool
    {
        $size = @filesize($file);
        if ($size === false || $size < 20 || $size > self::MAX_THUMBNAIL_BYTES) return false;
        $h = @fopen($file, 'rb');
        if ($h === false) return false;
        $head = (string)fread($h, 12);
        fclose($h);
        return self::riffLength($head) === $size;
    }

    /**
     * The length a WebP's RIFF header declares for the whole file, or null when
     * $head does not start like a WebP.
     */
    private static function riffLength(string $head): ?int
    {
        if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') return null;
        return (int)unpack('V', substr($head, 4, 4))[1] + 8;
    }

    /**
     * Why this file's thumbnail could not be made, while that is still true:
     * the marker is only read while it carries the file's modification time.
     *
     * @return array{status:int,message:string}|null
     */
    public function knownFailure(string $file): ?array
    {
        $thumb = $this->pathForFile($file);
        $mtime = @filemtime($file);
        if ($thumb === null || $mtime === false) return null;
        $marker = $thumb.self::FAILED;
        clearstatcache(true, $marker);
        if (!is_file($marker) || @filemtime($marker) !== $mtime) return null;
        $data = json_decode((string)@file_get_contents($marker), true);
        return [
            'status' => (int)($data['status'] ?? 415),
            'message' => (string)($data['message'] ?? 'This file is not a readable image'),
        ];
    }

    /* ---- making one ------------------------------------------------------ */

    /**
     * Make the thumbnail of an image, unless a current one is already there.
     *
     * Returns which: VALID, or GENERATED. Throws a RuntimeException whose code
     * is the HTTP status to answer with when it cannot be made -- and records
     * the failures that are the file's own (not an image, too large to decode)
     * so the next caller is answered without decoding it again. Failures that
     * are the server's (no GD, a full disk) are not recorded: they can pass.
     *
     * @return array{status:string,path:string}
     */
    public function ensure(string $file, bool $verify = false): array
    {
        $existing = $this->current($file, $verify);
        if ($existing !== null) return ['status' => self::VALID, 'path' => $existing];

        $ext = self::extensionOf($file);
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('Not a supported thumbnail type', 400);
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required for image thumbnails', 503);
        }
        if (!self::canDecode($ext)) {
            throw new RuntimeException('This server cannot read .'.$ext.' images', 415);
        }
        $failure = $this->knownFailure($file);
        if ($failure !== null) throw new RuntimeException($failure['message'], $failure['status']);

        $thumb = $this->pathForFile($file);
        if ($thumb === null) throw new RuntimeException('No thumbnail can be kept for this name', 422);

        // One maker per thumbnail: a gallery, a second device and the scan can
        // all ask for the same new photo at once, and only one need decode it.
        return $this->locked($thumb, function () use ($file, $thumb, $verify): array {
            $existing = $this->current($file, $verify);
            if ($existing !== null) return ['status' => self::VALID, 'path' => $existing];

            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            if ($mtime === false) throw new RuntimeException('File not found', 404);
            $bytes = $this->render($file, function (int $status, string $message) use ($thumb, $mtime): never {
                $this->remember($thumb, $mtime, $status, $message);
                throw new RuntimeException($message, $status);
            });
            if (!$this->put($thumb, $bytes, $mtime)) {
                throw new RuntimeException('Failed to store image thumbnail', 500);
            }
            @unlink($thumb.self::FAILED);
            return ['status' => self::GENERATED, 'path' => $thumb];
        });
    }

    /**
     * The thumbnail of an image as WebP bytes, made without keeping it -- for
     * the one name that cannot be mirrored (see the class comment).
     */
    public function renderUncached(string $file): string
    {
        return $this->render($file, function (int $status, string $message): never {
            throw new RuntimeException($message, $status);
        });
    }

    /**
     * Decode, shrink and encode. $refuse is called with the status and message
     * for a file that is not usable as an image, and must not return.
     */
    private function render(string $file, callable $refuse): string
    {
        $ext = self::extensionOf($file);
        if (!self::canDecode($ext)) throw new RuntimeException('This server cannot read .'.$ext.' images', 415);

        // Measured from the header before anything is decoded: see MAX_SOURCE_PIXELS.
        $dimensions = @getimagesize($file);
        if ($dimensions === false) $refuse(415, 'This file is not a readable image');
        if (!self::sourceFits((int)$dimensions[0], (int)$dimensions[1])) {
            $refuse(422, 'This image is too large to make a thumbnail of');
        }

        $source = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($file),
            'png' => @imagecreatefrompng($file),
            'gif' => @imagecreatefromgif($file),
            'webp' => @imagecreatefromwebp($file),
            'bmp' => @imagecreatefrombmp($file),
            'avif' => @imagecreatefromavif($file),
            default => false,
        };
        // A file can carry an image extension and not be an image -- a
        // truncated download, a rename, or something that was never a photo.
        // That is a fact about the file, not a server fault.
        if (!$source) $refuse(415, 'This file is not a readable image');

        $w = imagesx($source);
        $h = imagesy($source);
        if ($w < 1 || $h < 1) {
            imagedestroy($source);
            $refuse(415, 'This file is not a readable image');
        }

        // Shrunk to fit, never enlarged: a small image is its own thumbnail.
        $scale = min(self::MAX_EDGE / $w, self::MAX_EDGE / $h, 1);
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $im = imagecreatetruecolor($nw, $nh);
        // A truecolor canvas starts opaque black with save-alpha off, so a
        // transparent PNG or GIF resampled onto it came out with black behind
        // whatever should have shown through. Blending is off so the source
        // alpha is copied rather than composited against that black.
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagecopyresampled($im, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($source);
        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('exif_read_data')) {
            $im = self::orient($im, (int)(@exif_read_data($file)['Orientation'] ?? 1));
        }

        ob_start();
        $ok = @imagewebp($im, null, self::QUALITY);
        $bytes = (string)ob_get_clean();
        imagedestroy($im);
        if (!$ok || $bytes === '') throw new RuntimeException('Failed to generate thumbnail', 500);
        return $bytes;
    }

    /** Whether an image of these dimensions may be decoded for a thumbnail. */
    public static function sourceFits(int $w, int $h): bool
    {
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_SOURCE_PIXELS) return false;
        // Where GD's memory is PHP's own (the bundled build), stay inside the
        // limit rather than die at it with a fatal error no handler can answer.
        $limit = (int)ini_parse_quantity((string)ini_get('memory_limit'));
        if (defined('GD_BUNDLED') && GD_BUNDLED && $limit > 0) {
            return $w * $h * 5 < $limit - memory_get_usage(true);
        }
        return true;
    }

    /**
     * Turn a thumbnail the way its photo's EXIF orientation says (1-8).
     *
     * Phones store a photo as the sensor saw it and record how it was held;
     * browsers apply that to the original, but GD does not and the WebP
     * written here carries no EXIF, so portrait photos lay on their side in
     * the grid while opening upright. Applied to the small thumbnail, not the
     * full photo, so the turn costs nothing worth measuring.
     */
    public static function orient(\GdImage $im, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
        // imagerotate() turns anticlockwise.
        $angle = match ($orientation) { 3, 4 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
        if ($angle === 0) return $im;
        $turned = imagerotate($im, $angle, 0);
        if ($turned === false) return $im;
        imagedestroy($im);
        return $turned;
    }

    /**
     * Keep a video's frame, decoded and sent by a client: the server has no
     * video decoder of its own. False when this name cannot be mirrored.
     */
    public function storeFrame(string $file, string $webp): bool
    {
        $thumb = $this->pathForFile($file);
        $mtime = @filemtime($file);
        // Kept at the length its header declares, so it passes for whole when
        // it is served: anything after that is not part of the image, and a
        // frame shorter than that is not all there.
        $length = self::riffLength($webp);
        if ($thumb === null || $mtime === false || $length === null || $length > strlen($webp)) return false;
        $webp = substr($webp, 0, $length);
        return $this->locked($thumb, fn(): bool => $this->put($thumb, $webp, $mtime));
    }

    /**
     * Move a video's frame from the first layout into this one. Images are
     * not brought across: those were 300 px, and are made again at this size.
     */
    public function adoptFrame(string $file): bool
    {
        $mtime = @filemtime($file);
        $thumb = $this->pathForFile($file);
        if ($mtime === false || $thumb === null) return false;
        $old = $this->legacyPathFor($file, $mtime);
        if (!is_file($old) || !self::looksLikeWebp($old)) return false;
        return $this->locked($thumb, function () use ($file, $old, $thumb, $mtime): bool {
            if ($this->current($file, true) !== null) return true;
            if (!$this->prepareDir(dirname($thumb))) return false;
            if (!@rename($old, $thumb) && !(@copy($old, $thumb) && @unlink($old))) return false;
            @touch($thumb, $mtime);
            clearstatcache(true, $thumb);
            return true;
        });
    }

    /* ---- keeping them in step -------------------------------------------- */

    /**
     * A file or folder renamed or moved within the store: its thumbnails go
     * with it, so nothing is made again for a rename.
     */
    public function moved(string $fromRelative, string $toRelative): void
    {
        $from = $this->pathFor($fromRelative);
        $to = $this->pathFor($toRelative);
        if ($from === null || $from === $to) return;
        $fromDir = substr($from, 0, -5);
        $toDir = $to === null ? null : substr($to, 0, -5);

        foreach ([$from, $from.self::FAILED] as $i => $old) {
            if (!is_file($old)) continue;
            $new = $to === null ? null : ($i === 0 ? $to : $to.self::FAILED);
            if ($new === null || !$this->prepareDir(dirname($new)) || !@rename($old, $new)) @unlink($old);
        }
        if (is_dir($fromDir)) {
            // Nothing should be at the folder's new place in the mirror -- a
            // move never lands on something that is already there -- so what
            // is found there is left over and makes way. Unless it is this
            // folder: renamed only in case on case-insensitive storage, such
            // as a phone's, "Album" is "album", and clearing it lost them all.
            if ($toDir !== null && file_exists($toDir) && !self::sameDir($fromDir, $toDir)) $this->removeTree($toDir);
            if ($toDir === null || !$this->prepareDir(dirname($toDir)) || !@rename($fromDir, $toDir)) {
                $this->removeTree($fromDir);
            }
        }
    }

    /**
     * Files copied within the store: each copy gets its original's thumbnail,
     * restamped with the copy's own modification time -- the copy is new bytes
     * on disk, but the same picture.
     *
     * @param callable(): void|null $tick called per file, so a background copy
     *        of a large folder keeps reporting in while this runs
     */
    public function copied(string $fromRelative, string $toRelative, ?callable $tick = null): void
    {
        $root = $this->fs->root();
        $target = $root.($toRelative === '/' ? '' : $toRelative);
        foreach ($this->fs->copiedFiles($target) as $copy) {
            if ($tick !== null) $tick();
            if (!self::isImage($copy) && !self::isVideo($copy)) continue;
            $suffix = substr($copy, strlen($target));
            $original = $root.($fromRelative === '/' ? '' : $fromRelative).$suffix;
            $thumb = $this->current($original, true);
            $mtime = @filemtime($copy);
            $dest = $this->pathForFile($copy);
            if ($thumb === null || $mtime === false || $dest === null) continue;
            $this->locked($dest, function () use ($thumb, $dest, $mtime): bool {
                $bytes = @file_get_contents($thumb);
                return is_string($bytes) && $this->put($dest, $bytes, $mtime);
            });
        }
    }

    /** A file or folder gone from the store: its thumbnails go too. */
    public function removed(string $relative): void
    {
        $thumb = $this->pathFor($relative);
        if ($thumb === null) return;
        @unlink($thumb);
        @unlink($thumb.self::FAILED);
        $dir = substr($thumb, 0, -5);
        if (is_dir($dir)) $this->removeTree($dir);
    }

    /**
     * Make thumbnails for files just stored -- after the reply has gone where
     * the server allows that, so an upload is never slowed or failed by one.
     *
     * Whatever is held for those files is dropped first, before the reply:
     * it was made from the bytes they held before, and a file replaced within
     * the second it arrived keeps the same time (see the class comment). That
     * is done holding the thumbnail's lock, so a thumbnail being made from the
     * old bytes at that moment is finished, and then dropped, rather than
     * stored after this and taken for the new one's.
     *
     * Under php-fpm (and LiteSpeed) the reply is finished first and the work
     * bounded only loosely. Elsewhere (mod_php, `php -S`) the client is still
     * waiting, so only a handful are made in a couple of seconds; the scan and
     * the first look at the folder make the rest. A failure is logged and
     * nothing else: the file is stored whatever happens to its thumbnail.
     *
     * @param list<string> $files absolute paths in the store
     */
    public function afterReply(array $files): void
    {
        foreach ($files as $file) {
            if (!is_string($file) || (!self::isImage($file) && !self::isVideo($file))) continue;
            // Taken even when there is nothing yet: what a maker holding it is
            // about to store is exactly what has to go.
            $thumb = $this->pathForFile($file);
            if ($thumb !== null) {
                $this->locked($thumb, function () use ($thumb): void {
                    @unlink($thumb);
                    @unlink($thumb.self::FAILED);
                    clearstatcache(true, $thumb);
                });
            }
            if (self::isImage($file)) $this->queued[$file] = true;
        }
        if ($this->hooked || !$this->queued) return;
        $this->hooked = true;
        register_shutdown_function(function (): void {
            $detached = false;
            if (function_exists('fastcgi_finish_request')) {
                ignore_user_abort(true);
                $detached = @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                ignore_user_abort(true);
                $detached = @litespeed_finish_request();
            }
            $this->makeQueued($detached ? 30.0 : 2.0, $detached ? 500 : 5);
        });
    }

    /** What afterReply() queued, within a budget. Public for the tests. */
    public function makeQueued(float $seconds, int $limit): int
    {
        $deadline = microtime(true) + $seconds;
        $made = 0;
        foreach (array_keys($this->queued) as $file) {
            unset($this->queued[$file]);
            if ($made >= $limit || microtime(true) >= $deadline) break;
            try {
                if (is_file($file) && $this->ensure($file)['status'] === self::GENERATED) $made++;
            } catch (\Throwable $e) {
                error_log('[thumbnails] '.$this->describe($file).': '.$e->getMessage());
            }
        }
        $this->queued = [];
        return $made;
    }

    /* ---- the mirror, from the other side ---------------------------------- */

    /** The store path a thumbnail or marker belongs to, or null for anything else. */
    public function sourceOf(string $thumbOrMarker): ?string
    {
        if (!str_starts_with($thumbOrMarker, $this->tree.'/')) return null;
        $rel = substr($thumbOrMarker, strlen($this->tree));
        if (str_ends_with($rel, '.webp'.self::FAILED)) $rel = substr($rel, 0, -strlen('.webp'.self::FAILED));
        elseif (str_ends_with($rel, '.webp')) $rel = substr($rel, 0, -5);
        else return null;
        return $rel === '' || $rel === '/' ? null : $rel;
    }

    /* ---- plumbing ---------------------------------------------------------- */

    /**
     * Write through a temporary file stamped with the original's time, then
     * rename: a reader sees the old thumbnail or the new one, never half of
     * one, and never a new one with the old time.
     */
    private function put(string $thumb, string $bytes, int $mtime): bool
    {
        if (!$this->prepareDir(dirname($thumb))) return false;
        if (is_dir($thumb)) return false;
        $tmp = self::temporary($thumb);
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@touch($tmp, $mtime) || !@rename($tmp, $thumb)) {
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $thumb);
        return true;
    }

    /** A name beside $path to write it under first. See TEMP_SUFFIX_LENGTH. */
    private static function temporary(string $path): string
    {
        return $path.'.'.bin2hex(random_bytes(4)).'.tmp';
    }

    private function remember(string $thumb, int $mtime, int $status, string $message): void
    {
        $marker = $thumb.self::FAILED;
        if (!$this->prepareDir(dirname($marker))) return;
        $tmp = self::temporary($marker);
        $body = json_encode(['status' => $status, 'message' => $message, 'at' => gmdate('c')], JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, (string)$body) === false || !@touch($tmp, $mtime) || !@rename($tmp, $marker)) @unlink($tmp);
        clearstatcache(true, $marker);
    }

    /**
     * Make a folder of the mirror, refusing to make one where a thumbnail
     * already is -- the one collision the mirror can have.
     */
    private function prepareDir(string $dir): bool
    {
        if (!str_starts_with($dir.'/', $this->tree.'/')) return false;
        if (is_dir($dir)) return true;
        if (file_exists($dir)) return false;
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }

    /** Whether two spellings reach one directory -- the same device and inode. */
    private static function sameDir(string $a, string $b): bool
    {
        $x = @stat($a);
        $y = @stat($b);
        return $x !== false && $y !== false && (int)$x['ino'] !== 0 && $x['dev'] === $y['dev'] && $x['ino'] === $y['ino'];
    }

    /** Delete a folder of the mirror, never anything outside it. */
    private function removeTree(string $dir): void
    {
        if (!str_starts_with($dir, $this->tree.'/') || is_link($dir)) return;
        if (!is_dir($dir)) { @unlink($dir); return; }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $this->removeTree($dir.'/'.$name);
        }
        @rmdir($dir);
    }

    /**
     * Run $work holding the lock for one thumbnail. Locks are a fixed set of
     * files picked by hash, rather than one per thumbnail, so there is nothing
     * to clean up and no lock file can be deleted while someone waits on it.
     */
    private function locked(string $thumb, callable $work): mixed
    {
        $dir = $this->base.'/locks';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $h = @fopen($dir.'/'.(crc32($thumb) % self::LOCK_SLOTS).'.lock', 'c');
        if ($h === false) return $work();
        try {
            flock($h, LOCK_EX);
            return $work();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function describe(string $file): string
    {
        try { return $this->fs->relative($file); } catch (\Throwable) { return basename($file); }
    }
}
