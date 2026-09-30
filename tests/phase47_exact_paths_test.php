<?php
declare(strict_types=1);

/**
 * Share links and the upload ledger follow the file they were made for.
 *
 * MySQL compares file_path under utf8mb4_unicode_ci, which ignores case,
 * accents and trailing spaces: "/Report.txt" = "/report.txt" and
 * "/café.txt" = "/cafe.txt". On the disk those are different files. Against a
 * real MariaDB, before this was fixed:
 *
 *   - renaming /report.txt moved the public link issued for /Report.txt, so
 *     the recipient of that link was served the other, never-shared file;
 *   - sharing /cafe.txt handed back the live token of /café.txt;
 *   - uploading cafe.txt dropped the ledger row of café.txt, whose bytes then
 *     counted against nobody's quota.
 *
 * SQLite's NOCASE collation gives `=` the same case-insensitivity, which is
 * what this models; the first check proves the model holds, so a bare
 * `file_path = ?` would fail every check below it.
 */
require dirname(__DIR__).'/src/Repositories/ShareLinkRepository.php';
require dirname(__DIR__).'/src/Repositories/StorageLedger.php';
use CloudHub\Repositories\ShareLinkRepository;
use CloudHub\Repositories\StorageLedger;

$checks = [];

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE share_links (token TEXT PRIMARY KEY, file_path TEXT NOT NULL COLLATE NOCASE)');
$db->exec('CREATE TABLE file_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT, server_id INTEGER NOT NULL, file_path TEXT NOT NULL COLLATE NOCASE,
    original_name TEXT NOT NULL, size INTEGER NOT NULL, mime_type TEXT, uploaded_by INTEGER)');
$db->exec('CREATE TABLE storage_servers (id INTEGER PRIMARY KEY AUTOINCREMENT, is_default INTEGER)');
$db->exec('INSERT INTO storage_servers (is_default) VALUES (1)');

$link = static function(string $token) use ($db): ?string {
    $s = $db->prepare('SELECT file_path FROM share_links WHERE token = ?');
    $s->execute([$token]);
    $p = $s->fetchColumn();
    return $p === false ? null : (string)$p;
};
$insert = $db->prepare('INSERT INTO share_links (token, file_path) VALUES (?, ?)');
$insert->execute(['public-Report', '/Report.txt']);
$insert->execute(['private-report', '/report.txt']);

$checks['the model: the column compares case-insensitively, as MySQL does'] =
    (int)$db->query("SELECT COUNT(*) FROM share_links WHERE file_path = '/report.txt'")->fetchColumn() === 2;

// --- share links ------------------------------------------------------------
$shares = new ShareLinkRepository($db);

$checks['matching() returns only the byte-identical path'] =
    array_column($shares->matching('/report.txt'), 'token') === ['private-report'];

$shares->relocate('/report.txt', '/report-final.txt');
$checks['renaming report.txt carries its own link'] = $link('private-report') === '/report-final.txt';
$checks['and leaves the link of Report.txt on Report.txt'] = $link('public-Report') === '/Report.txt';

$insert->execute(['doomed', '/notes.txt']);
$insert->execute(['survivor', '/Notes.txt']);
$shares->forget('/notes.txt');
$checks['deleting notes.txt revokes its link'] = $link('doomed') === null;
$checks['but not the link of Notes.txt'] = $link('survivor') === '/Notes.txt';

// Folders: descendants follow, look-alike siblings and prefixes do not.
$insert->execute(['in-folder', '/Docs/a.txt']);
$insert->execute(['deep', '/Docs/sub/b.txt']);
$insert->execute(['the-folder', '/Docs']);
$insert->execute(['lookalike', '/docs']);
$insert->execute(['prefix-only', '/Docs2/c.txt']);
$shares->relocate('/Docs', '/Archive');
$checks['a moved folder carries its own link'] = $link('the-folder') === '/Archive';
$checks['and every link beneath it'] = $link('in-folder') === '/Archive/a.txt' && $link('deep') === '/Archive/sub/b.txt';
$checks['a folder differing only in case keeps its link'] = $link('lookalike') === '/docs';
$checks['a folder merely sharing a prefix keeps its link'] = $link('prefix-only') === '/Docs2/c.txt';
$shares->relocate('/Archive', '/Archive');
$checks['relocating onto itself changes nothing'] = $link('the-folder') === '/Archive';

// --- the upload ledger -------------------------------------------------------
$ledger = new StorageLedger($db);
$owner = static function(string $path) use ($db): array {
    $s = $db->prepare('SELECT uploaded_by FROM file_metadata WHERE file_path = ?');
    $s->execute([$path]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$ledger->record('/Cafe.txt', 'Cafe.txt', 100, null, 1);
$ledger->record('/cafe.txt', 'cafe.txt', 200, null, 2);
$rows = $db->query('SELECT file_path, uploaded_by, size FROM file_metadata ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$checks['recording cafe.txt keeps the row of Cafe.txt'] =
    count($rows) === 2 && $rows[0]['file_path'] === '/Cafe.txt' && (int)$rows[0]['uploaded_by'] === 1;
$checks['so both uploaders are still charged'] = $ledger->usage(1) === 100 && $ledger->usage(2) === 200;

$ledger->record('/photos/p1.jpg', 'p1.jpg', 10, null, 1);
$ledger->record('/Photos/p2.jpg', 'p2.jpg', 20, null, 2);
$checks['rowsUnder() is exact'] = array_column($ledger->rowsUnder('/photos'), 'path') === ['/photos/p1.jpg'];
$ledger->relocate('/photos', '/pics');
$checks['moving /photos moves only its own rows'] =
    $owner('/pics/p1.jpg') === [1] && (int)$db->query("SELECT COUNT(*) FROM file_metadata WHERE file_path = '/Photos/p2.jpg'")->fetchColumn() === 1;
$ledger->forget('/cafe.txt');
$checks['forgetting cafe.txt keeps Cafe.txt charged'] = $ledger->usage(1) === 110 && $ledger->usage(2) === 20;

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
