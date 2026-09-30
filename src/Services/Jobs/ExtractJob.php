<?php
declare(strict_types=1);
namespace CloudHub\Services\Jobs;

use RuntimeException;
use ZipArchive;

/**
 * Unpack a ZIP archive from the store into a new folder beside it.
 *
 * An archive is somebody else's file, so every entry is distrusted:
 *
 *   - names are checked before a byte is written. An absolute path, a drive
 *     letter, a ".." component, a control character or a name that is not
 *     UTF-8 refuses the whole archive -- nothing is extracted, rather than
 *     the safe part of a hostile one;
 *   - entries are only ever written below the job's own staging folder, by
 *     joining checked components, so nothing is resolved through the store;
 *   - symbolic links, devices and pipes are skipped, never created;
 *   - encrypted entries refuse the archive;
 *   - the sizes the archive declares are held to limits, the quota and the
 *     free disk space before extracting, and each entry is cut off if it
 *     produces more bytes than it declared, which is what a ZIP bomb does.
 *
 * The folder is assembled in staging and moved into place with one rename:
 * a damaged archive, a cancel or a crash leaves nothing in the store.
 */
final class ExtractJob extends BaseJobType
{
    /** Bytes read from an entry at a time. */
    private const CHUNK_BYTES = 1024 * 1024;

    public function name(): string { return 'extract'; }

    public function prepare(array $params, JobEnvironment $env, array $user): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP zip extension is required', 503);
        $fs = $env->files;
        $fs->writable();
        $archive = self::existingFile($params['path'] ?? null, $fs);
        if (strtolower(pathinfo($archive, PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('Only .zip archives can be extracted', 415);
        $destination = self::existingFolder($params['destination'] ?? dirname($archive), $fs);

        $folder = pathinfo(basename($archive), PATHINFO_FILENAME);
        $folder = $fs->safeName($folder === '' ? 'Extracted' : $folder);
        // Refuses a reserved name at the root now rather than after the work.
        $fs->childPath($fs->existing($destination), $folder);

        // Opened once here, so a file that is not a ZIP fails when it is
        // queued rather than when a worker gets to it.
        $zip = new ZipArchive();
        if ($zip->open($fs->existing($archive), ZipArchive::RDONLY) !== true) throw new RuntimeException('That file is not a readable ZIP archive', 422);
        $entries = $zip->numFiles;
        $zip->close();
        $max = self::maxEntries($env->config);
        if ($entries > $max) throw new RuntimeException('The archive has '.$entries.' entries; at most '.$max.' can be extracted', 413);

        return [
            'label' => 'Extract "'.basename($archive).'"',
            'target' => $destination,
            'payload' => ['path' => $archive, 'destination' => $destination, 'folder' => $folder],
        ];
    }

    public function run(JobContext $ctx): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP zip extension is required', 503);
        $env = $ctx->env();
        $fs = $env->files;
        $payload = $ctx->payload();

        // Placed by the attempt before this one, which stopped before it could say so.
        $placing = $ctx->state()['placing'] ?? null;
        if (is_array($placing) && isset($placing['to'])) {
            try { $landed = $fs->sanitize((string)$placing['to']); } catch (RuntimeException) { $landed = null; }
            if ($landed !== null && is_dir($landed)) {
                self::recordOwnership($ctx, $landed);
                return (array)($placing['result'] ?? []) + ['path' => (string)$placing['to']];
            }
        }

        $fs->writable();
        $archive = $fs->existing((string)($payload['path'] ?? ''));
        if (!is_file($archive)) throw new RuntimeException('The archive no longer exists', 404);
        $destination = $fs->existing((string)($payload['destination'] ?? ''));
        if (!is_dir($destination)) throw new RuntimeException('The destination is not a folder', 400);

        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) throw new RuntimeException('That file is not a readable ZIP archive', 422);
        try {
            [$plan, $bytes, $skipped] = $this->plan($zip, $env->config, $ctx);
            $env->quota()->assertFits($bytes, $ctx->owner());
            self::assertDiskSpace($env, $bytes);

            $ctx->total($bytes, 'bytes');
            $ctx->progress(0);
            $ctx->checkpoint(true);

            $root = $ctx->staging().'/extract';
            if (!@mkdir($root, 0775) && !is_dir($root)) throw new RuntimeException('Unable to create the extraction folder', 500);
            $files = 0;
            foreach ($plan as [$index, $parts, $isDir, $size]) {
                $path = $root.'/'.implode('/', $parts);
                if ($isDir) {
                    self::makeDir($path);
                    $ctx->checkpoint();
                    continue;
                }
                self::makeDir(dirname($path));
                $this->extractEntry($zip, $index, $path, $size, implode('/', $parts), $ctx);
                $files++;
            }
        } finally {
            $zip->close();
        }

        $result = ['files' => $files, 'bytes' => $bytes, 'skipped' => $skipped, 'archive' => (string)$payload['path']];
        $target = self::moveIntoPlace($ctx, $root, $destination, (string)$payload['folder'],
            static fn(string $to) => $ctx->saveState(['placing' => ['to' => $to, 'result' => $result]]));
        self::recordOwnership($ctx, $target);
        return $result + ['path' => $fs->relative($target)];
    }

    /**
     * Check every entry before anything is written.
     *
     * @return array{0:list<array{0:int,1:list<string>,2:bool,3:int}>,1:int,2:int} the entries to write, their declared bytes, and how many were skipped
     */
    private function plan(ZipArchive $zip, array $config, JobContext $ctx): array
    {
        $maxEntries = self::maxEntries($config);
        $maxBytes = (int)round(max(0.001, (float)($config['queue_extract_max_gb'] ?? 20)) * 1073741824);
        if ($zip->numFiles > $maxEntries) throw new RuntimeException('The archive has '.$zip->numFiles.' entries; at most '.$maxEntries.' can be extracted', 413);

        $plan = [];
        $bytes = 0;
        $skipped = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) throw new RuntimeException('The archive is damaged', 422);
            $name = (string)$stat['name'];
            if (($stat['encryption_method'] ?? 0) !== ZipArchive::EM_NONE) throw new RuntimeException('Encrypted archives cannot be extracted', 415);

            $parts = self::entryParts($name);
            if ($parts === []) continue;
            $isDir = str_ends_with(str_replace('\\', '/', $name), '/');

            // Unix mode bits, where the archive recorded them: links, devices
            // and pipes are left out. Nothing here would create one -- entries
            // are written as plain files -- but a link written as a file whose
            // content is a path is not what anyone archived either.
            $opsys = null;
            $attr = null;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                $type = ($attr >> 16) & 0170000;
                if ($type === 0040000) $isDir = true;
                elseif ($type !== 0 && $type !== 0100000) { $skipped++; continue; }
            }

            $size = $isDir ? 0 : max(0, (int)$stat['size']);
            $bytes += $size;
            if ($bytes > $maxBytes) {
                throw new RuntimeException('The archive would unpack to more than '.\CloudHub\Services\StorageQuota::humanBytes($maxBytes).', the most allowed', 413);
            }
            $plan[] = [$i, $parts, $isDir, $size];
            if ($i % 500 === 0) $ctx->checkpoint();
        }
        return [$plan, $bytes, $skipped];
    }

    /**
     * An entry's name as safe path components, or [] for an entry that names
     * nothing ("./"). Throws for any name that could reach outside the folder.
     *
     * @return list<string>
     */
    public static function entryParts(string $name): array
    {
        $shown = '"'.mb_substr(mb_scrub($name, 'UTF-8'), 0, 120).'"';
        $unsafe = static fn(string $why): RuntimeException =>
            new RuntimeException('The archive contains '.$why.' ('.$shown.'), so nothing was extracted', 422);

        if (str_contains($name, "\0")) throw $unsafe('a name with a null byte');
        if (!mb_check_encoding($name, 'UTF-8')) throw $unsafe('a name that is not valid UTF-8');
        // A ZIP made on Windows may separate with backslashes; so may an
        // attacker hoping the extractor does not.
        $normal = str_replace('\\', '/', $name);
        if (str_starts_with($normal, '/') || preg_match('#^[A-Za-z]:#', $normal)) throw $unsafe('an absolute path');
        $parts = [];
        foreach (explode('/', $normal) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') throw $unsafe('a path that climbs out of its folder');
            if (preg_match('/[\x00-\x1F\x7F]/u', $part)) throw $unsafe('a name with control characters');
            if (strlen($part) > 255) throw $unsafe('a name that is too long');
            $parts[] = $part;
        }
        return $parts;
    }

    private function extractEntry(ZipArchive $zip, int $index, string $path, int $size, string $label, JobContext $ctx): void
    {
        $in = $zip->getStreamIndex($index);
        if ($in === false) throw new RuntimeException('The archive is damaged: '.$label.' cannot be read', 422);
        $out = @fopen($path, 'wb');
        if ($out === false) { fclose($in); throw new RuntimeException('Unable to write '.$label, 500); }
        $written = 0;
        try {
            $ctx->progress($ctx->done(), $label);
            while (!feof($in)) {
                $data = fread($in, self::CHUNK_BYTES);
                if ($data === false) throw new RuntimeException('The archive is damaged: '.$label.' cannot be read', 422);
                if ($data === '') break;
                $written += strlen($data);
                if ($written > $size) throw new RuntimeException('The archive is damaged: '.$label.' is larger than it declares', 422);
                if (fwrite($out, $data) !== strlen($data)) throw new RuntimeException('Unable to write '.$label.'; the disk may be full', 507);
                $ctx->advance(strlen($data));
                $ctx->checkpoint();
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($written !== $size) throw new RuntimeException('The archive is damaged: '.$label.' is shorter than it declares', 422);
    }

    private static function makeDir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the folder '.basename($path).'; the archive may hold a file and a folder of the same name', 422);
        }
    }

    private static function maxEntries(array $config): int
    {
        return max(1, (int)($config['queue_extract_max_files'] ?? 20000));
    }
}
