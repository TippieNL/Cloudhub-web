<?php
declare(strict_types=1);
namespace CloudHub\Services;

use RuntimeException;

/**
 * Finds byte-identical photos and videos.
 *
 * Byte-identical, deliberately: a match here is never a judgement call, so
 * deleting one copy can never lose a file that was merely similar. Perceptual
 * matching -- a resized or re-encoded copy of the same picture -- is a
 * different feature with different risks, and for video it is not possible in
 * this application at all, which has no server-side decoder and extracts video
 * frames in the browser.
 *
 * The work is arranged so that almost none of it happens. Three stages, each
 * feeding the next a much smaller set:
 *
 *   1. Group by exact byte size. Two files of different sizes cannot be
 *      identical, and the walk already has the size in hand. On a real library
 *      this discards the great majority before a single byte is read.
 *   2. Hash the first and last 64 KB of what survives -- a fixed 128 KB read
 *      whatever the file weighs, which separates same-size-different-content
 *      files without touching the middle.
 *   3. Read in full only what is still grouped. A 600 MB video is read whole
 *      only if another file has its exact size and its exact head and tail.
 *
 * That ordering is the whole design. The target device is Android under KSWEB
 * on FUSE-backed storage, where reading bytes is expensive enough that a
 * hash-everything scan of a photo library is not worth building.
 */
final class DuplicateFinder
{
    /** Bytes read from each end of a file in the partial-hash stage. */
    private const EDGE_BYTES = 65536;

    /** Most path/size/mtime entries kept in the hash cache before pruning. */
    private const HASH_CACHE_ENTRIES = 50000;

    private string $cacheDir;

    public function __construct(private array $config, private FileService $files)
    {
        // Beside usage.json, the trash-purge stamp and the ledger sweep cursor:
        // outside the storage root, so this bookkeeping is never listed,
        // searched, counted, or reported as a duplicate of itself.
        $this->cacheDir = dirname(__DIR__, 2).'/storage/.cache';
    }

    /**
     * Begin a scan, or carry on with the one already running.
     *
     * The caller polls this. It starts over when asked, when nothing is in
     * flight, or when the folder being asked about is not the folder the saved
     * state describes -- the comparison is against the canonical path, so
     * "/Photos", "/Photos/" and "/Photos/." are the same request.
     */
    public function scan(string $path, bool $restart = false): array
    {
        $canonical = $this->files->relative($this->files->existing($path));
        $current = $this->load();
        if ($restart || $current === null || $current['path'] !== $canonical) {
            return $this->begin($path);
        }
        return $current['stage'] === 'done' ? $this->progress($current) : $this->advance();
    }

    /**
     * Walk the tree and queue the work. Cheap relative to hashing: stat only,
     * the same cost profile as FileService::storageReport(), which already
     * walks the whole store in one request on the Storage page.
     */
    public function begin(string $path): array
    {
        $start = $this->files->existing($path);
        if (!is_dir($start)) throw new RuntimeException('Directory not found', 404);

        $minBytes = max(0, (int)($this->config['duplicate_min_bytes'] ?? 1024));
        $maxFiles = max(1, (int)($this->config['duplicate_max_files'] ?? 50000));

        $bySize = [];
        $scanned = 0;
        $truncated = false;
        $stack = [$start];

        while ($stack) {
            $dir = array_pop($stack);
            foreach ($this->files->childPaths($dir) as $full) {
                if (is_dir($full)) { $stack[] = $full; continue; }
                // Photos and videos only, using the classification the storage
                // report already uses rather than a second extension list.
                $category = FileService::fileCategory($full);
                if ($category !== 'image' && $category !== 'video') continue;

                $bytes = (int)(@filesize($full) ?: 0);
                // Every empty file is identical to every other empty file, which
                // would make one enormous and entirely useless group.
                if ($bytes < $minBytes) continue;

                if (++$scanned > $maxFiles) { $truncated = true; break 2; }
                $bySize[$bytes][] = [
                    'path' => $this->files->relative($full),
                    'bytes' => $bytes,
                    'mtime' => (int)(@filemtime($full) ?: 0),
                ];
            }
        }

        // Only a size shared by more than one file can hold a duplicate.
        $pending = [];
        $candidates = 0;
        foreach ($bySize as $bytes => $group) {
            if (count($group) < 2) continue;
            $candidates += count($group);
            $pending[] = ['stage' => 'partial', 'bytes' => (int)$bytes, 'files' => $group];
        }
        // Largest first, so the biggest reclaimable space shows up early and a
        // scan somebody stops half way through has still told them something.
        usort($pending, static fn(array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $state = [
            'path' => $this->files->relative($start),
            'stage' => $pending ? 'hashing' : 'done',
            'startedAt' => time(),
            'finishedAt' => $pending ? null : time(),
            'scanned' => $scanned,
            'candidates' => $candidates,
            'hashed' => 0,
            'computed' => 0,
            'toHash' => $candidates,
            'truncated' => $truncated,
            'pending' => $pending,
            'groups' => [],
        ];
        $this->save($state);
        return $this->progress($state);
    }

    /**
     * Hash for a bounded slice of wall-clock time, then persist and return.
     *
     * The budget is checked between files rather than during one, because a
     * hash cannot be paused: hash_file() on a very large file runs to
     * completion, so a slice can overrun by however long that single file
     * takes. Checking between files is what keeps every request comfortably
     * inside max_execution_time in the ordinary case.
     */
    public function advance(): array
    {
        $state = $this->load();
        if ($state === null) throw new RuntimeException('No scan is in progress', 409);
        if ($state['stage'] === 'done') return $this->progress($state);

        $budget = max(1, (int)($this->config['duplicate_scan_seconds'] ?? 8));
        $deadline = microtime(true) + $budget;
        $hashes = $this->loadHashes();
        $dirty = false;

        while ($state['pending'] && microtime(true) < $deadline) {
            $item = array_shift($state['pending']);
            $partial = $item['stage'] === 'partial';

            $buckets = [];
            foreach ($item['files'] as $file) {
                $computed = false;
                $digest = $this->digest($file, $partial, $hashes, $dirty, $computed);
                // A file deleted or replaced since the walk simply drops out.
                if ($digest === null) continue;
                $buckets[$digest][] = $file;
                $state['hashed']++;
                if ($computed) $state['computed']++;
            }

            foreach ($buckets as $files) {
                if (count($files) < 2) continue;
                if ($partial && $item['bytes'] > self::EDGE_BYTES * 2) {
                    // Same size, same head and tail. Now it is worth reading
                    // the middle.
                    $state['pending'][] = ['stage' => 'full', 'bytes' => $item['bytes'], 'files' => $files];
                    $state['toHash'] += count($files);
                    continue;
                }
                // Either the full read is already done, or the file is small
                // enough that the two edges overlap and the partial hash *is*
                // the whole content.
                $state['groups'][] = [
                    'bytes' => $item['bytes'],
                    'count' => count($files),
                    'reclaimable' => $item['bytes'] * (count($files) - 1),
                    'files' => $files,
                ];
            }
        }

        if (!$state['pending']) {
            $state['stage'] = 'done';
            $state['finishedAt'] = time();
            usort($state['groups'], static fn(array $a, array $b): int => $b['reclaimable'] <=> $a['reclaimable']);
        }

        if ($dirty) $this->saveHashes($hashes);
        $this->save($state);
        return $this->progress($state);
    }

    /** The current scan without doing any further work. */
    public function state(): ?array
    {
        $state = $this->load();
        return $state === null ? null : $this->progress($state);
    }

    public function reset(): void
    {
        @unlink($this->cacheDir.'/duplicates.json');
    }

    /**
     * One file's digest, from the cache when the file demonstrably has not
     * changed since it was hashed.
     *
     * Keyed by path, size and mtime, so a re-scan hashes only what is new or
     * modified -- which is what makes running this regularly bearable.
     *
     * The entry is only trusted when the file is strictly older than the moment
     * it was hashed. mtime has one-second granularity, so a file rewritten in
     * the same second in which its digest was taken keeps that digest under a
     * key that still matches -- and a stale digest here does not merely slow
     * things down, it reports two files as identical when they are not, in a
     * feature whose entire safety argument is that a match is never a guess.
     * rsync and git treat a same-second timestamp as untrustworthy for the same
     * reason; git calls the condition "racily clean".
     *
     * What this cannot cover is a write that preserves or backdates mtime: no
     * cache keyed on mtime can tell that from a file nobody touched. A scan
     * started with the cache files removed re-reads everything, which is the
     * answer if a tool that rewrites timestamps has been near the library.
     */
    private function digest(array $file, bool $partial, array &$hashes, bool &$dirty, bool &$computed): ?string
    {
        $key = ($partial ? 'p:' : 'f:').$file['path'].'|'.$file['bytes'].'|'.$file['mtime'];
        if (isset($hashes[$key]['h'], $hashes[$key]['w']) && $file['mtime'] < (int)$hashes[$key]['w']) {
            $hashes[$key]['t'] = time();
            return (string)$hashes[$key]['h'];
        }

        // Resolved rather than concatenated: existing() re-proves containment
        // and refuses symlinks, so a file deleted, replaced or relinked between
        // the walk and the hash drops out instead of being read.
        try { $full = $this->files->existing($file['path']); }
        catch (\Throwable) { return null; }
        if (!is_file($full)) return null;

        $digest = $partial ? $this->edgeHash($full, $file['bytes']) : @hash_file('sha256', $full);
        if ($digest === false || $digest === null) return null;

        $hashes[$key] = ['h' => $digest, 't' => time(), 'w' => time()];
        $dirty = true;
        $computed = true;
        return $digest;
    }

    /** Hash of the first and last EDGE_BYTES, which is a fixed cost per file. */
    private function edgeHash(string $full, int $bytes): ?string
    {
        $handle = @fopen($full, 'rb');
        if ($handle === false) return null;
        try {
            $ctx = hash_init('sha256');
            $head = fread($handle, self::EDGE_BYTES);
            if ($head === false) return null;
            hash_update($ctx, $head);
            if ($bytes > self::EDGE_BYTES) {
                if (fseek($handle, -min(self::EDGE_BYTES, $bytes), SEEK_END) !== 0) return null;
                $tail = fread($handle, self::EDGE_BYTES);
                if ($tail === false) return null;
                hash_update($ctx, $tail);
            }
            // The length is part of the digest so that two different files
            // cannot collide merely by sharing edges of different lengths.
            hash_update($ctx, '|'.$bytes);
            return hash_final($ctx);
        } finally {
            fclose($handle);
        }
    }

    /** What the client sees: progress plus whatever has been confirmed so far. */
    private function progress(array $state): array
    {
        $reclaimable = 0;
        foreach ($state['groups'] as $group) $reclaimable += (int)$group['reclaimable'];

        return [
            'path' => $state['path'],
            'done' => $state['stage'] === 'done',
            'scanned' => $state['scanned'],
            'candidates' => $state['candidates'],
            'hashed' => $state['hashed'],
            'computed' => $state['computed'] ?? 0,
            'toHash' => $state['toHash'],
            'truncated' => $state['truncated'],
            'groups' => $state['groups'],
            'duplicateFiles' => array_sum(array_map(static fn(array $g): int => $g['count'] - 1, $state['groups'])),
            'reclaimable' => $reclaimable,
            'startedAt' => gmdate('c', (int)$state['startedAt']),
            'finishedAt' => $state['finishedAt'] ? gmdate('c', (int)$state['finishedAt']) : null,
        ];
    }

    private function load(): ?array
    {
        $file = $this->cacheDir.'/duplicates.json';
        if (!is_file($file)) return null;
        $state = json_decode((string)@file_get_contents($file), true);
        return is_array($state) && isset($state['stage'], $state['pending']) ? $state : null;
    }

    private function save(array $state): void
    {
        $this->writeJson($this->cacheDir.'/duplicates.json', $state);
    }

    /** @return array<string,array{h:string,t:int}> */
    private function loadHashes(): array
    {
        $file = $this->cacheDir.'/duplicate-hashes.json';
        if (!is_file($file)) return [];
        $hashes = json_decode((string)@file_get_contents($file), true);
        return is_array($hashes) ? $hashes : [];
    }

    private function saveHashes(array $hashes): void
    {
        if (count($hashes) > self::HASH_CACHE_ENTRIES) {
            // Oldest touch first, so the entries that survive are the ones the
            // most recent scans actually used.
            uasort($hashes, static fn(array $a, array $b): int => ($a['t'] ?? 0) <=> ($b['t'] ?? 0));
            $hashes = array_slice($hashes, -self::HASH_CACHE_ENTRIES, null, true);
        }
        $this->writeJson($this->cacheDir.'/duplicate-hashes.json', $hashes);
    }

    /** Through a temporary file, so a reader never sees half a document. */
    private function writeJson(string $file, array $payload): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) return;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $file)) @unlink($tmp);
    }
}
