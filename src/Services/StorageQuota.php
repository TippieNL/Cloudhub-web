<?php
declare(strict_types=1);
namespace CloudHub\Services;

use CloudHub\Helpers\Cache;
use CloudHub\Repositories\StorageLedger;
use RuntimeException;

/**
 * The whole-store limit and the per-account quota: measured, and enforced.
 *
 * Moved out of public/index.php, whose functions of the same job now delegate
 * here, so that the background worker -- which never loads the front
 * controller -- refuses exactly the copies and extractions an HTTP request
 * would. A quota that only binds in the foreground is one stepped round by
 * queueing the work instead.
 *
 * Both limits are opt-in (0 means unlimited) and both fail open: if a figure
 * cannot be obtained the bytes are let through, because blocking a legitimate
 * upload over a bookkeeping problem is worse than letting one through.
 *
 * sweepOccasionally() reads the application cache's switch, so Cache must have
 * been configured first.
 */
final class StorageQuota
{
    /**
     * The least time between two backstop sweeps of the ledger.
     *
     * Each sweep resolves up to 500 recorded paths, which on Android's FUSE
     * storage measured half a second -- and every file of a batch upload used
     * to run one, so 150 files under a quota spent over a minute re-checking
     * the same rows. The sweep is the backstop for files removed behind
     * CloudHub's back; CloudHub's own deletions leave the ledger straight away.
     * So skipping a repeat within seconds can only leave a vanished file
     * counted a little longer, never let an upload through that should have
     * been refused.
     */
    public const LEDGER_SWEEP_SECONDS = 30;

    public function __construct(
        private readonly FileService $files,
        private readonly array $config,
        private readonly StorageLedger $ledger,
        private readonly string $projectDir,
    ) {}

    /**
     * Measured storage use, cached.
     *
     * Measuring means walking the whole store, which is far too expensive to
     * do on every upload. The result is written to a small cache file and
     * reused for usage_cache_seconds; $force recomputes it for the
     * "Recalculate" button.
     *
     * The cache lives outside the storage root so it is neither listed,
     * searched, nor counted in the figure it holds.
     */
    public function report(bool $force = false): array
    {
        $cache = $this->projectDir.'/storage/.cache/usage.json';
        $ttl = max(0, (int)$this->config['usage_cache_seconds']);

        if (!$force && $ttl > 0 && is_file($cache) && time() - (int)filemtime($cache) < $ttl) {
            $cached = json_decode((string)file_get_contents($cache), true);
            if (is_array($cached) && isset($cached['bytes'])) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $report = $this->files->storageReport();
        $report['cached'] = false;
        if (!is_dir(dirname($cache))) @mkdir(dirname($cache), 0775, true);
        // Written through a temporary file, as the thumbnail cache already is:
        // a reader hitting a half-written usage.json gets JSON it cannot decode.
        // Names that are not UTF-8 are substituted: the report only displays
        // them, and failing to encode meant it was never cached at all, so
        // every quota check and dashboard visit walked the whole store again.
        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if ($reportJson !== false) {
            $cacheTmp = $cache.'.'.bin2hex(random_bytes(4)).'.tmp';
            if (@file_put_contents($cacheTmp, $reportJson) !== strlen($reportJson) || !@rename($cacheTmp, $cache)) {
                @unlink($cacheTmp);
            }
        }
        return $report;
    }

    /**
     * StorageLedger::sweep(), at most once every LEDGER_SWEEP_SECONDS.
     *
     * Off with the cache (CACHE_DRIVER=none), which restores a sweep every
     * time. The admin dashboard and restore still sweep unconditionally.
     */
    public function sweepOccasionally(): void
    {
        $stamp = $this->projectDir.'/storage/.cache/ledger-sweep';
        if (Cache::enabled()) {
            $last = @filemtime($stamp);
            if ($last !== false && time() - $last < self::LEDGER_SWEEP_SECONDS) return;
            if (is_dir(dirname($stamp)) || @mkdir(dirname($stamp), 0775, true) || is_dir(dirname($stamp))) @touch($stamp);
        }
        $this->ledger->sweep($this->files);
    }

    /**
     * Refuse bytes that would breach the whole-store limit or the account's
     * own quota, before any of them are written.
     *
     * $userId is who the bytes will be charged to; null charges nobody, so
     * only the store limit applies.
     */
    public function assertFits(int $size, ?int $userId): void
    {
        $limit = (int)round((float)$this->config['storage_limit_gb'] * 1073741824);
        if ($limit > 0) {
            $report = $this->report();
            $used = (int)($report['bytes'] ?? 0);
            if ($used + $size > $limit) {
                throw new RuntimeException('The file store is full ('.
                    self::humanBytes($used).' of '.self::humanBytes($limit).' used)', 507);
            }
        }

        $quota = (int)round((float)$this->config['user_quota_gb'] * 1073741824);
        if ($quota > 0 && $userId !== null) {
            $this->sweepOccasionally();
            $used = $this->ledger->usage($userId);
            if ($used + $size > $quota) {
                throw new RuntimeException('You have used '.self::humanBytes($used).
                    ' of your '.self::humanBytes($quota).' quota', 507);
            }
        }
    }

    /**
     * A byte count for people. Limits are configured in GB but can be set low;
     * "0 GB of 0 GB used" is not an answer, so the unit follows the number.
     */
    public static function humanBytes(int $n): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float)$n;
        while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
        return ($v >= 100 || $i === 0 ? round($v) : round($v, 1)).' '.$units[$i];
    }
}
