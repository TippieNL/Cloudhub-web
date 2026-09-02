<?php
declare(strict_types=1);

namespace CloudHub\Services;

use RuntimeException;

/**
 * Manages resumable chunk uploads without buffering multi-gigabyte files in a
 * single PHP request.
 *
 * Each upload gets an isolated staging directory containing metadata plus one
 * part file. Chunks are written at deterministic offsets, so retransmitting an
 * already accepted chunk is safe. Completion verifies the final byte count and
 * atomically renames the staging file into the storage tree when possible.
 */
final class UploadService
{
    /**
     * Status for "this upload's metadata cannot be parsed".
     *
     * 422 rather than 500 because the staged bytes, not the server, are what
     * is unprocessable -- and it has to be distinguishable from assertOwner()'s
     * 404 so that init() never mistakes somebody else's session for a damaged
     * one of its own.
     */
    private const META_UNREADABLE = 422;

    private string $stagingRoot;

    public function __construct(
        private readonly array $config,
        private readonly FileService $files
    ) {
        $configured = trim((string)($this->config['upload_staging_dir'] ?? ''));
        $this->stagingRoot = $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : dirname(__DIR__, 2).'/storage/uploads';

        $this->ensureStagingRoot();
    }

    /** Delete staging sessions that have not been touched within the configured TTL. */
    public function cleanupAbandoned(): int
    {
        $ttl = max(1, (int)$this->config['upload_abandon_hours']) * 3600;
        $cutoff = time() - $ttl;
        $removed = 0;
        foreach (scandir($this->stagingRoot) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $dir = $this->stagingRoot.'/'.$name;
            if (!is_dir($dir) || (filemtime($dir) ?: time()) >= $cutoff) continue;
            // Per entry, because deleteStagingTree() throws on any failed
            // rmdir or unlink and init() calls this before it does anything
            // else -- so one directory PHP could not remove (left by another
            // uid, or a FUSE quirk on Android) failed every user's every
            // upload with a 500 until somebody deleted it by hand. Cleanup
            // is opportunistic and must never block the actual work.
            try {
                $this->deleteStagingTree($dir);
                $removed++;
            } catch (\Throwable $e) {
                error_log('[upload] could not clean abandoned session '.$dir.': '.$e->getMessage());
            }
        }
        return $removed;
    }

    /** Create or resume an upload session. */
    public function init(string $targetPath, string $name, int $size, string $clientId, string $conflict): array
    {
        $this->files->writable();
        $this->cleanupAbandoned();

        $max = max(1, (int)$this->config['max_upload_mb']) * 1024 * 1024;
        // Separated so the message names the actual fault. The route defaults
        // a missing size to -1, which fell into the limit branch and answered
        // "File exceeds the 2048 MB limit" for a field that was never sent.
        if ($size < 0) throw new RuntimeException('A file size is required to start an upload', 400);
        if ($size > $max) throw new RuntimeException('File exceeds the '.$this->config['max_upload_mb'].' MB limit', 413);

        $safeName = $this->files->safeName($name);
        $targetDir = $this->files->existing($targetPath);
        if (!is_dir($targetDir)) throw new RuntimeException('Upload target is not a directory', 400);

        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $clientId);
        if ($id === '') $id = bin2hex(random_bytes(16));
        $dir = $this->sessionDir($id);
        $metaFile = $dir.'/meta.json';

        if (is_file($metaFile)) {
            try {
                $meta = $this->readMeta($id);
                $this->assertOwner($meta);
                if ((int)$meta['size'] !== $size || $meta['name'] !== $safeName || $meta['targetPath'] !== $targetPath) {
                    throw new RuntimeException('Upload ID belongs to a different file', 409);
                }
                return $this->statusPayload($id, $meta);
            } catch (RuntimeException $e) {
                /*
                 * Only genuinely unreadable metadata may be recreated.
                 *
                 * assertOwner() throws 404, and this used to treat anything
                 * that was not a 409 as "damaged session, start over" -- so a
                 * session belonging to somebody else was deleted rather than
                 * refused. Upload ids are a hash of path|name|size|lastModified
                 * (see uploadKey() in app.js), not per-user, so two people
                 * uploading the same file to the same folder collide by
                 * construction and the second wiped the first's staged bytes.
                 */
                if ($e->getCode() !== self::META_UNREADABLE) throw $e;
                $this->deleteStagingTree($dir);
            }
        }

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to initialise upload', 500);
        $meta = [
            'id'=>$id, 'name'=>$safeName, 'size'=>$size, 'targetPath'=>$targetPath,
            'conflict'=>$this->normaliseConflict($conflict), 'ownerUserId'=>(int)($_SESSION['user_id']??0), 'createdAt'=>time(), 'updatedAt'=>time()
        ];
        $this->writeMeta($id, $meta);
        touch($dir.'/data.part');
        return $this->statusPayload($id, $meta);
    }

    /** Return the server-confirmed offset so a browser can resume after reconnecting. */
    public function status(string $id): array
    {
        $meta = $this->readMeta($id);
        $this->assertOwner($meta);
        return $this->statusPayload($id, $meta);
    }

    /**
     * Append a chunk only when its requested offset matches the server offset.
     * A mismatch returns 409 through the router and the client re-queries status.
     */
    public function append(string $id, int $offset, string $input): array
    {
        $startedAt = microtime(true);
        $this->files->writable();
        $meta = $this->readMeta($id);
        $this->assertOwner($meta);
        $part = $this->sessionDir($id).'/data.part';
        $chunkLimit = max(1, (int)$this->config['upload_chunk_mb']) * 1024 * 1024;

        $in = fopen($input, 'rb');
        $out = $this->openPartForWriting($part);
        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            throw new RuntimeException('Unable to open upload stream for '.$part, 500);
        }

        /*
         * The offset is checked and the write is positioned there.
         *
         * Previously the size was read, compared, and then appended with 'ab'
         * with nothing held in between. Because upload ids are deterministic
         * (a hash of path|name|size|lastModified), the same file dropped into
         * two tabs produces two requests with the same id: both read offset 0,
         * both passed the check, and both appended -- leaving data.part at
         * twice the length. From then on complete() failed 409 "incomplete"
         * forever and append() failed 413, because $remaining went negative.
         * The session could not be recovered before the 24-hour sweep.
         *
         * Writing at a position this method chooses is what fixes that, not the
         * lock below: two requests that compute the same offset now write the
         * same region instead of each adding to the end, so the file keeps the
         * right length and the right bytes either way. The lock only narrows
         * the window between the check and the write.
         */
        $written = 0;
        $locked = $this->lockForWriting($out, $part, $offset === 0);
        clearstatcache(true, $part);
        $current = (int)(fstat($out)['size'] ?? 0);
        if ($offset !== $current) {
            $this->releasePart($out, $locked);
            fclose($in);
            throw new RuntimeException('Upload offset mismatch; expected '.$current, 409);
        }
        if (fseek($out, $current) !== 0) {
            $this->releasePart($out, $locked);
            fclose($in);
            throw new RuntimeException('Unable to seek '.$part.' to offset '.$current, 500);
        }

        $remaining = (int)$meta['size'] - $current;
        try {
            // The browser sends exactly chunkBytes per chunk, so $written
            // reaches $chunkLimit while the stream is not yet at EOF. Testing
            // $written first keeps the read length positive: fread() with a
            // length of 0 is a ValueError on PHP 8 and surfaced as HTTP 500 on
            // every full-size chunk.
            while ($written < $chunkLimit && !feof($in)) {
                $buffer = fread($in, min(1024 * 1024, $chunkLimit - $written));
                if ($buffer === false) throw new RuntimeException('Unable to read upload chunk', 500);
                if ($buffer === '') break;
                $len = strlen($buffer);
                if ($written + $len > $chunkLimit || $written + $len > $remaining) throw new RuntimeException('Chunk is larger than expected', 413);
                if (fwrite($out, $buffer) !== $len) throw new RuntimeException('Unable to write upload chunk', 500);
                $written += $len;
            }
            // A body longer than the limit must still be rejected rather than
            // silently truncated at exactly chunkLimit bytes.
            if ($written >= $chunkLimit && !feof($in)) {
                $excess = fread($in, 1);
                if ($excess !== false && $excess !== '') {
                    throw new RuntimeException('Chunk is larger than expected', 413);
                }
            }
        } finally {
            // Flush before releasing: another waiter must observe this chunk's
            // bytes in the size it reads, or it would compute the same offset
            // again.
            fflush($out);
            $this->releasePart($out, $locked);
            fclose($in);
        }
        clearstatcache(true, $part);

        /*
         * The session directory is touched rather than the metadata rewritten.
         *
         * This used to set $meta['updatedAt'] and call writeMeta() on every
         * chunk -- a json_encode, a temporary file and a rename, three
         * filesystem operations each time, on FUSE. Nothing reads updatedAt:
         * statusPayload() takes the resume offset from filesize() on the part
         * file. What the rewrite actually achieved was bumping the session
         * directory's mtime, which is what cleanupAbandoned() reads for the
         * abandon TTL -- a side effect of the write rather than its purpose.
         * Writing data.part does not bump the directory, so the touch is still
         * needed; it just says what it is for and costs one syscall.
         */
        @touch($this->sessionDir($id));

        $payload = $this->statusPayload($id, $meta);
        // So a client can say how much of an upload was the server and how much
        // was the network, instead of the two being argued about.
        $payload['serverMs'] = (int)round((microtime(true) - $startedAt) * 1000);
        return $payload;
    }

    /**
     * Open the staging file for a positioned write.
     *
     * 'c+b' rather than 'ab' because the write has to land where append()
     * decides, not wherever the file currently ends. It asks for more than
     * 'ab' did -- create *and* read -- and this class already warns that
     * Android emulated storage reports permissions unreliably, so a refusal
     * falls back to creating the file and reopening it for update.
     *
     * @return resource|false
     */
    private function openPartForWriting(string $part)
    {
        $out = @fopen($part, 'c+b');
        if ($out !== false) return $out;

        if (!is_file($part)) @touch($part);
        $out = @fopen($part, 'r+b');
        if ($out !== false) {
            error_log('[upload] '.$part.' refused c+b; reopened r+b');
            return $out;
        }
        return false;
    }

    /**
     * Take the advisory lock if this filesystem has one, without insisting.
     *
     * Locking here is an optimisation, not the correctness mechanism -- the
     * positioned write in append() is -- so a filesystem without it must not
     * fail the upload. ensureWritableDirectory()'s docblock records that
     * Android shared storage supports ordinary I/O without reliable advisory
     * flock() semantics, and requiring the lock made every chunk return 500 on
     * exactly the platform this application is built for.
     *
     * One attempt, never a retry. This used to try twenty times with a 50 ms
     * sleep between attempts, which was reasoning about the wrong failure: that
     * loop waits out *contention*, but where there is no advisory locking at
     * all flock() fails instantly and identically every time, so every chunk
     * slept a full second for something that could never succeed -- around 78
     * seconds of a 605 MB upload, measured.
     *
     * Waiting even briefly would only narrow the window between the size check
     * and the write, and 6ed82d4 established that window is already harmless:
     * two writers at one offset leave the file correct precisely because the
     * write is positioned rather than appended. There is nothing here worth a
     * second of anybody's upload.
     *
     * @param resource $out
     */
    private function lockForWriting($out, string $part, bool $report): bool
    {
        if (@flock($out, LOCK_EX | LOCK_NB)) return true;
        // Once per upload rather than once per chunk: seventy-six identical
        // lines say nothing the first one did not.
        if ($report) error_log('[upload] no advisory lock available for '.$part.'; continuing with a positioned write');
        return false;
    }

    /**
     * @param resource $out
     */
    private function releasePart($out, bool $locked): void
    {
        if ($locked) @flock($out, LOCK_UN);
        fclose($out);
    }

    /** Assemble/finalise the upload and apply the requested conflict policy. */
    public function complete(string $id): array
    {
        $this->files->writable();
        $meta = $this->readMeta($id);
        $this->assertOwner($meta);
        $dir = $this->sessionDir($id);
        $part = $dir.'/data.part';
        $actual = is_file($part) ? (filesize($part) ?: 0) : 0;
        if ($actual !== (int)$meta['size']) throw new RuntimeException('Upload is incomplete: '.$actual.' of '.$meta['size'].' bytes received', 409);

        $targetDir = $this->files->existing((string)$meta['targetPath']);
        $dest = $targetDir.'/'.$meta['name'];
        $policy = $this->normaliseConflict((string)$meta['conflict']);

        if (file_exists($dest)) {
            if ($policy === 'reject') throw new RuntimeException('File already exists: '.$meta['name'], 409);
            if ($policy === 'rename') $dest = $this->uniqueDestination($targetDir, (string)$meta['name']);
            if ($policy === 'overwrite' && !$this->config['allow_overwrite']) {
                throw new RuntimeException('Overwrite is disabled by server configuration', 403);
            }
            if ($policy === 'overwrite' && is_dir($dest)) throw new RuntimeException('Destination is a directory', 409);
        }

        /*
         * The existing file is never removed before its replacement is in
         * place.
         *
         * unlink($dest) used to run first, so an overwrite that then failed
         * both rename() and copy() -- staging on another filesystem gives
         * EXDEV, a full target disk gives ENOSPC -- destroyed the old file
         * without writing the new one. Nothing restored it and it never
         * reached the trash.
         *
         * rename() over an existing path is atomic on POSIX, so the common
         * case needs no unlink at all. The cross-device fallback copies to a
         * temporary name *in the destination directory* and renames that into
         * place, so the destination is only ever replaced by a complete file.
         */
        if (!@rename($part, $dest)) {
            $staged = $dest.'.cfh-incoming-'.bin2hex(random_bytes(6));
            if (!copy($part, $staged)) {
                @unlink($staged);
                throw new RuntimeException('Unable to finalise uploaded file', 500);
            }
            if (!rename($staged, $dest)) {
                @unlink($staged);
                throw new RuntimeException('Unable to finalise uploaded file', 500);
            }
            // The bytes are committed. A staging file that will not delete is
            // a cleanup problem, not a reason to fail an upload that landed --
            // reporting failure here made clients retry and produce a
            // duplicate "name (1).ext" from the very same part file.
            if (!@unlink($part)) error_log('[upload] could not remove staging file '.$part);
        }
        @unlink($dir.'/meta.json');
        @rmdir($dir);
        // FileService::relative() rather than a substr() against the raw
        // config value: $dest comes from existing(), which is a realpath,
        // while config['root_dir'] is whatever was written in .env -- so a
        // symlinked, trailing-slash or /./ ROOT_DIR cut the string at the
        // wrong offset. relative() resolves the same root and asserts
        // containment while it is there.
        return ['success'=>true,'name'=>basename($dest),'path'=>$this->files->relative($dest)];
    }

    /** Explicitly cancel an upload and remove all staged bytes. */
    public function cancel(string $id): array
    {
        $dir = $this->sessionDir($id);
        // Upload ids are a deterministic hash of path|name|size|lastModified,
        // so they are guessable -- and the ownership check used to sit inside
        // this is_file() test while the delete below ran unconditionally.
        // Anyone could therefore drop another account's staged bytes just by
        // naming a session whose metadata was missing. Nothing to prove
        // ownership against means nothing to cancel; genuinely orphaned
        // directories are removed by the cleanupAbandoned() TTL.
        if (!is_file($dir.'/meta.json')) throw new RuntimeException('Upload session not found or expired', 404);
        $this->assertOwner($this->readMeta($id));
        if (is_dir($dir)) $this->deleteStagingTree($dir);
        return ['success'=>true];
    }

    private function assertOwner(array $meta): void
    {
        $owner=(int)($meta['ownerUserId']??0);$current=(int)($_SESSION['user_id']??0);
        if($owner<=0||$current<=0||$owner!==$current) throw new RuntimeException('Upload session not found or expired',404);
    }

    private function statusPayload(string $id, array $meta): array
    {
        $part = $this->sessionDir($id).'/data.part';
        $received = is_file($part) ? (filesize($part) ?: 0) : 0;
        return [
            'id'=>$id, 'name'=>$meta['name'], 'size'=>(int)$meta['size'], 'received'=>$received,
            'complete'=>$received === (int)$meta['size'],
            'chunkBytes'=>max(1,(int)$this->config['upload_chunk_mb'])*1024*1024
        ];
    }

    private function readMeta(string $id): array
    {
        $id = $this->validId($id);
        $file = $this->sessionDir($id).'/meta.json';
        if (!is_file($file)) throw new RuntimeException('Upload session not found or expired', 404);
        $meta = json_decode((string)file_get_contents($file), true);
        // Distinct from every other failure so init() can tell "this session's
        // metadata is unreadable, so recreate it" apart from "this session
        // belongs to someone else, so refuse". Those two were previously
        // indistinguishable, and the second was treated as the first.
        if (!is_array($meta)) throw new RuntimeException('Upload metadata is invalid', self::META_UNREADABLE);
        return $meta;
    }

    /**
     * Persist metadata atomically. A temporary file is fully written before it
     * replaces meta.json, preventing interrupted requests from leaving corrupt
     * JSON that blocks future resume attempts.
     */
    private function writeMeta(string $id, array $meta): void
    {
        $dir = $this->sessionDir($id);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create upload session directory', 500);
        }

        $json = json_encode($meta, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $tmp = $dir.'/meta.'.bin2hex(random_bytes(6)).'.tmp';
        $file = $dir.'/meta.json';

        $bytes = @file_put_contents($tmp, $json);
        if ($bytes === false || $bytes !== strlen($json)) {
            @unlink($tmp);
            throw new RuntimeException('Upload staging directory is not writable by PHP', 500);
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to commit upload metadata', 500);
        }
        @touch($dir);
    }

    /** Create and validate the application-owned staging root. */
    private function ensureStagingRoot(): void
    {
        if (!is_dir($this->stagingRoot) && !@mkdir($this->stagingRoot, 0775, true) && !is_dir($this->stagingRoot)) {
            throw new RuntimeException('Unable to create upload staging directory', 500);
        }
        $this->ensureWritableDirectory($this->stagingRoot, 'upload staging directory');
    }

    /**
     * Verify PHP can create, write and remove files in a directory.
     * Android shared/emulated storage can support normal I/O without reliable
     * Unix permission reporting or advisory flock() semantics.
     */
    private function ensureWritableDirectory(string $dir, string $label): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create '.$label, 500);
        }

        $probe = $dir.'/.write-test-'.bin2hex(random_bytes(8));
        $payload = 'cloudhub-write-test';
        $handle = @fopen($probe, 'wb');

        if ($handle === false) {
            throw new RuntimeException(ucfirst($label).' cannot create files', 500);
        }

        $written = @fwrite($handle, $payload);
        @fflush($handle);
        @fclose($handle);

        if ($written !== strlen($payload)) {
            @unlink($probe);
            throw new RuntimeException(ucfirst($label).' cannot write files', 500);
        }

        if (!@unlink($probe)) {
            throw new RuntimeException(ucfirst($label).' cannot delete temporary files', 500);
        }
    }

    /**
     * Recursively remove a staging path, refusing anything outside the staging
     * root.
     *
     * FileService::deleteTree() cannot be used here: its containment check is
     * anchored to ROOT_DIR (storage/files) while staging lives in
     * storage/uploads, so every call raised "Path escapes the configured
     * storage root". That made cancel() always fail, and made init() fail for
     * everyone once any session aged past UPLOAD_ABANDON_HOURS, because init()
     * calls cleanupAbandoned() first.
     */
    private function deleteStagingTree(string $path): void
    {
        $normalised = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $this->stagingRoot), '/');
        if ($normalised === $root || !str_starts_with($normalised, $root.'/')) {
            throw new RuntimeException('Path escapes the upload staging root', 403);
        }

        // Symlinks are unlinked, never followed, so a staged link cannot be
        // used to delete files elsewhere on the filesystem.
        if (is_link($normalised)) {
            if (!@unlink($normalised)) throw new RuntimeException('Unable to remove upload staging link', 500);
            return;
        }

        if (is_dir($normalised)) {
            foreach (scandir($normalised) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $this->deleteStagingTree($normalised.'/'.$entry);
            }
            if (!@rmdir($normalised)) throw new RuntimeException('Unable to remove upload staging directory', 500);
            return;
        }

        // Suppressed because each result is checked here and turned into an
        // exception naming what could not be removed; the raw warning is the
        // same fact with less context, and cleanupAbandoned() logs the throw.
        if (file_exists($normalised) && !@unlink($normalised)) {
            throw new RuntimeException('Unable to remove upload staging file', 500);
        }
    }

    private function sessionDir(string $id): string { return $this->stagingRoot.'/'.$this->validId($id); }
    private function validId(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', $id)) throw new RuntimeException('Invalid upload ID', 400);
        return $id;
    }
    private function normaliseConflict(string $value): string
    {
        return in_array($value, ['rename','overwrite','reject'], true) ? $value : 'rename';
    }
    private function uniqueDestination(string $dir, string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        for ($i=1; $i<10000; $i++) {
            $candidate = $dir.'/'.$stem.' ('.$i.')'.($ext!==''?'.'.$ext:'');
            if (!file_exists($candidate)) return $candidate;
        }
        throw new RuntimeException('Unable to choose a unique filename', 409);
    }
}
