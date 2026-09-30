<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Services\FileService;
use RuntimeException;

/**
 * Defaults for a job type, and the checks and steps several of them share.
 *
 * The defaults are the cautious ones: the job needs the editor role, is taken
 * to write to the store, may be cancelled, and leaves nothing behind when it
 * fails.
 */
abstract class BaseJobType implements JobType
{
    public function capability(array $payload): string { return 'write'; }
    public function writesStore(array $payload): bool { return true; }
    public function clientCreatable(): bool { return true; }
    public function cancelableWhilePending(): bool { return true; }
    public function cancelableWhileRunning(): bool { return true; }
    public function exclusive(): bool { return false; }

    public function discard(string $jobId, JobEnvironment $env): void
    {
        $env->removeWorkDir($jobId);
    }

    public function download(array $job, JobEnvironment $env): ?array { return null; }

    /**
     * A list of paths from a client, each resolved to an existing item and
     * returned as its canonical relative path.
     *
     * @return list<string>
     */
    protected static function existingPaths(mixed $value, FileService $fs, int $max = 500): array
    {
        if (!is_array($value) || !array_is_list($value) || !$value) throw new RuntimeException('No items were given', 400);
        if (count($value) > $max) throw new RuntimeException('Too many items in one task (at most '.$max.')', 413);
        $out = [];
        foreach ($value as $path) {
            if (!is_string($path) || $path === '' || strlen($path) > 4096) throw new RuntimeException('Invalid path in the selection', 422);
            $rel = $fs->relative($fs->existing($path));
            if ($rel === '/') throw new RuntimeException('The storage root itself cannot be part of this operation', 400);
            self::assertUtf8($rel);
            $out[] = $rel;
        }
        return array_values(array_unique($out));
    }

    /** One existing folder from a client, as its canonical relative path. */
    protected static function existingFolder(mixed $value, FileService $fs): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 4096) throw new RuntimeException('A folder is required', 422);
        $full = $fs->existing($value);
        if (!is_dir($full)) throw new RuntimeException('That is not a folder', 400);
        $rel = $fs->relative($full);
        self::assertUtf8($rel);
        return $rel;
    }

    /** One existing file from a client, as its canonical relative path. */
    protected static function existingFile(mixed $value, FileService $fs): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 4096) throw new RuntimeException('A file is required', 422);
        $full = $fs->existing($value);
        if (!is_file($full)) throw new RuntimeException('That is not a file', 400);
        $rel = $fs->relative($full);
        self::assertUtf8($rel);
        return $rel;
    }

    /**
     * A path that is not UTF-8 cannot be stored as JSON, and the UI could not
     * name it either. Such files stay reachable over WebDAV.
     */
    protected static function assertUtf8(string $path): void
    {
        if (!mb_check_encoding($path, 'UTF-8')) throw new RuntimeException('Names that are not valid UTF-8 cannot be used in a background task', 400);
    }

    /** "3 items", "1 item". */
    protected static function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }

    /**
     * Move a staged item into the folder $dir as $name, or as a free variant
     * of it, and return where it landed.
     *
     * Never over anything: a name that is taken gets " (2)" when overwriting
     * is allowed and is refused when it is not, as the copy route decides.
     * The claim is proven immediately before the rename -- the one change
     * anyone else can see -- and $record is handed the chosen path before the
     * rename happens, for the caller to save. An attempt that crashed between
     * the two then finds its item already at that path when it carries on,
     * instead of placing it a second time.
     *
     * @param callable(string): void $record receives the relative target
     */
    protected static function moveIntoPlace(JobContext $ctx, string $staged, string $dir, string $name, callable $record): string
    {
        $fs = $ctx->env()->files;
        $fs->writable();
        $target = $fs->childPath($dir, $name);
        if (file_exists($target) || is_link($target)) {
            if (!$ctx->env()->config['allow_overwrite']) throw new RuntimeException('An item called "'.$name.'" is already there', 409);
            $target = $fs->freeName($target);
        }
        $ctx->checkpoint(true);
        $record($fs->relative($target));
        if (!@rename($staged, $target)) throw new RuntimeException('Unable to move "'.$name.'" into place', 500);
        return $target;
    }

    /**
     * Charge every file at or below $target to the job's owner, as the
     * synchronous routes charge what an account uploads or copies. Recording
     * replaces a path's row, so doing it twice after a resume charges once.
     */
    protected static function recordOwnership(JobContext $ctx, string $target): void
    {
        $env = $ctx->env();
        $fs = $env->files;
        $ledger = $env->ledger();
        $files = is_dir($target) && !is_link($target)
            ? (static function () use ($fs, $target): \Generator {
                foreach (Tree::walk($fs, $target) as [$path, $isDir]) if (!$isDir) yield $path;
            })()
            : [$target];
        foreach ($files as $file) {
            $ledger->record($fs->relative($file), basename($file), (int)(@filesize($file) ?: 0), null, $ctx->owner());
            $ctx->checkpoint();
        }
    }

    /** Refuse to start writing $bytes when the disk under the store cannot hold them. */
    protected static function assertDiskSpace(JobEnvironment $env, int $bytes): void
    {
        $free = @disk_free_space($env->files->root());
        // Unknown is not full: some FUSE mounts report nothing, and the write
        // itself still fails cleanly if the space really is not there.
        if ($free === false || $free <= 0) return;
        $margin = 64 * 1024 * 1024;
        if ($bytes + $margin > $free) {
            throw new RuntimeException('Not enough free disk space: this needs about '.\CloudHub\Services\StorageQuota::humanBytes($bytes)
                .' and '.\CloudHub\Services\StorageQuota::humanBytes((int)$free).' is free', 507);
        }
    }
}
