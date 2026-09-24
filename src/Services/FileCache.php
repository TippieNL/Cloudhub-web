<?php
declare(strict_types=1);
namespace CloudHub\Services;

use CloudHub\Helpers\Cache;
use RuntimeException;

/**
 * Folder listings, search and favorites, from the cache where that is safe.
 *
 * All three are dominated by one stat() per entry, which is nothing on a
 * server disk and most of the response time on Android's FUSE-backed shared
 * storage. Measured on a FUSE mount with the kernel's attribute cache off:
 * 1.3 s to list a 3,200-entry folder, 1.0 s to search 13,000 files, 0.4 s for
 * 500 favorites.
 *
 * What each answer is checked against before it is reused:
 *
 *   listing    the folder's own dirStamp(), so any entry added, removed or
 *              renamed misses; the cache generation, so anything CloudHub
 *              itself changed misses; and CACHE_TTL_SECONDS. What that leaves
 *              is a file rewritten in place by another program, whose size
 *              and time can lag by up to the TTL.
 *   search     every directory the walk passes through, by dirStamp(); the
 *              result rows are built fresh. No TTL is involved: it answers
 *              exactly as FileService::search() would.
 *   favorites  the account's stored paths and the cache generation, and the
 *              TTL -- a favorite deleted by another program stays listed for
 *              up to that long, and is forgotten once it is noticed.
 *
 * And only answers that took Cache::minComputeMs() or longer are kept. Where
 * the storage is quick nothing is cached, so nothing can be stale, and all
 * this costs is one stat of a local stamp file.
 */
final class FileCache
{
    /**
     * How long a search recording is kept. Every use re-proves it against the
     * disk, so this bounds storage rather than staleness.
     */
    public const SEARCH_SECONDS = 600;

    public function __construct(private readonly FileService $files) {}

    /** FileService::list(). */
    public function list(string $path): array
    {
        $ttl = Cache::ttl();
        if ($ttl <= 0 || !Cache::enabled()) return $this->files->list($path);

        $dir = $this->files->existing($path);
        if (!is_dir($dir)) throw new RuntimeException('Directory not found', 404);
        // Both read before the folder is: see Cache::generation() and
        // FileService::dirStamp() for why the order matters.
        $stamp = $this->files->dirStamp($dir);
        $generation = Cache::generation();
        $key = 'list_'.sha1($dir);

        if ($stamp !== null && $generation !== null) {
            $hit = Cache::get($key);
            if (is_array($hit) && ($hit['dir'] ?? null) === $dir && ($hit['stamp'] ?? null) === $stamp[0]
                && ($hit['generation'] ?? null) === $generation && is_array($hit['rows'] ?? null)) {
                return $hit['rows'];
            }
        }

        $started = hrtime(true);
        $rows = $this->files->list($path);
        if ($stamp !== null && $stamp[1] && $generation !== null && Cache::worthKeeping($started)) {
            Cache::set($key, ['dir' => $dir, 'stamp' => $stamp[0], 'generation' => $generation, 'rows' => $rows], $ttl);
        }
        return $rows;
    }

    /** FileService::search(), by way of FileService::searchWithIndex(). */
    public function search(string $path, string $needle, int $limit, int $maxNodes = 20000): array
    {
        if (!Cache::enabled()) return $this->files->search($path, $needle, $limit, $maxNodes);

        $key = 'search_'.sha1($this->files->existing($path)."\0".$maxNodes);
        $started = hrtime(true);
        $stored = Cache::get($key);
        [$answer, $index, $keep] = $this->files->searchWithIndex($path, $needle, $limit, $maxNodes,
            is_array($stored) ? $stored : null);
        if ($keep && Cache::worthKeeping($started)) Cache::set($key, $index, self::SEARCH_SECONDS);
        return $answer;
    }

    /**
     * FileService::describe() for each of a set of stored paths.
     *
     * Only a 404 means a path is gone -- removed by something CloudHub does
     * not see, such as a change made on the disk itself. Any other failure is
     * no evidence of that, so the path is neither returned nor reported gone.
     *
     * @param string $scope whose paths these are, e.g. one account's favorites
     * @param list<string> $paths
     * @return array{rows:array<int,array>,gone:list<string>} rows keyed by position in $paths
     */
    public function describeMany(string $scope, array $paths): array
    {
        $ttl = Cache::ttl();
        $generation = $ttl > 0 && Cache::enabled() ? Cache::generation() : null;
        $key = 'describe_'.sha1($scope);
        $signature = sha1(implode("\0", $paths));

        if ($generation !== null) {
            $hit = Cache::get($key);
            if (is_array($hit) && ($hit['scope'] ?? null) === $scope && ($hit['signature'] ?? null) === $signature
                && ($hit['generation'] ?? null) === $generation && is_array($hit['rows'] ?? null)) {
                return ['rows' => $hit['rows'], 'gone' => []];
            }
        }

        $started = hrtime(true);
        $rows = [];
        $gone = [];
        foreach ($paths as $i => $path) {
            try {
                $rows[$i] = $this->files->describe($path);
            } catch (RuntimeException $e) {
                if ($e->getCode() === 404) $gone[] = $path;
            }
        }
        // Not kept while anything is gone: the caller forgets those, which
        // changes the signature, so the entry could never be read back.
        if ($generation !== null && !$gone && Cache::worthKeeping($started)) {
            Cache::set($key, ['scope' => $scope, 'signature' => $signature, 'generation' => $generation, 'rows' => $rows], $ttl);
        }
        return ['rows' => $rows, 'gone' => $gone];
    }
}
