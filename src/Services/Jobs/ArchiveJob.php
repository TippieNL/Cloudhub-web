<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use RuntimeException;
use ZipArchive;

/**
 * Build a ZIP of files and folders, in the background.
 *
 * Two uses, one archive:
 *
 *   download  the ZIP waits in the job's output folder until its owner
 *             downloads it, the job is removed, or it expires. Reading only,
 *             as /api/files/download-zip is, so any signed-in account may.
 *   save      the ZIP is placed in a folder of the store ("Compress"),
 *             charged to the owner's quota. Writing, so editors only.
 *
 * The archive is built in the attempt's staging folder and only moved when it
 * is complete, so a cancelled or failed job never leaves a truncated ZIP
 * where anyone can see it. Walking follows the download route's rules:
 * symbolic links and CloudHub's own folders are never included.
 */
final class ArchiveJob extends BaseJobType
{
    /** Already-compressed formats, stored rather than deflated again; see ZIP_STORED_EXTENSIONS. */
    public const STORED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif',
        'mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', '3gp', '3g2', 'mpeg', 'mpg', 'ogv',
        'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac',
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub'];

    /** A rate libzip writes at on slow storage, for holding the job through close() where libzip cannot report. */
    private const SLOW_BYTES_PER_SECOND = 5 * 1024 * 1024;

    public function name(): string { return 'archive'; }
    public function capability(array $payload): string { return ($payload['mode'] ?? '') === 'save' ? 'write' : 'read'; }
    public function writesStore(array $payload): bool { return ($payload['mode'] ?? '') === 'save'; }

    /**
     * Archives waiting to be downloaded, or being made, that one account may
     * have at once. Any signed-in account may make one, and a finished one
     * stays on the disk until it is removed or expires: without a limit,
     * archiving the whole store over and over would fill the disk.
     */
    public const MAX_KEPT_DOWNLOADS = 3;

    public function prepare(array $params, JobEnvironment $env, array $user): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP zip extension is required', 503);
        $fs = $env->files;
        $mode = $params['mode'] ?? 'download';
        if ($mode !== 'download' && $mode !== 'save') throw new RuntimeException('mode must be "download" or "save"', 422);
        if ($mode === 'download') {
            $kept = array_filter($env->jobs()->listFor((int)$user['id'], false, 500), static fn(array $j): bool =>
                $j['type'] === 'archive' && ($j['payload']['mode'] ?? '') === 'download'
                && in_array($j['status'], ['pending', 'processing', 'completed'], true));
            if (count($kept) >= self::MAX_KEPT_DOWNLOADS) {
                throw new RuntimeException('You already have '.self::MAX_KEPT_DOWNLOADS
                    .' archives waiting to be downloaded or being made; remove one under Tasks first', 429);
            }
        }
        $paths = self::existingPaths($params['paths'] ?? null, $fs);

        $name = $params['name'] ?? null;
        if ($name === null || $name === '') {
            $first = basename($paths[0]);
            $name = count($paths) > 1 ? 'Archive'
                : (is_dir($fs->existing($paths[0])) || !str_contains(ltrim($first, '.'), '.') ? $first : pathinfo($first, PATHINFO_FILENAME));
        }
        if (!is_string($name)) throw new RuntimeException('name must be a string', 422);
        $name = $fs->safeName($name);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') $name = $fs->safeName($name.'.zip');

        $payload = ['mode' => $mode, 'paths' => $paths, 'name' => $name];
        $target = dirname($paths[0]);
        if ($mode === 'save') {
            $fs->writable();
            $destination = self::existingFolder($params['destination'] ?? dirname($paths[0]), $fs);
            // Refuses a reserved name at the root now rather than after the work.
            $fs->childPath($fs->existing($destination), $name);
            $payload['destination'] = $target = $destination;
        }
        return [
            'label' => ($mode === 'save' ? 'Compress into "' : 'Download "').$name.'"',
            'target' => $target === '.' ? '/' : $target,
            'payload' => $payload,
        ];
    }

    public function run(JobContext $ctx): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP zip extension is required', 503);
        $env = $ctx->env();
        $fs = $env->files;
        $payload = $ctx->payload();
        $save = ($payload['mode'] ?? '') === 'save';
        $name = basename((string)($payload['name'] ?? 'Archive.zip'));
        $state = $ctx->state();

        // Placed by the attempt before this one, which stopped before it could say so.
        $placing = $state['placing'] ?? null;
        if ($save && is_array($placing) && isset($placing['to'])) {
            try { $landed = $fs->sanitize((string)$placing['to']); } catch (RuntimeException) { $landed = null; }
            if ($landed !== null && is_file($landed)) {
                self::recordOwnership($ctx, $landed);
                return (array)($placing['result'] ?? []) + ['path' => (string)$placing['to']];
            }
        }
        if ($save) $fs->writable();

        $items = [];
        foreach ((array)($payload['paths'] ?? []) as $rel) $items[] = $fs->existing((string)$rel);

        $ctx->progress(0, 'Measuring');
        $bytes = 0;
        $files = 0;
        foreach ($items as $item) {
            $measured = Tree::measure($fs, $item, null, null, static fn() => $ctx->checkpoint());
            $bytes += $measured['bytes'];
            $files += $measured['files'];
        }
        self::assertDiskSpace($env, $bytes);
        $ctx->total($bytes, 'bytes');
        $ctx->checkpoint(true);

        $tmp = $ctx->staging().'/archive.zip';
        if (file_exists($tmp)) @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create the archive', 500);

        try {
            $roots = [];
            foreach ($items as $item) {
                $root = self::uniqueRoot(basename($item), $roots);
                $this->add($zip, $item, $root, $ctx);
                foreach ($this->children($fs, $item, $root) as [$full, $entry, $isDir]) {
                    $this->add($zip, $full, $entry, $ctx, $isDir);
                }
            }
            if ($zip->numFiles === 0) throw new RuntimeException('None of the selected items could be read', 404);

            // The real work happens in close(): libzip reads and compresses
            // every file then. Where it can report, each report is a
            // checkpoint; where it can also be stopped, a cancel stops it.
            $stop = null;
            $ctx->progress(0, 'Writing '.$name);
            if (method_exists($zip, 'registerProgressCallback')) {
                $zip->registerProgressCallback(0.01, static function (float $rate) use ($ctx, $bytes, &$stop): void {
                    if ($stop !== null) return;
                    try {
                        $ctx->progress((int)($rate * $bytes));
                        $ctx->checkpoint();
                    } catch (\Throwable $e) {
                        $stop = $e;
                    }
                });
                if (method_exists($zip, 'registerCancelCallback')) {
                    $zip->registerCancelCallback(static function () use (&$stop): int { return $stop === null ? 0 : 1; });
                }
            } else {
                $ctx->extendLease(120 + intdiv($bytes, self::SLOW_BYTES_PER_SECOND));
            }
            $closed = @$zip->close();
            $zip = null;
            if ($stop !== null) throw $stop;
            if (!$closed) throw new RuntimeException('Unable to finish the archive', 500);
        } catch (\Throwable $e) {
            if ($zip !== null) { @$zip->unchangeAll(); @$zip->close(); }
            @unlink($tmp);
            throw $e;
        }

        clearstatcache(true, $tmp);
        $size = (int)(@filesize($tmp) ?: 0);
        $result = ['name' => $name, 'bytes' => $size, 'files' => $files, 'sourceBytes' => $bytes];
        $ctx->progress($bytes, null);

        if (!$save) {
            $out = $ctx->output().'/'.$name;
            if (!@rename($tmp, $out)) throw new RuntimeException('Unable to keep the archive for download', 500);
            return $result + ['download' => true];
        }

        $fs->writable();
        $destination = $fs->existing((string)($payload['destination'] ?? ''));
        if (!is_dir($destination)) throw new RuntimeException('The destination is not a folder', 400);
        $env->quota()->assertFits($size, $ctx->owner());
        $target = self::moveIntoPlace($ctx, $tmp, $destination, $name,
            static fn(string $to) => $ctx->saveState(['placing' => ['to' => $to, 'result' => $result]]));
        self::recordOwnership($ctx, $target);
        return $result + ['path' => $fs->relative($target)];
    }

    public function download(array $job, JobEnvironment $env): ?array
    {
        if ($job['status'] !== 'completed' || ($job['payload']['mode'] ?? '') !== 'download') return null;
        $name = basename((string)($job['payload']['name'] ?? ''));
        if ($name === '' || $name === '.' || $name === '..') return null;
        $path = $env->workDir((string)$job['id']).'/output/'.$name;
        return is_file($path) && !is_link($path) ? ['path' => $path, 'name' => $name, 'mime' => 'application/zip'] : null;
    }

    /**
     * Everything below an item, as [absolute path, entry name, is a folder].
     *
     * @return \Generator<int, array{0:string,1:string,2:bool}>
     */
    private function children(\CloudHub\Services\FileService $fs, string $item, string $root): \Generator
    {
        if (is_link($item) || !is_dir($item)) return;
        $prefix = strlen($item);
        foreach (Tree::walk($fs, $item) as [$full, $isDir]) {
            yield [$full, $root.substr($full, $prefix), $isDir];
        }
    }

    private function add(ZipArchive $zip, string $full, string $entry, JobContext $ctx, ?bool $isDir = null): void
    {
        $isDir ??= is_dir($full) && !is_link($full);
        if ($isDir) {
            $zip->addEmptyDir($entry);
        } elseif (is_file($full) && !is_link($full)) {
            if (!$zip->addFile($full, $entry)) throw new RuntimeException('Unable to add '.basename($full).' to the archive', 500);
            if (in_array(strtolower(pathinfo($full, PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true)
                && method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
        }
        $ctx->checkpoint();
    }

    /**
     * Top-level entries get unique names, as the download route gives them:
     * report.pdf picked from two folders must not become one entry.
     *
     * @param array<string,bool> $roots
     */
    private static function uniqueRoot(string $name, array &$roots): string
    {
        $candidate = $name;
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        for ($i = 2; isset($roots[strtolower($candidate)]); $i++) {
            if ($i > 10000) throw new RuntimeException('Unable to name the archive entries uniquely', 500);
            $candidate = $stem.' ('.$i.')'.($ext !== '' ? '.'.$ext : '');
        }
        $roots[strtolower($candidate)] = true;
        return $candidate;
    }
}
