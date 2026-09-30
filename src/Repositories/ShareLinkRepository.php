<?php
declare(strict_types=1);

namespace CloudHub\Repositories;

use PDO;
use Throwable;

/**
 * Keeps share links pointed at the file they were made for.
 *
 * A link is stored against a path, and MySQL compares file_path under the
 * column's utf8mb4_unicode_ci collation, which ignores case, accents and
 * trailing spaces. "/Report.pdf" and "/report.pdf", or "/café.jpg" and
 * "/cafe.jpg", are one value to the database and two files on the disk. So
 * renaming /report.pdf used to rewrite the link issued for /Report.pdf as well,
 * and whoever held that link was then served a file nobody had shared with
 * them. Deleting one revoked the other's link.
 *
 * SQL narrows the candidates and PHP decides, comparing bytes, exactly as
 * FavoriteRepository::matching() does. Every write then names its rows by
 * token, so nothing the collation merely considers equal is touched.
 */
final class ShareLinkRepository
{
    public function __construct(private readonly PDO $db) {}

    /** Drop the links of a path, and of everything beneath it when it is a folder. */
    public function forget(string $path): void
    {
        $delete = $this->db->prepare('DELETE FROM share_links WHERE token = ?');
        foreach ($this->matching($path) as $row) $delete->execute([$row['token']]);
    }

    /**
     * Follow a rename or a move, including everything beneath a moved folder.
     *
     * All of it or none of it: a failure partway would leave some links
     * following the move and the rest pointing at a path that is gone.
     */
    public function relocate(string $from, string $to): void
    {
        if ($from === $to) return;
        $moving = $this->matching($from);
        if (!$moving) return;
        $this->transactionally(function() use ($moving, $from, $to): void {
            $update = $this->db->prepare('UPDATE share_links SET file_path = ? WHERE token = ?');
            foreach ($moving as $row) $update->execute([self::rebase($row['path'], $from, $to), $row['token']]);
        });
    }

    /**
     * The links at a path or beneath it, compared byte for byte.
     *
     * mb_strlen for SUBSTR(), which counts characters on a utf8mb4 column;
     * str_starts_with() in PHP, which compares bytes.
     *
     * @return list<array{token:string,path:string}>
     */
    public function matching(string $path): array
    {
        $prefix = rtrim($path, '/').'/';
        $stmt = $this->db->prepare('SELECT token, file_path FROM share_links WHERE file_path = ? OR SUBSTR(file_path, 1, ?) = ?');
        $stmt->execute([$path, mb_strlen($prefix), $prefix]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $p = (string)$r['file_path'];
            if ($p !== $path && !str_starts_with($p, $prefix)) continue;
            $rows[] = ['token' => (string)$r['token'], 'path' => $p];
        }
        return $rows;
    }

    private static function rebase(string $path, string $from, string $to): string
    {
        if ($path === $from) return $to;
        return rtrim($to, '/').'/'.substr($path, strlen(rtrim($from, '/').'/'));
    }

    /** As FavoriteRepository's: join a transaction already open, otherwise own one. */
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
