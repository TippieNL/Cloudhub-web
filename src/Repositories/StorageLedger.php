<?php
declare(strict_types=1);

namespace CloudHub\Repositories;

use CloudHub\Services\FileService;
use PDO;
use Throwable;

/**
 * Who uploaded what, so a per-account quota can mean something.
 *
 * This finally gives `file_metadata` a job. The table was declared in
 * schema.sql from the beginning and referenced by no PHP at all, so there was
 * no record anywhere of which account a file came from -- and without that a
 * per-user quota cannot be computed, only guessed at.
 *
 * Two properties matter more than completeness here:
 *
 *  - **Fail open.** Every method swallows its errors. A ledger problem must
 *    mean "the quota does not bind", never "a legitimate upload is refused".
 *    Blocking someone's upload because of a bookkeeping bug is far worse than
 *    letting one through.
 *  - **Self-healing.** The ledger is maintained where CloudHub controls the
 *    path (upload, delete, move, rename, restore, purge), and sweep() drops
 *    rows whose file has since disappeared by any other route -- WebDAV, or a
 *    change made directly on disk. It converges rather than requiring every
 *    write in the system to remember to call it.
 *
 * What it therefore counts: bytes this account uploaded through CloudHub that
 * are still on disk. Files that predate the feature, or that arrived by any
 * other means, are unattributed -- they count towards the whole-store limit
 * but towards nobody's personal quota.
 */
final class StorageLedger
{
    public function __construct(private readonly PDO $db) {}

    public function record(string $path, string $name, int $size, ?string $mime, ?int $userId): void
    {
        try {
            $server = $this->defaultServerId();
            if ($server === null) return;   // nothing to attach the row to
            $this->forget($path);           // an overwrite replaces the old row
            $stmt = $this->db->prepare(
                'INSERT INTO file_metadata (server_id, file_path, original_name, size, mime_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$server, $path, $name, max(0, $size), $mime, $userId]);
        } catch (Throwable $e) {
            error_log('[ledger] record failed: '.$e->getMessage());
        }
    }

    /** Drop the row for a path, and for everything beneath it when it is a folder. */
    public function forget(string $path): void
    {
        try {
            $prefix = rtrim($path, '/').'/';
            $stmt = $this->db->prepare('DELETE FROM file_metadata WHERE file_path = ? OR SUBSTR(file_path, 1, ?) = ?');
            $stmt->execute([$path, strlen($prefix), $prefix]);
        } catch (Throwable $e) {
            error_log('[ledger] forget failed: '.$e->getMessage());
        }
    }

    /**
     * Follow a move or rename, including every path beneath a moved folder.
     *
     * Descendants are rewritten row by row rather than in one UPDATE, because
     * the two databases spell string concatenation differently (`||` against
     * CONCAT) and the row count here is small -- the files one account
     * uploaded into one folder.
     *
     * The prefix match is a SUBSTR comparison rather than LIKE: LIKE has no
     * default escape character in SQLite, so the underscore in an ordinary
     * filename would act as a wildcard and rewrite unrelated paths.
     */
    public function relocate(string $from, string $to): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE file_metadata SET file_path = ? WHERE file_path = ?');
            $stmt->execute([$to, $from]);

            $prefix = rtrim($from, '/').'/';
            $stmt = $this->db->prepare('SELECT id, file_path FROM file_metadata WHERE SUBSTR(file_path, 1, ?) = ?');
            $stmt->execute([strlen($prefix), $prefix]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $update = $this->db->prepare('UPDATE file_metadata SET file_path = ? WHERE id = ?');
            foreach ($rows as $row) {
                $update->execute([rtrim($to, '/').'/'.substr((string)$row['file_path'], strlen($prefix)), (int)$row['id']]);
            }
        } catch (Throwable $e) {
            error_log('[ledger] relocate failed: '.$e->getMessage());
        }
    }

    /** Bytes attributed to one account, or to every account when null. */
    public function usage(?int $userId = null): int
    {
        try {
            if ($userId === null) return (int)$this->db->query('SELECT COALESCE(SUM(size),0) FROM file_metadata')->fetchColumn();
            $stmt = $this->db->prepare('SELECT COALESCE(SUM(size),0) FROM file_metadata WHERE uploaded_by = ?');
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[ledger] usage failed: '.$e->getMessage());
            return 0;   // fail open: an unreadable ledger must not block uploads
        }
    }

    /** @return array<int,array{userId:?int,bytes:int,files:int}> largest first */
    public function usageByUser(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT uploaded_by, COALESCE(SUM(size),0) AS bytes, COUNT(*) AS files
                 FROM file_metadata GROUP BY uploaded_by ORDER BY bytes DESC')->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn(array $r) => [
                'userId' => $r['uploaded_by'] === null ? null : (int)$r['uploaded_by'],
                'bytes' => (int)$r['bytes'],
                'files' => (int)$r['files'],
            ], $rows ?: []);
        } catch (Throwable $e) {
            error_log('[ledger] usageByUser failed: '.$e->getMessage());
            return [];
        }
    }

    /**
     * Drop rows whose file is no longer on disk.
     *
     * The backstop for every write CloudHub does not see. Bounded per call so
     * it can run on an ordinary request without turning one upload into a scan
     * of the entire table.
     */
    public function sweep(FileService $files, int $limit = 500): int
    {
        try {
            $rows = $this->db->query('SELECT id, file_path FROM file_metadata ORDER BY id LIMIT '.max(1, $limit))
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stale = [];
            foreach ($rows as $row) {
                try {
                    $files->existing((string)$row['file_path']);
                } catch (Throwable) {
                    $stale[] = (int)$row['id'];
                }
            }
            if (!$stale) return 0;
            $this->db->exec('DELETE FROM file_metadata WHERE id IN ('.implode(',', $stale).')');
            return count($stale);
        } catch (Throwable $e) {
            error_log('[ledger] sweep failed: '.$e->getMessage());
            return 0;
        }
    }

    private function defaultServerId(): ?int
    {
        try {
            $id = $this->db->query('SELECT id FROM storage_servers ORDER BY is_default DESC, id LIMIT 1')->fetchColumn();
            return $id === false ? null : (int)$id;
        } catch (Throwable) {
            return null;
        }
    }

}
