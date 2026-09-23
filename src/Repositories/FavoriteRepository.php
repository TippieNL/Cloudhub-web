<?php
declare(strict_types=1);

namespace CloudHub\Repositories;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The files each account has starred.
 *
 * A favorite is a row per account and path. It is a preference, not a change
 * to the file store, which is why a viewer may keep favorites of their own and
 * why nothing here touches the disk.
 *
 * Paths are stored as they are everywhere else in the database -- the
 * canonical relative path -- and kept pointed at their file the same way share
 * links and the upload ledger are: a rename or a move carries them along, a
 * permanent delete drops them, and a trash entry keeps them so that a restore
 * gives them back (see rowsUnder() and reinstate()).
 *
 * `path_hash` is what makes one favorite per account and file a constraint
 * rather than a hope. file_path is TEXT and can be 4 KB long, which no MySQL
 * index can cover whole; a prefix index would call two long paths that share
 * their first 190 characters the same file. The hash is also compared
 * byte for byte, where file_path compares under the column's case-insensitive
 * collation -- "/a.jpg" and "/A.jpg" are two files on the storage this runs on.
 *
 * Two kinds of method, deliberately different:
 *
 *  - add(), remove(), list() and removeMany() answer the person asking, so a
 *    failure is thrown and reported to them.
 *  - forget(), relocate(), rowsUnder(), reinstate() and forgetUser() run
 *    beside an operation that has already happened -- a file deleted, an
 *    account removed. They log and carry on, as the ledger and share
 *    bookkeeping do: a favorite is never worth failing a delete for.
 */
final class FavoriteRepository
{
    /**
     * Enough for any real collection, and a bound on what one account can
     * make the database hold -- a viewer may add favorites, and nothing else a
     * viewer does writes rows without limit.
     */
    public const MAX_PER_USER = 5000;

    public function __construct(private readonly PDO $db) {}

    public static function hash(string $path): string
    {
        return hash('sha256', $path);
    }

    /**
     * Star a file. Returns false when it already was one, which is not an
     * error: two taps, two tabs or two devices asking for the same favorite
     * all end with one.
     */
    public function add(int $userId, string $path): bool
    {
        if ($this->has($userId, $path)) return false;
        if ($this->count($userId) >= self::MAX_PER_USER) {
            throw new RuntimeException('You have '.self::MAX_PER_USER.' favorites, which is the most one account can keep', 409);
        }
        try {
            $stmt = $this->db->prepare('INSERT INTO favorites (user_id, file_path, path_hash) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $path, self::hash($path)]);
            return true;
        } catch (PDOException $e) {
            // Lost a race with an identical request: the unique key did its
            // job, and the file is a favorite either way.
            if ($e->getCode() === '23000') return false;
            throw $e;
        }
    }

    /** Unstar a file. Returns false when it was not a favorite. */
    public function remove(int $userId, string $path): bool
    {
        $stmt = $this->db->prepare('DELETE FROM favorites WHERE user_id = ? AND path_hash = ?');
        $stmt->execute([$userId, self::hash($path)]);
        return $stmt->rowCount() > 0;
    }

    public function has(int $userId, string $path): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND path_hash = ?');
        $stmt->execute([$userId, self::hash($path)]);
        return $stmt->fetchColumn() !== false;
    }

    public function count(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * One account's favorites, most recently starred first.
     *
     * @return list<array{path:string,createdAt:string}>
     */
    public function list(int $userId): array
    {
        // id breaks ties: rows added within one second share a timestamp.
        $stmt = $this->db->prepare('SELECT file_path, created_at FROM favorites WHERE user_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$userId]);
        return array_map(static fn(array $r): array => [
            'path' => (string)$r['file_path'],
            'createdAt' => (string)$r['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Drop named rows of one account -- the listing's clean-up of files that are gone. */
    public function removeMany(int $userId, array $paths): void
    {
        if (!$paths) return;
        $stmt = $this->db->prepare('DELETE FROM favorites WHERE user_id = ? AND path_hash = ?');
        foreach ($paths as $path) $stmt->execute([$userId, self::hash((string)$path)]);
    }

    /** Every favorite of an account that has been deleted. */
    public function forgetUser(int $userId): void
    {
        try {
            $this->db->prepare('DELETE FROM favorites WHERE user_id = ?')->execute([$userId]);
        } catch (Throwable $e) {
            error_log('[favorites] forgetUser failed: '.$e->getMessage());
        }
    }

    /** Every account's favorite of a path, and of everything beneath it when it is a folder. */
    public function forget(string $path): void
    {
        try {
            $ids = array_column($this->matching($path), 'id');
            if ($ids) $this->deleteIds($ids);
        } catch (Throwable $e) {
            error_log('[favorites] forget failed: '.$e->getMessage());
        }
    }

    /**
     * Follow a rename or a move, including everything beneath a moved folder.
     *
     * Rows already at the destination are dropped first. They can only be
     * stale -- a move never lands on an occupied name except over WebDAV with
     * Overwrite, where what was there has just been displaced -- and left in
     * place they would collide with the rows arriving on the unique key and
     * abort the whole relocation.
     */
    public function relocate(string $from, string $to): void
    {
        if ($from === $to) return;
        try {
            $this->transactionally(function() use ($from, $to): void {
                $moving = $this->matching($from);
                if (!$moving) return;
                $stale = array_column($this->matching($to), 'id');
                if ($stale) $this->deleteIds($stale);

                $update = $this->db->prepare('UPDATE favorites SET file_path = ?, path_hash = ? WHERE id = ?');
                foreach ($moving as $row) {
                    $moved = self::rebase($row['path'], $from, $to);
                    $update->execute([$moved, self::hash($moved), $row['id']]);
                }
            });
        } catch (Throwable $e) {
            error_log('[favorites] relocate failed: '.$e->getMessage());
        }
    }

    /**
     * The favorites of a path and everything beneath it, for a trash entry to
     * keep until it is restored.
     *
     * @return list<array{userId:int,path:string,createdAt:string}>
     */
    public function rowsUnder(string $path): array
    {
        try {
            return array_map(static fn(array $r): array => [
                'userId' => $r['userId'], 'path' => $r['path'], 'createdAt' => $r['createdAt'],
            ], $this->matching($path));
        } catch (Throwable $e) {
            error_log('[favorites] rowsUnder failed: '.$e->getMessage());
            return [];
        }
    }

    /**
     * Give a restored item its favorites back.
     *
     * $rows are what rowsUnder() returned when it was trashed, $from where it
     * was then and $to where it was restored -- which differs when the name
     * had been taken in the meantime. The original date is kept, so a restored
     * favorite sorts where it did rather than jumping to the top.
     */
    public function reinstate(array $rows, string $from, string $to): void
    {
        if (!$rows) return;
        try {
            $insert = $this->db->prepare('INSERT INTO favorites (user_id, file_path, path_hash, created_at) VALUES (?, ?, ?, ?)');
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['userId'], $row['path'])) continue;
                $path = (string)$row['path'];
                if ($path !== $from && !str_starts_with($path, rtrim($from, '/').'/')) continue;
                $restored = self::rebase($path, $from, $to);
                $at = is_string($row['createdAt'] ?? null) && $row['createdAt'] !== '' ? $row['createdAt'] : gmdate('Y-m-d H:i:s');
                try {
                    $insert->execute([(int)$row['userId'], $restored, self::hash($restored), $at]);
                } catch (PDOException $e) {
                    // Starred again while it sat in the trash: already there.
                    if ($e->getCode() !== '23000') throw $e;
                }
            }
        } catch (Throwable $e) {
            error_log('[favorites] reinstate failed: '.$e->getMessage());
        }
    }

    /**
     * Rows at a path or beneath it, compared exactly.
     *
     * SUBSTR narrows the search in SQL, where it compares under the column's
     * collation -- case-insensitively on MySQL -- and PHP then keeps only the
     * byte-for-byte matches, so moving "/photos" never takes "/Photos" along.
     * mb_strlen for SQL, because SUBSTR() counts characters on a utf8mb4
     * column; str_starts_with in PHP, which compares bytes.
     *
     * @return list<array{id:int,userId:int,path:string,createdAt:string}>
     */
    private function matching(string $path): array
    {
        $prefix = rtrim($path, '/').'/';
        $stmt = $this->db->prepare('SELECT id, user_id, file_path, created_at FROM favorites WHERE path_hash = ? OR SUBSTR(file_path, 1, ?) = ?');
        $stmt->execute([self::hash($path), mb_strlen($prefix), $prefix]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $p = (string)$r['file_path'];
            if ($p !== $path && !str_starts_with($p, $prefix)) continue;
            $rows[] = ['id' => (int)$r['id'], 'userId' => (int)$r['user_id'], 'path' => $p, 'createdAt' => (string)$r['created_at']];
        }
        return $rows;
    }

    private static function rebase(string $path, string $from, string $to): string
    {
        if ($path === $from) return $to;
        return rtrim($to, '/').'/'.substr($path, strlen(rtrim($from, '/').'/'));
    }

    /** @param list<int> $ids */
    private function deleteIds(array $ids): void
    {
        // Every element is an int from matching(), so nothing else can reach the statement.
        $this->db->exec('DELETE FROM favorites WHERE id IN ('.implode(',', array_map('intval', $ids)).')');
    }

    /** As StorageLedger's: join a transaction already open, otherwise own one. */
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
}
