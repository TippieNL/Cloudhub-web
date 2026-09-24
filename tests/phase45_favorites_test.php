<?php
declare(strict_types=1);

/**
 * Favorites: a star per account and file, kept pointed at its file.
 *
 * A favorite is stored against a path, like a share link or a ledger row, so
 * it is only worth anything if every way a path changes brings it along: a
 * rename, a move (of the file or of a folder above it, through the API or
 * WebDAV), and a delete -- which drops it, unless the delete went to the trash,
 * in which case a restore gives it back.
 *
 * The contract is Cloudhub-2's, which its Android app speaks: this build
 * serves the same /api/favorites so the app works against either server. The
 * repository runs against a real in-memory SQLite database and the trash
 * against a real directory tree; the route wiring is pinned against the source.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Repositories/FavoriteRepository.php';

use CloudHub\Services\FileService;
use CloudHub\Repositories\FavoriteRepository;

$root = dirname(__DIR__);
$checks = [];

$fresh = function (): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE favorites (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        file_path TEXT NOT NULL, path_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_id, path_hash))');
    return $db;
};
$paths = function (FavoriteRepository $favs, int $user): array {
    $p = array_column($favs->list($user), 'path');
    sort($p);
    return $p;
};

// --- one star per account and file -----------------------------------------
$db = $fresh();
$favs = new FavoriteRepository($db);
$checks['starring stores a favorite'] = $favs->add(7, '/Photos/a.jpg') === true;
$checks['starring it again stores nothing new'] = $favs->add(7, '/Photos/a.jpg') === false && $favs->count(7) === 1;
$checks['a race the unique key settles is not an error'] = (function () use ($db, $favs): bool {
    // As if a second request inserted between this one's check and insert.
    $db->exec("INSERT INTO favorites (user_id, file_path, path_hash) VALUES (7, '/raced.jpg', '".FavoriteRepository::hash('/raced.jpg')."')");
    return $favs->add(7, '/raced.jpg') === false && $favs->count(7) === 2;
})();
$favs->add(9, '/Photos/a.jpg');
$checks['each account keeps its own'] = $paths($favs, 7) === ['/Photos/a.jpg', '/raced.jpg'] && $paths($favs, 9) === ['/Photos/a.jpg'];
$checks['two spellings that differ only in case are two files'] = $favs->add(7, '/Photos/A.jpg') === true && $favs->count(7) === 3;
$checks['unstarring removes only that account\'s'] = $favs->remove(7, '/Photos/a.jpg') === true
    && $favs->remove(7, '/Photos/a.jpg') === false && $paths($favs, 9) === ['/Photos/a.jpg'];

$checks['the newest favorite is listed first'] = (function () use ($fresh): bool {
    $favs = new FavoriteRepository($fresh());
    $favs->add(1, '/one'); $favs->add(1, '/two'); $favs->add(1, '/three');
    // Same second for all three: the insertion order has to break the tie.
    return array_column($favs->list(1), 'path') === ['/three', '/two', '/one'];
})();

$checks['one account cannot store without limit'] = (function () use ($fresh): bool {
    $db = $fresh();
    $favs = new FavoriteRepository($db);
    $db->beginTransaction();
    $insert = $db->prepare('INSERT INTO favorites (user_id, file_path, path_hash) VALUES (3, ?, ?)');
    for ($i = 0; $i < FavoriteRepository::MAX_PER_USER; $i++) $insert->execute(["/f$i", FavoriteRepository::hash("/f$i")]);
    $db->commit();
    try { $favs->add(3, '/one-too-many'); return false; }
    catch (RuntimeException $e) { return $e->getCode() === 409 && $favs->add(4, '/someone-else') === true; }
})();

// --- following a path ----------------------------------------------------------
$db = $fresh();
$favs = new FavoriteRepository($db);
$favs->add(7, '/Fotos é/one.jpg');
$favs->add(7, '/Fotos é/sub/two.jpg');
$favs->add(8, '/Fotos é/sub/two.jpg');
$favs->add(7, '/Fotos émigré.jpg');     // shares the prefix, not the folder
$favs->add(7, '/a_b/x.txt');
$favs->add(7, '/axb/y.txt');            // an underscore is not a wildcard

$favs->relocate('/Fotos é/one.jpg', '/Fotos é/uno.jpg');
$checks['a rename carries the star'] = in_array('/Fotos é/uno.jpg', $paths($favs, 7), true)
    && !in_array('/Fotos é/one.jpg', $paths($favs, 7), true);
$favs->relocate('/Fotos é', '/Archive/Fotos é');
$checks['a folder move carries every star beneath it, for every account'] =
    $paths($favs, 7) === ['/Archive/Fotos é/sub/two.jpg', '/Archive/Fotos é/uno.jpg', '/Fotos émigré.jpg', '/a_b/x.txt', '/axb/y.txt']
    && $paths($favs, 8) === ['/Archive/Fotos é/sub/two.jpg'];
$checks['and each moved row is found by its new path'] = (function () use ($favs): bool {
    // The hash moved with the path, or the moved star could never be removed.
    return $favs->remove(8, '/Archive/Fotos é/sub/two.jpg') === true && $favs->count(8) === 0;
})();
$favs->relocate('/a_b', '/moved');
$checks['a wildcard character in a folder name matches literally'] = in_array('/axb/y.txt', $paths($favs, 7), true)
    && in_array('/moved/x.txt', $paths($favs, 7), true);

$checks['a move onto a stale favorite does not lose the moving one'] = (function () use ($fresh, $paths): bool {
    $favs = new FavoriteRepository($fresh());
    $favs->add(1, '/old.jpg');          // its file gone by a route nobody saw
    $favs->add(1, '/new.jpg');
    $favs->relocate('/new.jpg', '/old.jpg');
    return $paths($favs, 1) === ['/old.jpg'];
})();

$favs->forget('/Archive');
$checks['forgetting a folder drops every star beneath it'] = $paths($favs, 7) === ['/Fotos émigré.jpg', '/axb/y.txt', '/moved/x.txt'];
$favs->forgetUser(7);
$checks['a deleted account takes its favorites with it'] = $favs->count(7) === 0;

$checks['bookkeeping never fails the operation it follows'] = (function (): bool {
    // No favorites table: an installation that has not run migrate.php yet.
    $favs = new FavoriteRepository(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    $log = ini_set('error_log', '/dev/null');
    $favs->forget('/x'); $favs->relocate('/x', '/y'); $favs->reinstate([['userId' => 1, 'path' => '/x']], '/x', '/y');
    $favs->forgetUser(1);
    $rows = $favs->rowsUnder('/x');
    ini_set('error_log', (string)$log);
    return $rows === [];
})();

// --- through the trash and back -------------------------------------------------
$db = $fresh();
$favs = new FavoriteRepository($db);
$base = sys_get_temp_dir().'/cloudhub-p45-'.bin2hex(random_bytes(5));
mkdir($base.'/Fotos é/sub', 0775, true);
file_put_contents($base.'/Fotos é/one.jpg', '1');
file_put_contents($base.'/Fotos é/sub/two.jpg', '2');
$fs = new FileService(['root_dir' => $base, 'read_only' => false]);

$favs->add(7, '/Fotos é/one.jpg');
$favs->add(8, '/Fotos é/sub/two.jpg');
$db->exec("UPDATE favorites SET created_at = '2020-01-02 03:04:05' WHERE user_id = 8");

$rows = $favs->rowsUnder('/Fotos é');
$meta = $fs->trash($fs->existing('/Fotos é'), 'tester', [], $rows);
$favs->forget($meta['originalPath']);
$checks['trashing unstars it'] = $favs->count(7) === 0 && $favs->count(8) === 0;
$checks['the entry keeps who had starred it'] = is_file($base.'/.trash/'.$meta['id'].'/favorites.json');
$checks['the trash listing does not hand that to anyone'] =
    !str_contains((string)json_encode($fs->trashList()), 'userId');

mkdir($base.'/Fotos é');                // the name is taken in the meantime
$restored = $fs->restore($meta['id']);
$favs->reinstate($restored['favorites'], $restored['originalPath'], $restored['path']);
$checks['a restore gives the stars back, at the path it restored to'] = $restored['path'] === '/Fotos é (2)'
    && $paths($favs, 7) === ['/Fotos é (2)/one.jpg'] && $paths($favs, 8) === ['/Fotos é (2)/sub/two.jpg'];
$checks['with the date each was first starred'] = ($favs->list(8)[0]['createdAt'] ?? '') === '2020-01-02 03:04:05';
$checks['a star added while it was in the trash is not doubled'] = (function () use ($favs): bool {
    $favs->reinstate([['userId' => 7, 'path' => '/Fotos é/one.jpg', 'createdAt' => '']], '/Fotos é', '/Fotos é (2)');
    return $favs->count(7) === 1;
})();
$checks['rows from elsewhere are never reinstated'] = (function () use ($favs): bool {
    $favs->reinstate([['userId' => 9, 'path' => '/elsewhere.jpg']], '/Fotos é', '/Fotos é');
    return $favs->count(9) === 0;
})();
$checks['an entry trashed without favorites restores as before'] = (function () use ($fs, $base): bool {
    file_put_contents($base.'/plain.txt', 'x');
    $m = $fs->trash($fs->existing('/plain.txt'), 'tester');
    $r = $fs->restore($m['id']);
    return $r['favorites'] === [] && $r['path'] === '/plain.txt';
})();
$checks['a listing row can be had for one path'] = (function () use ($fs): bool {
    $row = $fs->describe('/Fotos é (2)/one.jpg');
    return $row['name'] === 'one.jpg' && $row['path'] === '/Fotos é (2)/one.jpg' && $row['isDirectory'] === false;
})();

$rmrf = function (string $p) use (&$rmrf): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') $rmrf($p.'/'.$n); @rmdir($p); }
};
$rmrf($base);

// --- the schema ---------------------------------------------------------------
$schema = (string)file_get_contents($root.'/database/schema.sql');
$migrate = (string)file_get_contents($root.'/database/migrate.php');
foreach (['a fresh install' => $schema, 'an upgrade' => $migrate] as $what => $sql) {
    $checks["$what creates the favorites table"] = str_contains($sql, 'CREATE TABLE IF NOT EXISTS favorites');
    $checks["$what keeps one favorite per account and file"] =
        str_contains($sql, 'UNIQUE KEY uq_favorite_user_path(user_id, path_hash)');
}

// --- the routes: every way a path changes brings its favorites along -----------
$index = (string)file_get_contents($root.'/public/index.php');
$dav = (string)file_get_contents($root.'/src/Services/WebDav.php');

$checks['starring is exempt from the write capability, not from CSRF'] =
    (bool)preg_match("/\\\$writeExemptPost = \\[[^\\]]*'\\/api\\/favorites'[^\\]]*\\];/", $index)
    && (bool)preg_match('/Auth::verifyCsrf\(\);.*?\$writeExemptPost/s', $index);
$checks['the list route releases the session lock first'] = (function () use ($index): bool {
    $at = strpos($index, "\$path === '/api/favorites' && \$method === 'GET'");
    return $at !== false && str_contains(substr($index, $at, 200), 'release_session_lock();');
})();
$checks['the folder listing still touches no database'] = (function () use ($index): bool {
    $at = strpos($index, "\$path === '/api/files/list' && \$method === 'GET'");
    $end = strpos($index, "\$path === '/api/files/download'", (int)$at);
    if ($at === false || $end === false) return false;
    $body = substr($index, $at, $end - $at);
    return !str_contains($body, 'favorites(') && !str_contains($body, 'db(');
})();
$checks['only a file can be starred'] = str_contains($index, "throw new RuntimeException('Only files can be favorites', 400);");
$checks['a permanent delete drops the stars'] = str_contains($index, "shares_forget(\$rel);\n        favorites()->forget(\$rel);");
$checks['a delete to the trash keeps them with the entry'] =
    str_contains($index, "favorites()->rowsUnder(\$trashed));")
    && str_contains($index, "favorites()->forget(\$meta['originalPath']);");
$checks['a restore gives them back'] =
    str_contains($index, "favorites()->reinstate(\$restored['favorites'], \$restored['originalPath'], \$restored['path']);");
$checks['a move carries them'] =
    str_contains($index, 'favorites()->relocate($fs->relative($source), $fs->relative($target));');
$checks['a rename and a WebDAV move carry them'] = substr_count($index, 'favorites()->relocate($from, $to);') === 2;
$checks['a WebDAV delete drops them'] = str_contains($index, "shares_forget(\$rel);\n                favorites()->forget(\$rel);");
$checks['WebDAV is handed them for the trash'] =
    str_contains($index, "'favorites' => fn(string \$rel): array => favorites()->rowsUnder(\$rel),")
    && substr_count($dav, '$favorites?$favorites(') === 2;
$checks['a deleted account takes them along'] = str_contains($index, 'favorites()->forgetUser($id);');
// --- the contract the Android app decodes --------------------------------------
// FavoritesListing { favorites: List<FileEntry>, limit } and FavoriteResult
// { success, favorite, path, message } in Cloudhub-2's net/Models.kt.
$checks['the list answers favorites and limit'] =
    str_contains($index, "return ['favorites' => \$entries, 'limit' => FavoriteRepository::MAX_PER_USER];");
$checks['each favorite is an ordinary listing row'] =
    str_contains($index, "file_cache()->describeMany('favorites_'.\$user, array_column(\$rows, 'path'));")
    && str_contains((string)file_get_contents($root.'/src/Services/FileCache.php'), '$rows[$i] = $this->files->describe($path);');
$checks['starring answers success, favorite and the stored path'] =
    str_contains($index, "return ['success' => true, 'favorite' => true, 'added' => \$added, 'path' => \$rel,")
    && str_contains($index, "return ['success' => true, 'favorite' => false, 'removed' => \$removed, 'path' => \$rel,");

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
