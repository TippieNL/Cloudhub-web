<?php
declare(strict_types=1);
namespace CloudHub\Services;

use RuntimeException;

/**
 * Thumbnails for everything already in the store, made in the background a
 * slice at a time.
 *
 * A gallery makes a thumbnail the first time it is asked for one, which is
 * fine for a photo uploaded today and slow for the ten thousand that were
 * there before thumbnails were kept: every folder is a cold cache the first
 * time it is opened. This works through the store ahead of anyone looking, in
 * three passes, each resumable from where the last slice stopped:
 *
 *  - walk: list every image and video, a folder at a time, into a file on
 *    disk -- so a store of a million photos costs a file of their names, not a
 *    million entries in memory;
 *  - make: read that list from where it got to and give every image a current
 *    thumbnail, skipping those that have one and those known to be
 *    unreadable, and bring video frames over from the first layout;
 *  - sweep: walk the mirror and remove what no longer belongs to anything --
 *    thumbnails of files deleted outside CloudHub, stale ones, half-written
 *    leftovers -- and finally the first layout's folder.
 *
 * Paths are written down URL-encoded, one to a line: a file name may hold any
 * byte but "/" and NUL, which JSON cannot carry and a newline would split.
 *
 * One scan at a time, whoever asks: a slice holds a lock, and a second caller
 * is told the scan is busy and shown how far it has got.
 */
final class ThumbnailScan
{
    /** How long one request carries the scan on for. */
    public const SLICE_SECONDS = 8;

    /** How long a finished scan stands before another is begun without being asked for. */
    public const FRESH_SECONDS = 86400;

    /** A half-written thumbnail older than this belongs to a request that died. */
    private const STALE_TEMP_SECONDS = 3600;

    private const COUNTS = ['generated' => 0, 'valid' => 0, 'adopted' => 0, 'failed' => 0, 'skipped' => 0, 'removed' => 0];

    private string $stateFile;
    private string $listFile;
    private string $lockFile;

    /** @param string $stateDir where the scan keeps its place, `storage/.cache` */
    public function __construct(private FileService $fs, private ThumbnailService $thumbs, string $stateDir)
    {
        $dir = rtrim(str_replace('\\', '/', $stateDir), '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $this->stateFile = $dir.'/thumbnail-scan.json';
        $this->listFile = $dir.'/thumbnail-scan.list';
        $this->lockFile = $dir.'/thumbnail-scan.lock';
    }

    /** How far the last scan got, doing no work. */
    public function progress(): array
    {
        return $this->report($this->load(), false);
    }

    /**
     * Carry the scan on for up to $seconds and say how far it has got.
     *
     * $restart begins a new scan whatever state the last one is in. Without
     * it a scan in progress is continued, and a finished one is begun again
     * only once it is FRESH_SECONDS old -- so a client may ask on every start
     * and cost the server nothing on most of them.
     */
    public function run(float $seconds, bool $restart = false): array
    {
        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false) throw new RuntimeException('Unable to open the thumbnail scan', 500);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return $this->report($this->load(), true);
        }
        try {
            $state = $this->load();
            $stale = $state !== null && $state['phase'] === 'done'
                && time() - (int)$state['finishedTs'] > self::FRESH_SECONDS;
            if ($restart || $state === null || $stale) $state = $this->begin();

            $deadline = microtime(true) + max(0.0, $seconds);
            while ($state['phase'] !== 'done' && microtime(true) < $deadline) {
                $state = match ($state['phase']) {
                    'walk' => $this->walk($state, $deadline),
                    'make' => $this->make($state, $deadline),
                    'sweep' => $this->sweep($state, $deadline),
                };
                $this->save($state);
            }
            $this->save($state);
            return $this->report($state, false);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function begin(): array
    {
        if (@file_put_contents($this->listFile, '') === false) {
            throw new RuntimeException('Unable to start the thumbnail scan', 500);
        }
        return [
            'version' => 1,
            'phase' => 'walk',
            'startedAt' => gmdate('c'),
            'finishedAt' => null,
            'finishedTs' => 0,
            'walk' => [rawurlencode('/')],
            'found' => 0,
            'offset' => 0,
            'processed' => 0,
            'sweep' => [''],
            'counts' => self::COUNTS,
        ];
    }

    /* ---- walk: what is there -------------------------------------------- */

    private function walk(array $state, float $deadline): array
    {
        $out = @fopen($this->listFile, 'ab');
        if ($out === false) throw new RuntimeException('Unable to write the thumbnail scan', 500);
        try {
            $root = $this->fs->root();
            while ($state['walk'] && microtime(true) < $deadline) {
                $relative = rawurldecode((string)array_pop($state['walk']));
                $dir = $relative === '/' ? $root : $root.$relative;
                if (!is_dir($dir)) continue;
                // childPaths() applies the store's own rules: no symlinks, and
                // none of CloudHub's folders at the root (the trash among them).
                foreach ($this->fs->childPaths($dir) as $child) {
                    $rel = $this->fs->relative($child);
                    if (is_dir($child)) {
                        $state['walk'][] = rawurlencode($rel);
                    } elseif (is_file($child) && (ThumbnailService::isImage($child) || ThumbnailService::isVideo($child))) {
                        fwrite($out, rawurlencode($rel)."\n");
                        $state['found']++;
                    }
                }
            }
        } finally {
            fclose($out);
        }
        if (!$state['walk']) $state['phase'] = 'make';
        return $state;
    }

    /* ---- make: a thumbnail for each ---------------------------------------- */

    private function make(array $state, float $deadline): array
    {
        $in = @fopen($this->listFile, 'rb');
        if ($in === false) {
            $state['phase'] = 'sweep';
            return $state;
        }
        $finished = false;
        try {
            fseek($in, (int)$state['offset']);
            $root = $this->fs->root();
            while (microtime(true) < $deadline) {
                $line = fgets($in);
                if ($line === false) { $finished = true; break; }
                $state['offset'] = (int)ftell($in);
                $rel = rawurldecode(rtrim($line, "\r\n"));
                if ($rel === '' || $rel === '/') continue;
                $state['processed']++;
                $state['counts'][$this->one($root.$rel)]++;
            }
        } finally {
            fclose($in);
        }
        if ($finished) $state['phase'] = 'sweep';
        return $state;
    }

    /**
     * One file's thumbnail, and which count it belongs in.
     *
     * Unreadable files are the files' own business and counted as failed --
     * the service has recorded them, so they are not decoded again until they
     * change. What this server cannot do (no GD, no AVIF) is skipped rather
     * than blamed on the file, and a fault of the server's is logged.
     */
    private function one(string $file): string
    {
        if (!is_file($file)) return 'skipped';
        if (ThumbnailService::isVideo($file)) {
            if ($this->thumbs->current($file, true) !== null) return 'valid';
            return $this->thumbs->adoptFrame($file) ? 'adopted' : 'skipped';
        }
        if (!ThumbnailService::canDecode(ThumbnailService::extensionOf($file)) || !$this->thumbs->canKeep($file)) {
            return 'skipped';
        }
        try {
            return $this->thumbs->ensure($file, true)['status'] === ThumbnailService::GENERATED ? 'generated' : 'valid';
        } catch (RuntimeException $e) {
            if (in_array($e->getCode(), [415, 422], true)) return 'failed';
            error_log('[thumbnails] '.$this->fs->relative($file).': '.$e->getMessage());
            return 'failed';
        }
    }

    /* ---- sweep: what no longer belongs ------------------------------------- */

    private function sweep(array $state, float $deadline): array
    {
        $tree = $this->thumbs->tree();
        while ($state['sweep'] && microtime(true) < $deadline) {
            $rel = rawurldecode((string)array_pop($state['sweep']));
            $dir = $tree.$rel;
            if (!is_dir($dir) || is_link($dir)) continue;
            $kept = 0;
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $full = $dir.'/'.$name;
                if (!is_link($full) && is_dir($full)) {
                    $state['sweep'][] = rawurlencode($rel.'/'.$name);
                    $kept++;
                } elseif ($this->belongs($full)) {
                    $kept++;
                } elseif (@unlink($full)) {
                    $state['counts']['removed']++;
                }
            }
            // Emptied by this pass: a folder of the mirror whose folder in the
            // store is gone. Its parent goes on the next pass, if it empties.
            if ($kept === 0 && $rel !== '') @rmdir($dir);
        }
        if (!$state['sweep']) {
            $state['counts']['removed'] += $this->removeLegacy();
            @unlink($this->listFile);
            $state['phase'] = 'done';
            $state['finishedAt'] = gmdate('c');
            $state['finishedTs'] = time();
        }
        return $state;
    }

    /** Whether a file in the mirror is a current thumbnail or marker of something in the store. */
    private function belongs(string $file): bool
    {
        if (is_link($file)) return false;
        // Stamped with its original's time before the rename, so only its
        // change time says how old a half-written one is.
        if (str_ends_with($file, '.tmp')) return time() - (int)@filectime($file) < self::STALE_TEMP_SECONDS;
        $source = $this->thumbs->sourceOf($file);
        if ($source === null) return false;
        $original = $this->fs->root().$source;
        if (!is_file($original)) return false;
        if (!ThumbnailService::isImage($original) && !ThumbnailService::isVideo($original)) return false;
        return @filemtime($file) === @filemtime($original);
    }

    /**
     * The first layout's folder, once every frame in it has been brought
     * across -- which is what a finished pass means. What is left belongs to
     * files long gone, or to images that have been made again at this size.
     */
    private function removeLegacy(): int
    {
        $dir = $this->thumbs->legacyDir();
        if (!is_dir($dir) || is_link($dir)) return 0;
        $removed = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $dir.'/'.$name;
            if (is_file($full) && @unlink($full)) $removed++;
        }
        @rmdir($dir);
        return $removed;
    }

    /* ---- the scan's own state ---------------------------------------------- */

    private function load(): ?array
    {
        if (!is_file($this->stateFile)) return null;
        $state = json_decode((string)@file_get_contents($this->stateFile), true);
        if (!is_array($state) || ($state['version'] ?? 0) !== 1
            || !in_array($state['phase'] ?? null, ['walk', 'make', 'sweep', 'done'], true)) {
            return null;
        }
        $state['counts'] = array_merge(self::COUNTS, array_map('intval', (array)($state['counts'] ?? [])));
        $state['walk'] = array_values(array_map('strval', (array)($state['walk'] ?? [])));
        $state['sweep'] = array_values(array_map('strval', (array)($state['sweep'] ?? [])));
        foreach (['found', 'offset', 'processed', 'finishedTs'] as $key) $state[$key] = (int)($state[$key] ?? 0);
        return $state;
    }

    private function save(array $state): void
    {
        $tmp = $this->stateFile.'.'.bin2hex(random_bytes(4)).'.tmp';
        $body = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($body === false || @file_put_contents($tmp, $body) === false || !@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to record the thumbnail scan', 500);
        }
    }

    private function report(?array $state, bool $busy): array
    {
        $counts = $state['counts'] ?? self::COUNTS;
        return [
            'started' => $state !== null,
            'done' => ($state['phase'] ?? null) === 'done',
            'busy' => $busy,
            'phase' => $state['phase'] ?? null,
            'found' => (int)($state['found'] ?? 0),
            'processed' => (int)($state['processed'] ?? 0),
            'generated' => $counts['generated'],
            'valid' => $counts['valid'],
            'adopted' => $counts['adopted'],
            'failed' => $counts['failed'],
            'skipped' => $counts['skipped'],
            'removed' => $counts['removed'],
            'startedAt' => $state['startedAt'] ?? null,
            'finishedAt' => $state['finishedAt'] ?? null,
            'format' => ThumbnailService::FORMAT,
        ];
    }
}
