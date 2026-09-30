<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use CloudHub\Services\FileService;
use RuntimeException;

/**
 * Walking, measuring, copying and deleting trees a piece at a time.
 *
 * FileService's own tree operations are recursive and report nothing until
 * they return. That is right for a request, which either finishes or does not,
 * and wrong for a job: a job has to heartbeat while it works, notice when it
 * is cancelled, and say how far it has got. Everything here yields or calls
 * back per file or per chunk, and holds one folder's entries plus the folders
 * still to visit -- never the whole tree -- in memory.
 *
 * Walks go through FileService::childPaths(), so they apply the rules every
 * listing applies: symbolic links are skipped, and at the storage root so are
 * CloudHub's own folders.
 */
final class Tree
{
    /** Bytes read and written per step of a file copy or hash. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * Every file and folder below $dir, not $dir itself, parents before their
     * children.
     *
     * @return \Generator<int, array{0:string,1:bool}> [absolute path, is a folder]
     */
    public static function walk(FileService $fs, string $dir): \Generator
    {
        $stack = [$dir];
        while ($stack) {
            $current = array_pop($stack);
            foreach ($fs->childPaths($current) as $child) {
                $isDir = is_dir($child);
                yield [$child, $isDir];
                if ($isDir) $stack[] = $child;
            }
        }
    }

    /**
     * Bytes, files and folders at and below $path. Stops once past either
     * limit and says so, for a caller that only needs to know "more than".
     *
     * @param callable(): void|null $tick called per entry, to heartbeat through a long walk
     * @return array{bytes:int,files:int,dirs:int,truncated:bool}
     */
    public static function measure(FileService $fs, string $path, ?int $maxFiles = null, ?int $maxBytes = null, ?callable $tick = null): array
    {
        if (is_link($path)) return ['bytes' => 0, 'files' => 0, 'dirs' => 0, 'truncated' => false];
        if (!is_dir($path)) return ['bytes' => (int)(@filesize($path) ?: 0), 'files' => 1, 'dirs' => 0, 'truncated' => false];
        $bytes = 0; $files = 0; $dirs = 1; $truncated = false;
        foreach (self::walk($fs, $path) as [$entry, $isDir]) {
            if ($isDir) $dirs++;
            else { $files++; $bytes += (int)(@filesize($entry) ?: 0); }
            if ($tick !== null) $tick();
            if (($maxFiles !== null && $files > $maxFiles) || ($maxBytes !== null && $bytes > $maxBytes)) { $truncated = true; break; }
        }
        return ['bytes' => $bytes, 'files' => $files, 'dirs' => $dirs, 'truncated' => $truncated];
    }

    /**
     * Copy a file or a folder to $target, which must not exist yet.
     *
     * Files are streamed a chunk at a time and progress is reported in bytes,
     * with a checkpoint after every chunk. Symbolic links inside a folder are
     * left out, as the ZIP download leaves them out.
     *
     * @param string $label how the source is named in progress, relative to the store
     */
    public static function copy(FileService $fs, string $source, string $target, JobContext $ctx, string $label): void
    {
        if (is_link($source)) throw new RuntimeException('Symlinks cannot be copied', 403);
        if (!is_dir($source)) { self::copyFile($source, $target, $ctx, $label); return; }
        if (!@mkdir($target, 0775)) throw new RuntimeException('Unable to create the folder '.basename($target), 500);
        $prefix = strlen($source);
        foreach (self::walk($fs, $source) as [$entry, $isDir]) {
            $rel = substr($entry, $prefix);
            if ($isDir) {
                if (!@mkdir($target.$rel, 0775) && !is_dir($target.$rel)) throw new RuntimeException('Unable to create the folder '.basename($entry), 500);
                $ctx->checkpoint();
                continue;
            }
            self::copyFile($entry, $target.$rel, $ctx, $label.$rel);
        }
    }

    /** Stream one file to a new file, checkpointing per chunk. */
    public static function copyFile(string $source, string $target, JobContext $ctx, string $label): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) throw new RuntimeException('Unable to read '.basename($source), 500);
        // 'x' so a copy can never replace a file, even one that appeared in
        // the staging folder by some other route.
        $out = @fopen($target, 'xb');
        if ($out === false) { fclose($in); throw new RuntimeException('Unable to write '.basename($target), 500); }
        try {
            $ctx->progress($ctx->done(), $label);
            while (!feof($in)) {
                $data = fread($in, self::CHUNK_BYTES);
                if ($data === false) throw new RuntimeException('Unable to read '.basename($source), 500);
                if ($data === '') break;
                if (fwrite($out, $data) !== strlen($data)) {
                    throw new RuntimeException('Unable to write '.basename($target).'; the disk may be full', 507);
                }
                $ctx->advance(strlen($data));
                $ctx->checkpoint();
            }
            if (!fflush($out)) throw new RuntimeException('Unable to write '.basename($target).'; the disk may be full', 507);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Delete $path and everything below it, calling $tick after each file.
     *
     * Not recursive, so no depth is too deep, and it reports as it goes, so a
     * job deleting a hundred thousand files heartbeats and shows progress.
     * Symbolic links are removed, never followed. Only for paths inside the
     * storage root, and never the root itself.
     *
     * @param callable(): void $tick
     */
    public static function remove(FileService $fs, string $path, callable $tick): void
    {
        if ($fs->relative($path) === '/') throw new RuntimeException('Storage root cannot be deleted', 403);
        $stack = [[$path, false]];
        while ($stack) {
            [$entry, $emptied] = array_pop($stack);
            if ($emptied) {
                if (!@rmdir($entry) && is_dir($entry)) throw new RuntimeException('Unable to delete the folder '.basename($entry), 500);
                continue;
            }
            if (is_link($entry) || !is_dir($entry)) {
                if (!@unlink($entry) && (file_exists($entry) || is_link($entry))) throw new RuntimeException('Unable to delete '.basename($entry), 500);
                $tick();
                continue;
            }
            $stack[] = [$entry, true];
            foreach (scandir($entry) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') $stack[] = [$entry.'/'.$name, false];
            }
        }
    }
}
