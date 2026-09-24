<?php
declare(strict_types=1);
namespace CloudHub\Services;

/**
 * What the storage this install writes to can actually do.
 *
 * Split out of tools/storage-check.php so the same report can be rendered as
 * text on a command line and as JSON over an authenticated route. A KSWEB
 * install may not expose PHP CLI at all, and a shell from a different PHP --
 * Termux, say -- reports that PHP's ini, user and permissions rather than the
 * ones serving requests, which are the values a deployment question is
 * actually about.
 *
 * One source of truth deliberately: two copies of this would disagree the
 * moment either was touched, the same reason FileService::entry() is shared
 * between listing and search.
 *
 * Returns data and never prints. Callers decide how to show it, and the route
 * decides what an administrator is allowed to see -- which is why directories
 * are reported as a count and never as a list of names. The original
 * storage-check.php was removed from the web root precisely for dumping one.
 */
final class StorageDiagnostics
{
    /** Block size for the throughput probe: what UploadService::append() uses. */
    private const BLOCK_BYTES = 1048576;

    public function __construct(private array $config, private string $projectDir) {}

    /** @return array<string,string> label => absolute path */
    public function targets(): array
    {
        return [
            'ROOT_DIR (served files)' => (string)$this->config['root_dir'],
            'upload staging' => ((string)$this->config['upload_staging_dir'])
                ?: rtrim((string)$this->config['root_dir'], '/').'/.uploads',
            'thumbnail cache' => $this->projectDir.'/storage/.thumbnails',
            'logs' => $this->projectDir.'/logs',
        ];
    }

    /**
     * Which of those the application creates for itself when it first needs
     * them. Only ROOT_DIR has to exist already; reporting the others as faults
     * before anything has used them is a false alarm, and this diagnostic
     * exists to end guesswork rather than manufacture it.
     *
     * @return list<string>
     */
    private function createdOnDemand(): array
    {
        return ['upload staging', 'thumbnail cache', 'logs'];
    }

    /**
     * The whole report.
     *
     * $measureMb of 0 skips the throughput probe, which writes and reads that
     * many megabytes. A caller that runs on every request should leave it off:
     * making the server do expensive work on demand is the objection that kept
     * ?refresh off /api/storage/me.
     */
    public function report(int $measureMb = 0): array
    {
        $paths = [];
        $problems = [];

        foreach ($this->targets() as $label => $path) {
            $real = realpath($path);
            $row = [
                'label' => $label,
                'path' => $path,
                'resolved' => $real === false ? null : $real,
                'exists' => file_exists($path),
                'isDirectory' => is_dir($path),
                'readable' => is_readable($path),
                'writable' => null,
                'entries' => null,
            ];

            if (!is_dir($path)) {
                // Not yet created is only a fault if something else has to
                // create it. Where the application makes it itself, what
                // matters is whether it will be able to.
                $parentWritable = self::nearestExistingWritable($path);
                $row['createdOnDemand'] = in_array($label, $this->createdOnDemand(), true);
                $row['parentWritable'] = $parentWritable;
                if (!$row['createdOnDemand']) {
                    $problems[] = $label.' does not exist, and the application does not create it.';
                } elseif (!$parentWritable) {
                    $problems[] = $label.' will be created on first use, but PHP cannot write to the '
                        .'directory above it.';
                }
                $paths[] = $row;
                continue;
            }

            // A real create/write/delete rather than is_writable(), which is
            // unreliable on Android shared storage -- the same reason
            // UploadService probes instead of trusting the permission bits.
            $probe = rtrim($path, '/').'/.storage-check-'.bin2hex(random_bytes(6));
            $written = @file_put_contents($probe, 'probe');
            $row['writable'] = $written !== false && @unlink($probe);
            if (!$row['writable']) {
                @unlink($probe);
                $problems[] = $label.' is not writable by the PHP user.';
            }

            // A count, never the names.
            $entries = is_readable($path) ? (scandir($path) ?: []) : [];
            $row['entries'] = count(array_filter($entries, static fn($n) => $n !== '.' && $n !== '..'));
            $paths[] = $row;
        }

        // Computed before the array is built rather than inside it: runtime()
        // appends its own warnings to $problems, and relying on the evaluation
        // order of array elements to pick them up is the sort of thing that
        // quietly stops working when somebody reorders the keys.
        $runtime = $this->runtime($problems);
        $finishing = $this->finishing();
        $throughput = $measureMb > 0 ? $this->throughput($measureMb) : null;
        $cache = $this->cache($problems);

        return [
            'paths' => $paths,
            'runtime' => $runtime,
            'finishing' => $finishing,
            'throughput' => $throughput,
            'cache' => $cache,
            'problems' => $problems,
        ];
    }

    /**
     * Whether the application cache is on, and actually working.
     *
     * A cache that has turned itself off costs speed and nothing else, so no
     * error would ever say so -- on a phone it just feels slow. The probe is a
     * real write and read-back, not a guess from the configuration.
     *
     * @param list<string> $problems collected by reference
     */
    private function cache(array &$problems): array
    {
        \CloudHub\Helpers\Cache::configure($this->config, $this->projectDir);
        $status = \CloudHub\Helpers\Cache::status(true);
        if ($status['reason'] !== 'disabled by CACHE_DRIVER' && ($status['working'] !== true)) {
            $problems[] = 'The application cache is not working ('.($status['reason'] ?? 'the probe failed')
                .'); listings and search run uncached.';
        }
        return $status;
    }

    /** @param list<string> $problems collected by reference through the return */
    private function runtime(array &$problems): array
    {
        $chunkBytes = max(1, (int)$this->config['upload_chunk_mb']) * 1048576;
        $limits = [];
        foreach (['memory_limit', 'max_execution_time', 'output_buffering', 'post_max_size', 'upload_max_filesize'] as $key) {
            $limits[$key] = (string)ini_get($key);
        }

        // Chunks arrive as a raw PUT body, which PHP does not measure against
        // post_max_size -- but a server in front of it may impose its own, and
        // the README asks for these to sit above the chunk size.
        $warnings = [];
        foreach (['post_max_size', 'upload_max_filesize'] as $key) {
            $bytes = self::iniBytes($limits[$key]);
            if ($bytes > 0 && $bytes <= $chunkBytes) {
                $warnings[] = $key.' ('.$limits[$key].') is not above the '
                    .$this->config['upload_chunk_mb'].' MB chunk size; lower UPLOAD_CHUNK_MB or raise it.';
            }
        }
        /*
         * A 32-bit build cannot address past 2 GB.
         *
         * filesize(), fseek() and the offsets the chunk protocol writes at all
         * go through PHP's signed integer, so on a 32-bit runtime a file beyond
         * 2^31 bytes is accepted at init() and then mis-handled on the way to
         * disk. At the old default of 2048 MB the question never arose -- that
         * is exactly the boundary -- so it was documented as a recommendation.
         * Above it, it is a requirement, and this is the only place that can
         * tell the operator whether their build actually meets it.
         */
        if (PHP_INT_SIZE < 8 && (int)$this->config['max_upload_mb'] > 2047) {
            $warnings[] = 'MAX_UPLOAD_MB is '.(int)$this->config['max_upload_mb']
                .' MB but this is a 32-bit PHP build, which cannot address files beyond 2 GB; '
                .'lower MAX_UPLOAD_MB to 2047 or install a 64-bit runtime.';
        }

        foreach ($warnings as $warning) $problems[] = $warning;

        return [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'limits' => $limits,
            'chunkMb' => (int)$this->config['upload_chunk_mb'],
            'warnings' => $warnings,
            'commit' => $this->commit(),
            'sources' => $this->sourceFingerprints(),
        ];
    }

    /**
     * Whether finishing an upload renames or copies.
     *
     * UploadService::complete() renames the staged file into place and falls
     * back to copying every byte when rename() reports EXDEV, so staging on a
     * different filesystem means writing the whole file a second time in one
     * blocking burst at the end.
     */
    private function finishing(): array
    {
        $targets = $this->targets();
        $stagingDev = self::deviceOf($targets['upload staging']);
        $rootDev = self::deviceOf($targets['ROOT_DIR (served files)']);
        $same = $stagingDev !== null && $rootDev !== null && $stagingDev === $rootDev;

        return [
            'stagingDevice' => $stagingDev,
            'rootDevice' => $rootDev,
            'stagingExists' => is_dir($targets['upload staging']),
            'sameFilesystem' => ($stagingDev === null || $rootDev === null) ? null : $same,
            'verdict' => match (true) {
                $stagingDev === null || $rootDev === null => 'Could not determine; one of the paths does not resolve.',
                $same => 'Same filesystem — an instant rename, nothing is copied.',
                default => 'DIFFERENT filesystems — every completed upload is copied in full, a second '
                    .'write of the whole file. Point UPLOAD_STAGING_DIR at a directory on the same '
                    .'filesystem as ROOT_DIR, or leave it empty for ROOT_DIR/.uploads.',
            },
        ];
    }

    /** Write then read $megabytes through PHP's own streams, as an upload would. */
    private function throughput(int $megabytes): array
    {
        $out = [];
        foreach (['upload staging', 'ROOT_DIR (served files)'] as $label) {
            $dir = $this->targets()[$label];
            $out[$label] = is_dir($dir) ? $this->measure($dir, $megabytes) : null;
        }
        $out['megabytes'] = $megabytes;
        return $out;
    }

    private function measure(string $dir, int $megabytes): ?array
    {
        $file = rtrim($dir, '/').'/.storage-check-io-'.bin2hex(random_bytes(6));
        $block = str_repeat("\0", self::BLOCK_BYTES);

        $handle = @fopen($file, 'wb');
        if ($handle === false) return null;
        $start = microtime(true);
        for ($i = 0; $i < $megabytes; $i++) {
            if (fwrite($handle, $block) === false) { fclose($handle); @unlink($file); return null; }
        }
        fflush($handle);
        fclose($handle);
        $writeSeconds = microtime(true) - $start;

        clearstatcache(true, $file);
        $handle = @fopen($file, 'rb');
        if ($handle === false) { @unlink($file); return null; }
        $start = microtime(true);
        while (!feof($handle)) { if (fread($handle, self::BLOCK_BYTES) === false) break; }
        fclose($handle);
        $readSeconds = microtime(true) - $start;

        @unlink($file);
        return [
            'writeMbPerSecond' => round($megabytes / max($writeSeconds, 0.000001), 1),
            'readMbPerSecond' => round($megabytes / max($readSeconds, 0.000001), 1),
        ];
    }

    /**
     * Which filesystem a path is on, resolving to its nearest existing ancestor.
     *
     * A staging directory that has not been created yet is not on a different
     * filesystem; it is on whichever one its parent is, which is where it will
     * be created. Reporting that as a cross-device copy is a false alarm, and
     * the first version of this diagnostic did exactly that.
     */
    private static function deviceOf(string $path): ?int
    {
        $candidate = $path;
        for ($i = 0; $i < 32; $i++) {
            $stat = @stat($candidate);
            if ($stat !== false) return (int)$stat['dev'];
            $parent = dirname($candidate);
            if ($parent === $candidate) return null;
            $candidate = $parent;
        }
        return null;
    }

    /** Whether the nearest directory that does exist above $path can be written to. */
    private static function nearestExistingWritable(string $path): bool
    {
        $candidate = dirname($path);
        for ($i = 0; $i < 32; $i++) {
            if (is_dir($candidate)) {
                $probe = rtrim($candidate, '/').'/.storage-check-'.bin2hex(random_bytes(6));
                $ok = @file_put_contents($probe, 'probe') !== false;
                @unlink($probe);
                return $ok;
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) return false;
            $candidate = $parent;
        }
        return false;
    }

    /** So "is the fix actually deployed" stops being a question. */
    private function commit(): ?string
    {
        if (!is_dir($this->projectDir.'/.git') || !function_exists('shell_exec')) return null;
        $head = @shell_exec('git -C '.escapeshellarg($this->projectDir).' rev-parse --short HEAD 2>/dev/null');
        $head = is_string($head) ? trim($head) : '';
        return $head === '' ? null : $head;
    }

    /**
     * Short content hashes of the files these questions keep coming back to.
     *
     * commit() returns null whenever the deployment is a copy rather than a
     * checkout, which is the ordinary way this is installed on a phone -- and
     * then "is that fix actually running" has no answer. A hash of the file
     * itself has one, and can be compared against any revision without needing
     * git on the device.
     *
     * @return array<string,?string>
     */
    private function sourceFingerprints(): array
    {
        $out = [];
        foreach ([
            'UploadService.php' => '/src/Services/UploadService.php',
            'FileService.php' => '/src/Services/FileService.php',
            'FileCache.php' => '/src/Services/FileCache.php',
            'Cache.php' => '/src/Helpers/Cache.php',
            'index.php' => '/public/index.php',
            'app.js' => '/public/assets/js/app.js',
        ] as $label => $relative) {
            $file = $this->projectDir.$relative;
            $hash = is_file($file) ? @hash_file('sha256', $file) : false;
            $out[$label] = $hash === false ? null : substr((string)$hash, 0, 12);
        }
        return $out;
    }

    /** Bytes for a php.ini shorthand size such as "8M" or "512K". */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        $unit = strtolower($value[strlen($value) - 1]);
        $n = (int)$value;
        return match ($unit) { 'g' => $n * 1024 ** 3, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }
}
