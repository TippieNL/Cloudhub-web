<?php
declare(strict_types=1);
namespace CloudHub\Helpers;

use InvalidArgumentException;
use Throwable;

/**
 * The application cache: phpFastCache behind a façade that cannot fail a request.
 *
 * Every operation is best effort. A backend that cannot be built, reached or
 * written is logged once and treated as a miss for the rest of the request,
 * so the worst a broken cache can do is make CloudHub as slow as it was
 * without one. A deployment without vendor/ runs exactly as before.
 *
 * What is cached, and why each entry is safe to reuse, is decided by the
 * callers (see FileCache). This class supplies three things they build on:
 *
 *   get/set       the backend, chosen by CACHE_DRIVER
 *   generation()  a token that every request which may change the file store
 *                 replaces as it finishes (bumpGeneration()), so an entry
 *                 recorded under an older token is never believed again
 *   minComputeMs  the least time an answer must have cost before it is worth
 *                 remembering; on storage where listing is fast, nothing is
 *                 cached at all and nothing can be stale
 *
 * phpFastCache is loaded lazily, and only for a key that could be stored.
 * Without OPcache its classes cost ~3-4 ms to compile on every request that
 * loads them; mayHold() means a folder that was never slow never pays that,
 * and neither does an installation whose storage is quick.
 *
 * Single host only: the generation token and the stamps are files under
 * CACHE_PATH, shared by every PHP process on the machine but not beyond it.
 */
final class Cache
{
    /** Longest lifetime any entry may have. Also how long `active` is trusted. */
    public const MAX_TTL = 3600;

    /** How often the Files backend is swept for entries nobody read back. */
    private const GC_INTERVAL = 3600;

    /** How long one sweep may take; the next one carries on. */
    private const GC_BUDGET_MS = 100;

    /** @var array<string,mixed> */
    private static array $settings = [];

    /** Why the cache is off by configuration, or null when it is configured. */
    private static ?string $off = 'not configured';

    /** Why the backend failed during this request, if it did. */
    private static ?string $broken = null;

    private static ?\Psr\SimpleCache\CacheInterface $pool = null;

    /**
     * Read the CACHE_* settings from the application config.
     *
     * Refuses, rather than merely warns about, a CACHE_PATH inside ROOT_DIR or
     * public/. phpFastCache reads its files back with unserialize(), which
     * instantiates objects, so a cache directory that account holders can
     * upload into is a code-execution hole; one the web server serves hands
     * the folder listings it holds to anyone who asks.
     */
    public static function configure(array $config, ?string $projectDir = null): void
    {
        // Where a relative CACHE_PATH, and the default one, are taken from.
        $project = rtrim(str_replace('\\', '/', $projectDir ?? dirname(__DIR__, 2)), '/');
        $driver = strtolower(trim((string)($config['cache_driver'] ?? 'files')));
        $path = str_replace('\\', '/', trim((string)($config['cache_path'] ?? '')));
        if ($path === '') $path = $project.'/storage/.cache/phpfastcache';
        elseif (!str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:/#', $path)) $path = $project.'/'.$path;
        $path = rtrim($path, '/');
        $root = (string)($config['root_dir'] ?? '');

        self::$settings = [
            'driver' => $driver,
            'path' => $path,
            'ttl' => max(0, min(self::MAX_TTL, (int)($config['cache_ttl_seconds'] ?? 30))),
            'minComputeMs' => max(0, (int)($config['cache_min_compute_ms'] ?? 50)),
            // Several installations may share one APCu or Redis; their keys
            // must not meet. Files already get a directory each.
            'namespace' => 'ch'.substr(sha1($root !== '' ? $root : $project), 0, 10),
            'redis' => [
                'host' => (string)($config['cache_redis_host'] ?? '127.0.0.1'),
                'port' => (int)($config['cache_redis_port'] ?? 6379),
                'password' => (string)($config['cache_redis_password'] ?? ''),
                'database' => (int)($config['cache_redis_database'] ?? 0),
            ],
        ];
        self::$pool = null;
        self::$broken = null;
        self::$off = match (true) {
            in_array($driver, ['none', 'off', 'false', '0', ''], true) => 'disabled by CACHE_DRIVER',
            !in_array($driver, ['files', 'apcu', 'redis'], true) => 'unknown CACHE_DRIVER "'.$driver.'"',
            $root !== '' && self::within($path, $root) => 'CACHE_PATH is inside ROOT_DIR, where account holders can write',
            self::within($path, $project.'/public') => 'CACHE_PATH is inside public/, which the web server serves',
            default => null,
        };
        if (self::$off !== null && self::$off !== 'disabled by CACHE_DRIVER') {
            error_log('[cache] off: '.self::$off);
        }
    }

    /** Whether caching is on: configured so, and not failed during this request. Loads nothing. */
    public static function enabled(): bool
    {
        return self::$off === null && self::$broken === null;
    }

    public static function ttl(): int
    {
        return (int)(self::$settings['ttl'] ?? 0);
    }

    public static function minComputeMs(): int
    {
        return (int)(self::$settings['minComputeMs'] ?? 0);
    }

    /** Whether an answer that took since $startedNs is worth remembering. */
    public static function worthKeeping(int $startedNs): bool
    {
        return (hrtime(true) - $startedNs) / 1e6 >= self::minComputeMs();
    }

    /**
     * A stored value, or null on a miss or any failure.
     *
     * Returns null without touching the backend when this key cannot have
     * been stored; see mayHold().
     */
    public static function get(string $key): mixed
    {
        $key = self::key($key);
        if (!self::enabled() || !self::mayHold($key)) return null;
        $pool = self::pool();
        if ($pool === null) return null;
        try {
            return $pool->get($key);
        } catch (Throwable $e) {
            // A damaged entry would fail the same way on every read; drop it.
            self::discard($key);
            self::fail('read', $e);
            return null;
        }
    }

    public static function set(string $key, mixed $value, int $ttl): bool
    {
        $key = self::key($key);
        if (!self::enabled() || $ttl <= 0) return false;
        $pool = self::pool();
        if ($pool === null) return false;
        try {
            if (!$pool->set($key, $value, min($ttl, self::MAX_TTL))) {
                self::fail('write', null);
                return false;
            }
        } catch (Throwable $e) {
            self::fail('write', $e);
            return false;
        }
        self::stamp('active');
        self::collectGarbage();
        return true;
    }

    public static function delete(string $key): void
    {
        $key = self::key($key);
        if (!self::enabled() || !self::mayHold($key)) return;
        $pool = self::pool();
        if ($pool === null) return;
        try { $pool->delete($key); } catch (Throwable $e) { self::fail('delete', $e); }
    }

    /**
     * The current generation token, created if there is none.
     *
     * Read before computing anything that will be stored under it, never
     * after: a change that lands while the answer is being computed then
     * leaves the answer filed under a token that is already out of date.
     *
     * Null means no token can be kept, and so nothing may be cached.
     */
    public static function generation(): ?string
    {
        if (self::$off !== null) return null;
        $file = self::$settings['path'].'/generation';
        $token = @file_get_contents($file);
        if (is_string($token) && preg_match('/^[0-9a-f]{24}$/', $token)) return $token;
        return self::writeGeneration();
    }

    /**
     * Retire every entry recorded under the current generation.
     *
     * Run as each request that may have changed the file store finishes. The
     * token is replaced by rename, which is atomic, so a reader sees the old
     * one or the new one and never a torn one. If it cannot be replaced it is
     * deleted, which retires it just as well: the next reader starts another.
     */
    public static function bumpGeneration(): void
    {
        if (self::$off !== null) return;
        if (self::writeGeneration() !== null) return;
        $file = self::$settings['path'].'/generation';
        if (@unlink($file) || !file_exists($file)) return;
        error_log('[cache] could not retire the cache generation; cached listings may be stale for up to '
            .self::ttl().' seconds');
    }

    /**
     * What the cache is doing, for the storage diagnostics.
     *
     * With $probe, a real write and read-back rather than a guess: the same
     * reasoning that makes StorageDiagnostics probe directories instead of
     * trusting is_writable().
     */
    public static function status(bool $probe = false): array
    {
        $status = [
            'driver' => (string)(self::$settings['driver'] ?? 'files'),
            'path' => (string)(self::$settings['path'] ?? ''),
            'enabled' => self::enabled(),
            'reason' => self::$off ?? self::$broken,
            'ttlSeconds' => self::ttl(),
            'minComputeMs' => self::minComputeMs(),
            'working' => null,
        ];
        if (!$probe || !self::enabled()) return $status;
        $key = self::key('probe_'.bin2hex(random_bytes(4)));
        $value = bin2hex(random_bytes(8));
        $pool = self::pool();
        try {
            $status['working'] = $pool !== null && $pool->set($key, $value, 60) && $pool->get($key) === $value;
            if ($pool !== null) $pool->delete($key);
        } catch (Throwable $e) {
            self::fail('probe', $e);
            $status['working'] = false;
        }
        $status['enabled'] = self::enabled();
        $status['reason'] = self::$off ?? self::$broken;
        return $status;
    }

    /** Forget configuration and backend. Used by tests. */
    public static function reset(): void
    {
        self::$settings = [];
        self::$off = 'not configured';
        self::$broken = null;
        self::$pool = null;
    }

    /* ---- internals ------------------------------------------------------ */

    private static function pool(): ?\Psr\SimpleCache\CacheInterface
    {
        if (self::$pool !== null || !self::enabled()) return self::$pool;
        try {
            if (!class_exists(\Phpfastcache\Helper\Psr16Adapter::class)) {
                $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
                if (is_file($autoload)) require_once $autoload;
            }
            if (!class_exists(\Phpfastcache\Helper\Psr16Adapter::class)) {
                self::$broken = 'phpFastCache is not installed (vendor/ is missing)';
                return null;
            }
            [$name, $options] = self::driverOptions();
            self::$pool = new \Phpfastcache\Helper\Psr16Adapter($name, $options);
        } catch (Throwable $e) {
            self::fail('start', $e);
        }
        return self::$pool;
    }

    /** @return array{0:string,1:\Phpfastcache\Config\ConfigurationOptionInterface} */
    private static function driverOptions(): array
    {
        // No in-process copy of each item read: a request reads an entry once,
        // and a large folder's listing would otherwise sit in memory twice.
        $common = ['itemDetailedDate' => false, 'defaultTtl' => self::MAX_TTL, 'useStaticItemCaching' => false];
        switch (self::$settings['driver']) {
            case 'apcu':
                return ['Apcu', new \Phpfastcache\Drivers\Apcu\Config($common)];
            case 'redis':
                $r = self::$settings['redis'];
                return ['Redis', new \Phpfastcache\Drivers\Redis\Config($common + [
                    'host' => $r['host'], 'port' => $r['port'], 'password' => $r['password'],
                    'database' => $r['database'],
                    // A cache that is down must cost a request little; the
                    // default of five seconds would make every page wait.
                    'timeout' => 1,
                ])];
            default:
                return ['Files', new \Phpfastcache\Drivers\Files\Config($common + [
                    'path' => self::$settings['path'],
                    // Fixed: the default names the directory after the Host
                    // header, so any client could make it create new ones.
                    'securityKey' => 'cloudhub',
                    // Never the system temp directory, which every local
                    // account can write to. See configure() for why that matters.
                    'autoTmpFallback' => false,
                    // Written to a temporary file and renamed into place, so a
                    // reader never sees half an entry.
                    'secureFileManipulation' => true,
                    'defaultChmod' => 0775,
                ])];
        }
    }

    /**
     * The backend's key for one of ours. A malformed key is a bug in the
     * caller, so it throws rather than quietly turning the cache off.
     */
    private static function key(string $key): string
    {
        if (!preg_match('/^[a-z0-9_]{1,80}$/', $key)) {
            throw new InvalidArgumentException('Cache keys are lower-case letters, digits and underscores');
        }
        return (self::$settings['namespace'] ?? 'ch').'_'.$key;
    }

    /**
     * Whether $key may be stored, decided without loading the backend.
     *
     * One stat of a local file. For Files it is the entry's own file, so a
     * folder that was never slow enough to keep costs nothing to look up even
     * where others were. For a shared backend it is `active`, which every
     * write touches: nothing stored within MAX_TTL means nothing is live.
     */
    private static function mayHold(string $key): bool
    {
        if (self::$settings['driver'] === 'files') return is_file(self::filesPath($key));
        $mtime = @filemtime(self::$settings['path'].'/active');
        return $mtime !== false && $mtime >= time() - self::MAX_TTL;
    }

    /**
     * Remove an entry that cannot be read back.
     *
     * phpFastCache's own delete reads the item first, and fails on a damaged
     * one exactly as the read did -- which would leave it failing every read
     * until it was swept. So a Files entry is unlinked directly.
     */
    private static function discard(string $key): void
    {
        if (self::$settings['driver'] === 'files') {
            @unlink(self::filesPath($key));
            return;
        }
        try { self::$pool?->delete($key); } catch (Throwable) {}
    }

    /** The Files driver's own directory: CACHE_PATH, its security key, its name. */
    private static function filesDir(): string
    {
        return self::$settings['path'].'/cloudhub/Files';
    }

    /**
     * Where phpFastCache 9's Files driver keeps an entry: the key's md5, in
     * buckets named by its first two and next two characters. See
     * IOHelperTrait::getFilePath(); pinned by the cache test.
     */
    private static function filesPath(string $key): string
    {
        $hash = md5($key);
        return self::filesDir().'/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash.'.txt';
    }

    private static function writeGeneration(): ?string
    {
        $dir = self::$settings['path'];
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        $token = bin2hex(random_bytes(12));
        $tmp = $dir.'/generation.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $token) === strlen($token) && @rename($tmp, $dir.'/generation')) return $token;
        @unlink($tmp);
        return null;
    }

    private static function stamp(string $name): void
    {
        $dir = self::$settings['path'];
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
        @touch($dir.'/'.$name);
    }

    /**
     * Drop Files entries that expired without being read again.
     *
     * phpFastCache deletes an expired file only when something asks for it,
     * and a folder that is never opened again is never asked for, so without
     * this the directory would only grow. Every entry lives at most MAX_TTL,
     * which makes anything older rubbish by definition -- including the
     * temporary files of a write that was interrupted.
     */
    private static function collectGarbage(): void
    {
        if (self::$settings['driver'] !== 'files') return;
        $stamp = self::$settings['path'].'/gc';
        $last = @filemtime($stamp);
        if ($last !== false && $last > time() - self::GC_INTERVAL) return;
        self::stamp('gc');

        $cutoff = time() - self::MAX_TTL - 60;
        $deadline = hrtime(true) + self::GC_BUDGET_MS * 1_000_000;
        // Two levels of buckets, as filesPath() lays them out.
        foreach (self::subdirectories(self::filesDir()) as $outer) {
            $emptied = false;
            foreach (self::subdirectories($outer) as $bucket) {
                $removed = false;
                foreach (scandir($bucket) ?: [] as $file) {
                    if (hrtime(true) > $deadline) return;
                    $full = $bucket.'/'.$file;
                    if ($file === '.' || $file === '..' || !is_file($full)) continue;
                    $mtime = @filemtime($full);
                    if ($mtime !== false && $mtime < $cutoff && @unlink($full)) $removed = true;
                }
                // Only a bucket this sweep emptied, and only if it is still
                // empty -- which rmdir() itself decides. One a writer has just
                // created, and not yet written into, is left for it.
                if ($removed && @rmdir($bucket)) $emptied = true;
            }
            if ($emptied) @rmdir($outer);
        }
    }

    /** @return list<string> */
    private static function subdirectories(string $dir): array
    {
        $out = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if ($name !== '.' && $name !== '..' && is_dir($dir.'/'.$name)) $out[] = $dir.'/'.$name;
        }
        return $out;
    }

    private static function fail(string $operation, ?Throwable $e): void
    {
        if (self::$broken === null) {
            error_log('[cache] '.$operation.' failed'.($e ? ': '.get_class($e).': '.$e->getMessage() : '')
                .'; caching is off for the rest of this request');
        }
        self::$broken = $operation.' failed';
        self::$pool = null;
    }

    /** Whether $path is $parent or below it, compared as the filesystem may compare it. */
    private static function within(string $path, string $parent): bool
    {
        $p = strtolower(self::resolve($path));
        $r = strtolower(self::resolve($parent));
        return $p === $r || str_starts_with(rtrim($p, '/').'/', rtrim($r, '/').'/');
    }

    /**
     * realpath() for a path that may not exist yet: its nearest existing
     * ancestor resolved, with the rest appended. A symlinked CACHE_PATH that
     * points into ROOT_DIR must be caught as surely as a literal one.
     */
    private static function resolve(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $rest = [];
        while ($path !== '' && $path !== '.' && !file_exists($path)) {
            array_unshift($rest, basename($path));
            $parent = dirname($path);
            if ($parent === $path) break;
            $path = $parent;
        }
        $real = realpath($path === '' ? '/' : $path);
        $base = rtrim($real === false ? $path : str_replace('\\', '/', $real), '/');
        $joined = $base.($rest ? '/'.implode('/', $rest) : '');
        return $joined === '' ? '/' : $joined;
    }
}
