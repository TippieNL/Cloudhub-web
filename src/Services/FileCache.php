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
 *   search     each folder the walk passes through, by its own dirStamp(), so
 *              a change costs that folder alone; result rows are built fresh.
 *              No TTL is involved: it answers exactly as FileService::search()
 *              would.
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
     * How long a search's record of the tree is kept. Every use re-proves each
     * folder it passes through, so this bounds storage rather than staleness.
     */
    public const SEARCH_SECONDS = Cache::MAX_TTL;

    /** A record larger than this is not kept; the next search starts over. */
    public const SEARCH_MAX_BYTES = 16 * 1048576;

    /**
     * How long a search may walk when nothing can keep what it found, and so
     * asking again would only walk the same ground again.
     */
    public const UNCACHED_BUDGET_MS = 20000;

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

    /**
     * FileService::search(), by way of FileService::searchWithIndex().
     *
     * One record per storage root, whatever folder the search starts from:
     * the walk below /DCIM passes through the same folders as the walk from /,
     * and each folder's record is proven on its own.
     *
     * $budgetMs is how long to walk before answering with what has been found;
     * the answer says `incomplete`, and the same request again carries on from
     * the record. Without a cache there is no record to carry on from, so the
     * walk gets UNCACHED_BUDGET_MS instead.
     */
    public function search(string $path, string $needle, int $limit, int $budgetMs): array
    {
        if (!Cache::enabled()) {
            $deadline = microtime(true) + max($budgetMs, self::UNCACHED_BUDGET_MS) / 1000;
            return $this->files->search($path, $needle, $limit, FileService::SEARCH_MAX_NODES, $deadline);
        }
        $deadline = microtime(true) + $budgetMs / 1000;
        $key = 'tree_'.sha1($this->files->root());
        $started = hrtime(true);
        $stored = Cache::get($key);
        [$answer, $index, $changed] = $this->files->searchWithIndex($path, $needle, $limit,
            FileService::SEARCH_MAX_NODES, is_array($stored) ? $stored : null, $deadline);
        // A walk cut short is kept whatever it cost: the next request carries on from it.
        if ($changed && $index['bytes'] <= self::SEARCH_MAX_BYTES
            && ($answer['incomplete'] || Cache::worthKeeping($started))) {
            Cache::set($key, $index, self::SEARCH_SECONDS);
        }
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
