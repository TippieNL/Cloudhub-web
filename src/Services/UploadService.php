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
            $this->deleteStagingTree($dir);
            $removed++;
        }
        return $removed;
    }

    /** Create or resume an upload session. */
    public function init(string $targetPath, string $name, int $size, string $clientId, string $conflict): array
    {
        $this->files->writable();
        $this->cleanupAbandoned();

        $max = max(1, (int)$this->config['max_upload_mb']) * 1024 * 1024;
        if ($size < 0 || $size > $max) throw new RuntimeException('File exceeds the '.$this->config['max_upload_mb'].' MB limit', 413);

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
                if ($e->getCode() === 409) throw $e;
                // A prior interrupted metadata write must not permanently block
                // the same file. Remove the damaged session and recreate it.
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
        $this->files->writable();
        $meta = $this->readMeta($id);
        $this->assertOwner($meta);
        $part = $this->sessionDir($id).'/data.part';
        $current = is_file($part) ? (filesize($part) ?: 0) : 0;
        if ($offset !== $current) throw new RuntimeException('Upload offset mismatch; expected '.$current, 409);

        $remaining = (int)$meta['size'] - $current;
        $chunkLimit = max(1, (int)$this->config['upload_chunk_mb']) * 1024 * 1024;
        $in = fopen($input, 'rb');
        $out = fopen($part, 'ab');
        if (!$in || !$out) throw new RuntimeException('Unable to open upload stream', 500);
        $written = 0;
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
            fclose($in);
            fclose($out);
        }
        clearstatcache(true, $part);
        $meta['updatedAt'] = time();
        $this->writeMeta($id, $meta);
        return $this->statusPayload($id, $meta);
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
            if ($policy === 'overwrite' && is_file($dest) && !unlink($dest)) throw new RuntimeException('Unable to replace existing file', 500);
        }

        if (!rename($part, $dest)) {
            if (!copy($part, $dest) || !unlink($part)) throw new RuntimeException('Unable to finalise uploaded file', 500);
        }
        @unlink($dir.'/meta.json');
        @rmdir($dir);
        return ['success'=>true,'name'=>basename($dest),'path'=>substr(str_replace('\\','/',$dest), strlen($this->config['root_dir']))];
    }

    /** Explicitly cancel an upload and remove all staged bytes. */
    public function cancel(string $id): array
    {
        $dir = $this->sessionDir($id);
        if(is_file($dir.'/meta.json')){$meta=$this->readMeta($id);$this->assertOwner($meta);}
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
        if (!is_array($meta)) throw new RuntimeException('Upload metadata is invalid', 500);
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
            if (!unlink($normalised)) throw new RuntimeException('Unable to remove upload staging link', 500);
            return;
        }

        if (is_dir($normalised)) {
            foreach (scandir($normalised) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $this->deleteStagingTree($normalised.'/'.$entry);
            }
            if (!rmdir($normalised)) throw new RuntimeException('Unable to remove upload staging directory', 500);
            return;
        }

        if (file_exists($normalised) && !unlink($normalised)) {
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
