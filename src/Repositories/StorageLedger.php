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
            // The delete and the insert are one unit: a failure between them
            // left the old row gone and no new one written, so the bytes on
            // disk stopped counting against anybody's quota.
            $this->transactionally(function() use ($server, $path, $name, $size, $mime, $userId): void {
                // deleteRows() rather than forget(): forget() swallows its own
                // failures by design, which inside a transaction would let a
                // failed delete commit alongside the insert and leave two rows
                // for one path -- charging the quota twice.
                $this->deleteRows($path);   // an overwrite replaces the old row
                $stmt = $this->db->prepare(
                    'INSERT INTO file_metadata (server_id, file_path, original_name, size, mime_type, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$server, $path, $name, max(0, $size), $mime, $userId]);
            });
        } catch (Throwable $e) {
            error_log('[ledger] record failed: '.$e->getMessage());
        }
    }

    /**
     * Run $work inside a transaction, joining one that is already open.
     *
     * PDO does not nest transactions, so a caller already inside one is left
     * to own the commit; this only begins, commits and rolls back the
     * transaction it opened itself.
     */
    private function transactionally(callable $work): void
    {
        if ($this->db->inTransaction()) { $work(); return; }
        $this->db->beginTransaction();
        try {
            $work();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Drop the row for a path, and for everything beneath it when it is a folder. */
    public function forget(string $path): void
    {
        try {
            $this->deleteRows($path);
        } catch (Throwable $e) {
            error_log('[ledger] forget failed: '.$e->getMessage());
        }
    }

    /** The delete itself, which reports failure so a caller in a transaction can abort. */
    private function deleteRows(string $path): void
    {
        $prefix = rtrim($path, '/').'/';
        // mb_strlen, not strlen: the column is utf8mb4 and MySQL's SUBSTR()
        // counts characters, so a byte length overshoots for any non-ASCII
        // name. "/Fotos N/" is 10 bytes but 9 characters, so the comparison
        // took "/Fotos N/a" and matched nothing -- every row beneath an
        // accented, CJK or emoji folder survived its deletion and kept
        // counting against the owner's quota.
        $stmt = $this->db->prepare('DELETE FROM file_metadata WHERE file_path = ? OR SUBSTR(file_path, 1, ?) = ?');
        $stmt->execute([$path, mb_strlen($prefix), $prefix]);
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
            // All of it or none of it: a failure partway used to leave some
            // descendants renamed and the rest pointing at a folder that no
            // longer exists.
            $this->transactionally(function() use ($from, $to): void {
            $stmt = $this->db->prepare('UPDATE file_metadata SET file_path = ? WHERE file_path = ?');
            $stmt->execute([$to, $from]);

            $prefix = rtrim($from, '/').'/';
            // mb_strlen for SQL, strlen for PHP: MySQL's SUBSTR() counts
            // characters on a utf8mb4 column while PHP's substr() counts
            // bytes, so the two lengths are genuinely different numbers and
            // each has to match the function it is passed to. Using the byte
            // length in the query left every descendant of an accented, CJK
            // or emoji folder pointing at the old path after a move.
            $stmt = $this->db->prepare('SELECT id, file_path FROM file_metadata WHERE SUBSTR(file_path, 1, ?) = ?');
            $stmt->execute([mb_strlen($prefix), $prefix]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $update = $this->db->prepare('UPDATE file_metadata SET file_path = ? WHERE id = ?');
            foreach ($rows as $row) {
                $update->execute([rtrim($to, '/').'/'.substr((string)$row['file_path'], strlen($prefix)), (int)$row['id']]);
            }
            });
        } catch (Throwable $e) {
            error_log('[ledger] relocate failed: '.$e->getMessage());
        }
    }

    /**
     * The rows for a path and everything beneath it, for a trash entry to keep.
     *
     * Trashing forgets them -- trashed bytes count towards nobody's quota --
     * and a restore used to bring the file back attributed to nobody, so
     * upload, trash, restore stepped round a quota. The trash entry keeps
     * these and reattribute() gives them back.
     *
     * @return list<array{path:string,name:string,size:int,mime:?string,userId:?int}>
     */
    public function rowsUnder(string $path): array
    {
        try {
            $prefix = rtrim($path, '/').'/';
            $stmt = $this->db->prepare(
                'SELECT file_path, original_name, size, mime_type, uploaded_by FROM file_metadata
                 WHERE file_path = ? OR SUBSTR(file_path, 1, ?) = ?');
            $stmt->execute([$path, mb_strlen($prefix), $prefix]);
            return array_map(static fn(array $r): array => [
                'path' => (string)$r['file_path'],
                'name' => (string)$r['original_name'],
                'size' => (int)$r['size'],
                'mime' => $r['mime_type'] === null ? null : (string)$r['mime_type'],
                'userId' => $r['uploaded_by'] === null ? null : (int)$r['uploaded_by'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            error_log('[ledger] rowsUnder failed: '.$e->getMessage());
            return [];
        }
    }

    /**
     * Give restored bytes back to whoever uploaded them.
     *
     * $rows are what rowsUnder() returned when the item was trashed; $from is
     * where it was then and $to where it was restored, which differs when the
     * original name was taken in the meantime. Rows outside $from are ignored.
     */
    public function reattribute(array $rows, string $from, string $to): void
    {
        $prefix = rtrim($from, '/').'/';
        foreach ($rows as $row) {
            $path = is_array($row) ? (string)($row['path'] ?? '') : '';
            if ($path === $from) $restored = $to;
            elseif ($path !== '' && str_starts_with($path, $prefix)) $restored = rtrim($to, '/').'/'.substr($path, strlen($prefix));
            else continue;
            $this->record($restored, (string)($row['name'] ?? basename($restored)), (int)($row['size'] ?? 0),
                isset($row['mime']) ? (string)$row['mime'] : null,
                isset($row['userId']) ? (int)$row['userId'] : null);
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
     *
     * The window advances between calls and wraps at the end of the table.
     * Without the cursor this took `ORDER BY id LIMIT 500` every time, so it
     * examined the same lowest 500 ids forever: on a table larger than that,
     * rows above the watermark were never checked by any code path, a user's
     * recorded usage only ever grew, and a configured quota eventually locked
     * them out permanently with no admin remedy.
     */
    public function sweep(FileService $files, int $limit = 500): int
    {
        try {
            $limit = max(1, $limit);
            $cursor = $this->readSweepCursor();

            $stmt = $this->db->prepare('SELECT id, file_path FROM file_metadata WHERE id > ? ORDER BY id LIMIT '.$limit);
            $stmt->execute([$cursor]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Short window means the end of the table was reached, so the next
            // call starts over from the beginning.
            $this->writeSweepCursor(count($rows) < $limit ? 0 : (int)$rows[count($rows) - 1]['id']);

            $stale = [];
            foreach ($rows as $row) {
                try {
                    $files->existing((string)$row['file_path']);
                } catch (Throwable) {
                    $stale[] = (int)$row['id'];
                }
            }
            if (!$stale) return 0;
            // Every element came from (int) above, so this cannot carry
            // anything but integers into the statement.
            $this->db->exec('DELETE FROM file_metadata WHERE id IN ('.implode(',', $stale).')');
            return count($stale);
        } catch (Throwable $e) {
            error_log('[ledger] sweep failed: '.$e->getMessage());
            return 0;
        }
    }

    /**
     * Where the next sweep window starts.
     *
     * Kept beside the usage cache rather than in the database: it is a hint,
     * losing it costs one repeated window, and it must not turn a read-only
     * route into a write to the file store.
     */
    private function sweepCursorFile(): string
    {
        return dirname(__DIR__, 2).'/storage/.cache/sweep-cursor';
    }

    private function readSweepCursor(): int
    {
        $file = $this->sweepCursorFile();
        return is_file($file) ? max(0, (int)@file_get_contents($file)) : 0;
    }

    private function writeSweepCursor(int $cursor): void
    {
        $file = $this->sweepCursorFile();
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) return;
        // No LOCK_EX: the cursor is a single-integer hint, and on the Android
        // shared storage this app targets flock() is unreliable -- there
        // file_put_contents() with LOCK_EX can fail outright and never advance
        // the cursor, freezing the sweep on one window so a user's recorded
        // usage only grows and a configured quota eventually locks them out. A
        // one-write torn value just reads back as 0 and re-sweeps from the
        // start, which is harmless.
        @file_put_contents($file, (string)max(0, $cursor));
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
