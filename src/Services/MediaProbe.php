<?php
declare(strict_types=1);
namespace CloudHub\Services;

/**
 * Which codec a media file actually holds.
 *
 * A browser that cannot decode a file reports MEDIA_ERR_SRC_NOT_SUPPORTED and
 * nothing else -- not what it is, not what is missing. Naming the codec turns
 * "this media format is not supported" into something a person can act on, and
 * H.265/HEVC in an .mp4 is by far the common case: desktop players take it,
 * many Android browsers refuse it, and nothing about the file or the server is
 * wrong when they do.
 *
 * This reads bytes chosen by whoever uploaded the file, so it is written that
 * way: a cap on how much is read, a depth limit, declared box sizes checked
 * against what is actually left rather than believed, and any offset that fails
 * to advance treated as malformed. A container it does not understand returns
 * 'unknown' -- a confidently wrong codec in an error message is worse than no
 * codec at all.
 */
final class MediaProbe
{
    /** Ceiling on bytes read from one file, however the boxes are laid out. */
    private const READ_LIMIT = 4194304;

    /** ISO-BMFF nests shallowly; anything deeper is malformed or hostile. */
    private const MAX_DEPTH = 12;

    /** Boxes worth descending into on the way to stsd. */
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    /** fourcc => how a person would name it, and whether browsers broadly decode it. */
    private const CODECS = [
        'avc1' => ['H.264 / AVC', true],
        'avc3' => ['H.264 / AVC', true],
        'hvc1' => ['H.265 / HEVC', false],
        'hev1' => ['H.265 / HEVC', false],
        'av01' => ['AV1', false],
        'vp09' => ['VP9', true],
        'vp08' => ['VP8', true],
        'mp4v' => ['MPEG-4 Part 2', false],
        's263' => ['H.263', false],
        'mp4a' => ['AAC', true],
        'Opus' => ['Opus', true],
        'ac-3' => ['Dolby AC-3', false],
        'ec-3' => ['Dolby E-AC-3', false],
        'alac' => ['ALAC', false],
        'fLaC' => ['FLAC', true],
    ];

    private int $bytesRead = 0;

    public function __construct(private string $cacheDir) {}

    /** Bytes read by the last probe. Exposed so a test can hold it to its cap. */
    public function bytesRead(): int { return $this->bytesRead; }

    /**
     * @return array{codec:?string,name:string,widelySupported:?bool,container:string}
     */
    public function probe(string $file): array
    {
        $unknown = ['codec' => null, 'name' => 'unknown', 'widelySupported' => null, 'container' => 'unknown'];

        $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        // The box structure below is the MP4/QuickTime family. Everything else
        // is reported as unknown rather than parsed hopefully.
        if (!in_array($extension, ['mp4', 'm4v', 'mov', 'm4a', '3gp', '3g2'], true)) return $unknown;
        if (!is_file($file)) return $unknown;

        $cached = $this->cached($file);
        if ($cached !== null) return $cached;

        $this->bytesRead = 0;
        $handle = @fopen($file, 'rb');
        if ($handle === false) return $unknown;

        try {
            $size = (int)(@filesize($file) ?: 0);
            $fourcc = $this->findSampleFormat($handle, 0, $size, 0);
        } catch (\Throwable) {
            $fourcc = null;
        } finally {
            fclose($handle);
        }

        $result = $unknown;
        $result['container'] = 'mp4';
        if ($fourcc !== null) {
            [$name, $supported] = self::CODECS[$fourcc] ?? [$fourcc, null];
            $result = ['codec' => $fourcc, 'name' => $name, 'widelySupported' => $supported, 'container' => 'mp4'];
        }

        $this->remember($file, $result);
        return $result;
    }

    /**
     * Walk boxes between $offset and $end, descending containers, until a
     * sample description yields a format.
     */
    private function findSampleFormat($handle, int $offset, int $end, int $depth): ?string
    {
        if ($depth > self::MAX_DEPTH) return null;

        while ($offset < $end) {
            if ($this->bytesRead >= self::READ_LIMIT) return null;

            $header = $this->readAt($handle, $offset, 8);
            if ($header === null || strlen($header) < 8) return null;

            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;

            if ($size === 1) {
                // 64-bit size. PHP integers are signed; a value that does not
                // fit is malformed as far as this is concerned.
                $large = $this->readAt($handle, $offset + 8, 8);
                if ($large === null || strlen($large) < 8) return null;
                $parts = unpack('Nhigh/Nlow', $large);
                if ($parts['high'] > 0x7FFFFFFF) return null;
                $size = ($parts['high'] << 32) | $parts['low'];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $end - $offset;   // extends to the end of its parent
            }

            // Believed only after it is checked against what is actually there.
            if ($size < $headerSize || $offset + $size > $end) return null;

            if ($type === 'stsd') {
                $found = $this->readSampleFormat($handle, $offset + $headerSize, $offset + $size);
                if ($found !== null) return $found;
            } elseif (in_array($type, self::CONTAINERS, true)) {
                $found = $this->findSampleFormat($handle, $offset + $headerSize, $offset + $size, $depth + 1);
                if ($found !== null) return $found;
            }

            $next = $offset + $size;
            // A box that does not move the cursor forward would loop for ever.
            if ($next <= $offset) return null;
            $offset = $next;
        }
        return null;
    }

    /** stsd: version/flags, an entry count, then sample entries whose first field is the format. */
    private function readSampleFormat($handle, int $offset, int $end): ?string
    {
        if ($offset + 16 > $end) return null;
        $body = $this->readAt($handle, $offset, 16);
        if ($body === null || strlen($body) < 16) return null;

        $count = unpack('N', substr($body, 4, 4))[1];
        if ($count < 1) return null;

        // Entry: 4-byte size, 4-byte format.
        $format = substr($body, 12, 4);
        return preg_match('/^[A-Za-z0-9\-\. ]{4}$/', $format) === 1 ? $format : null;
    }

    /** Read exactly $length bytes at $offset, counting them against the cap. */
    private function readAt($handle, int $offset, int $length): ?string
    {
        if ($offset < 0 || $length <= 0) return null;
        if ($this->bytesRead + $length > self::READ_LIMIT) return null;
        if (fseek($handle, $offset) !== 0) return null;
        $data = fread($handle, $length);
        if ($data === false) return null;
        $this->bytesRead += strlen($data);
        return $data;
    }

    /* ---- cache ---------------------------------------------------------- */

    private function cacheFile(): string { return $this->cacheDir.'/media-codecs.json'; }

    private function cached(string $file): ?array
    {
        $all = $this->load();
        $key = $this->key($file);
        $entry = $all[$key] ?? null;
        if (!is_array($entry) || !isset($entry['result'], $entry['w'])) return null;

        // Only trusted when the file is strictly older than the moment it was
        // read: mtime is second-granular, so a file rewritten in the same
        // second still matches its key. Same rule as the duplicate hash cache.
        $mtime = (int)(@filemtime($file) ?: 0);
        return $mtime < (int)$entry['w'] ? $entry['result'] : null;
    }

    private function remember(string $file, array $result): void
    {
        $all = $this->load();
        $all[$this->key($file)] = ['result' => $result, 'w' => time()];
        if (count($all) > 5000) $all = array_slice($all, -5000, null, true);

        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) return;
        $json = json_encode($all, JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        $tmp = $this->cacheFile().'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $this->cacheFile())) @unlink($tmp);
    }

    private function load(): array
    {
        if (!is_file($this->cacheFile())) return [];
        $all = json_decode((string)@file_get_contents($this->cacheFile()), true);
        return is_array($all) ? $all : [];
    }

    private function key(string $file): string
    {
        return $file.'|'.(int)(@filesize($file) ?: 0).'|'.(int)(@filemtime($file) ?: 0);
    }
}
