<?php
declare(strict_types=1);
namespace CloudHub\Services;

use RuntimeException;

/**
 * Image thumbnails: where they are cached, and how one is made.
 *
 * Moved out of public/index.php so the background thumbnail job, which never
 * loads the front controller, writes exactly the entries GET /api/thumbnail
 * serves -- same key, same size, same orientation, same guard against
 * decompression bombs.
 */
final class ImageThumbnailer
{
    /** Extensions GD turns into a thumbnail. Video frames come from browsers. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * The most pixels a thumbnail will decode.
     *
     * GD holds a decoded image at up to four bytes a pixel whatever the file's
     * size, so a 285 KB PNG declaring 10000x10000 pixels took one thumbnail
     * request to 704 MB -- measured -- and nothing caches the failure, so every
     * listing of that folder asked again. memory_limit is no defence: Debian
     * and Ubuntu build PHP against the system libgd, whose allocations PHP
     * never counts, and `php -S` runs with no limit at all. 50 megapixels
     * still covers a phone's full-resolution photo.
     */
    public const MAX_SOURCE_PIXELS = 50_000_000;

    /**
     * Where a file's cached thumbnail lives, creating the cache directory.
     *
     * Keyed by absolute path and modification time, so editing or replacing a
     * file yields a new key and the stale thumbnail is simply never read again.
     */
    public static function cachePath(string $projectDir, string $file, ?int $mtime = null): ?string
    {
        $mtime ??= @filemtime($file);
        if ($mtime === false) return null;
        $dir = $projectDir.'/storage/.thumbnails/images';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        return $dir.'/'.md5($file.':'.$mtime).'.webp';
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
     * browsers apply that to the original, but GD does not and the WebP written
     * here carries no EXIF, so portrait photos lay on their side in the grid
     * while opening upright. Applied to the small thumbnail, not the full
     * photo, so the turn costs nothing worth measuring.
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
     * Decode $file, scale it to fit 300px, turn it upright and store it at $cache.
     *
     * Throws with the status the thumbnail route answers: 400 for a type that
     * takes no thumbnail, 503 without GD, 415 for a file that is not a
     * readable image, 422 for one too large to decode safely, 500 otherwise.
     */
    public static function generate(string $file, string $cache): void
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            throw new RuntimeException('Not a supported thumbnail type', 400);
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required for image thumbnails', 503);
        }

        // Measured from the header before anything is decoded: see
        // MAX_SOURCE_PIXELS.
        $dimensions = @getimagesize($file);
        if ($dimensions === false) throw new RuntimeException('This file is not a readable image', 415);
        if (!self::sourceFits((int)$dimensions[0], (int)$dimensions[1])) {
            throw new RuntimeException('This image is too large to make a thumbnail of', 422);
        }

        $create = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($file),
            'png' => @imagecreatefrompng($file),
            'gif' => @imagecreatefromgif($file),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            'bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($file) : false,
            default => false
        };
        if (!$create) throw new RuntimeException('Failed to generate thumbnail', 500);

        // Dimensions come from the decoded image, which is the one resampled.
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
        // A truecolor canvas starts opaque black with save-alpha off, so a
        // transparent PNG or GIF resampled onto it came out with black behind
        // whatever should have shown through, and imagewebp() then wrote no
        // alpha channel at all. Blending is turned off so the source alpha is
        // copied rather than composited against the black.
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagecopyresampled($im, $create, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('exif_read_data')) {
            $im = self::orient($im, (int)(@exif_read_data($file)['Orientation'] ?? 1));
        }

        // Write through a temporary file: two browsers -- or a browser and the
        // background job -- asking for the same new thumbnail at once must not
        // read a half-written one.
        $tmp = $cache.'.'.bin2hex(random_bytes(4)).'.tmp';
        $ok = @imagewebp($im, $tmp, 75);
        imagedestroy($im);
        imagedestroy($create);
        if (!$ok || !@rename($tmp, $cache)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to store image thumbnail', 500);
        }
    }
}
