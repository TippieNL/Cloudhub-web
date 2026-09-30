<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

/**
 * Which workers are alive, from files a worker touches as it works.
 *
 * Two questions depend on it. The web app asks whether a command-line worker
 * is running, to decide whether it has to run queued jobs itself. And a worker
 * asks whether the worker a job is recorded against is still there: one that
 * is gone from this machine -- killed, crashed, out of memory -- cannot finish
 * the job, which then goes back to the queue at once instead of after its
 * heartbeat goes stale.
 *
 * A worker's id is kind:host:pid:random. A job is only ever taken back from a
 * worker on this host whose process has gone AND whose file has stopped being
 * touched, so neither a recycled pid nor a second machine sharing the
 * database can make a live worker look dead. Kept beside the other caches,
 * outside the storage root.
 */
final class WorkerRegistry
{
    /**
     * How long a worker's file stays fresh without being touched. A worker
     * touches it every couple of seconds while idle and with each heartbeat
     * while it works; one busy inside a step that cannot report looks absent
     * for that long, which costs nothing worse than the web app running a
     * queued job itself meanwhile.
     */
    public const ALIVE_SECONDS = 10;

    /** At most one touch per this many seconds, per worker. */
    private const BEAT_SECONDS = 2;

    /** @var array<string,float> */
    private array $beaten = [];

    public function __construct(private readonly string $dir) {}

    /** A new worker id: 'cli' for tools/worker.php, 'web' for a request running jobs itself. */
    public static function newId(string $kind): string
    {
        return ($kind === 'cli' ? 'cli' : 'web').':'.self::host().':'.getmypid().':'.bin2hex(random_bytes(3));
    }

    /** This machine's name as a worker id carries it. */
    public static function host(): string
    {
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string)gethostname()) ?? '';
        return substr($host === '' ? 'localhost' : $host, 0, 40);
    }

    /** @return array{kind:string,host:string,pid:int}|null */
    public static function parse(string $id): ?array
    {
        if (!preg_match('/^(cli|web):([A-Za-z0-9.-]{1,40}):(\d{1,10}):[0-9a-f]{6}$/', $id, $m)) return null;
        return ['kind' => $m[1], 'host' => $m[2], 'pid' => (int)$m[3]];
    }

    /**
     * Whether a process exists: true, false, or null when this PHP cannot tell
     * (no posix extension and no /proc), which callers take as alive.
     */
    public static function pidAlive(int $pid): ?bool
    {
        if ($pid <= 0) return false;
        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) return true;
            // EPERM: it exists, it is just not ours to signal.
            return function_exists('posix_get_last_error') && posix_get_last_error() === 1;
        }
        if (is_dir('/proc/self')) return is_dir('/proc/'.$pid);
        return null;
    }

    /** Say this worker is alive. Cheap to call often. */
    public function beat(string $id, bool $force = false): void
    {
        $now = microtime(true);
        if (!$force && $now - ($this->beaten[$id] ?? 0.0) < self::BEAT_SECONDS) return;
        $this->beaten[$id] = $now;
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) return;
        @touch($this->file($id));
    }

    /** A worker that is stopping says so, rather than looking alive for another half minute. */
    public function forget(string $id): void
    {
        unset($this->beaten[$id]);
        @unlink($this->file($id));
    }

    public function isFresh(string $id): bool
    {
        clearstatcache(true, $this->file($id));
        $at = @filemtime($this->file($id));
        return $at !== false && time() - $at < self::ALIVE_SECONDS;
    }

    /**
     * Workers seen recently, optionally of one kind.
     *
     * @return list<string>
     */
    public function alive(?string $kind = null): array
    {
        $out = [];
        foreach (glob($this->dir.'/*.worker') ?: [] as $file) {
            $id = str_replace('_', ':', basename($file, '.worker'));
            $parsed = self::parse($id);
            if ($parsed === null || ($kind !== null && $parsed['kind'] !== $kind)) continue;
            if ($this->isFresh($id)) $out[] = $id;
        }
        return $out;
    }

    /** Remove the files of workers not seen for a day. */
    public function prune(int $olderThan = 86400): void
    {
        foreach (glob($this->dir.'/*.worker') ?: [] as $file) {
            $at = @filemtime($file);
            if ($at !== false && time() - $at > $olderThan) @unlink($file);
        }
    }

    private function file(string $id): string
    {
        return $this->dir.'/'.str_replace(':', '_', $id).'.worker';
    }
}
