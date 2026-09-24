<?php
declare(strict_types=1);

/**
 * The application cache: phpFastCache behind CloudHub\Helpers\Cache, and the
 * listings, search and favorites FileCache answers from it.
 *
 * What is pinned, and why each matters:
 *
 *   - a recorded search walk answers exactly as FileService::search() does,
 *     for any needle, bound and folder, and notices every change to the tree
 *     it walked -- it has no TTL, so it must never be wrong;
 *   - a cached listing is reused only while its folder, the cache generation
 *     and the TTL all say it may be, and the rows a search returns are never
 *     the recording's;
 *   - the cache can fail in every way it can fail -- missing vendor/, a path
 *     it cannot write, a damaged entry -- without failing the request;
 *   - it refuses a directory account holders or the web server can reach,
 *     because phpFastCache unserialize()s what it reads back;
 *   - storage that answers quickly caches nothing at all.
 *
 * Everything runs against real directories and phpFastCache's real Files
 * driver; the route wiring is pinned against the source.
 */
require dirname(__DIR__).'/src/Helpers/Cache.php';
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/FileCache.php';

use CloudHub\Helpers\Cache;
use CloudHub\Services\FileCache;
use CloudHub\Services\FileService;

$root = dirname(__DIR__);
$checks = [];
$tmp = sys_get_temp_dir().'/cloudhub-p46-'.bin2hex(random_bytes(5));
mkdir($tmp, 0775, true);
// The cache logs what it turns off; keep that out of the test's output, and
// readable by the checks that are about it.
$log = $tmp.'/php.log';
ini_set('error_log', $log);
$logged = static fn(string $needle): bool => str_contains((string)@file_get_contents($log), $needle);

$rmrf = static function (string $p) use (&$rmrf): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') $rmrf($p.'/'.$n); @rmdir($p); }
};

/**
 * A tree with everything a walk must get right: nesting, empty folders, names
 * that differ only in case, non-ASCII names, CloudHub's reserved folders at
 * the root and symlinks, neither of which any walk may enter or list.
 */
$build = static function (string $dir, int $seed): void {
    mt_srand($seed);
    $names = ['alpha', 'Beta', 'photo', 'Photo', 'IMG_2024', 'img-2025', 'notes', 'report', 'Été', '日本語',
        'résumé', 'x', 'xy', 'a b', 'foo.bar', '.hidden', 'readme.md', 'clip.mp4', 'song.MP3', 'deep'];
    mkdir($dir, 0775, true);
    $dirs = [$dir];
    for ($i = 0; $i < 90; $i++) {
        $parent = $dirs[mt_rand(0, count($dirs) - 1)];
        $path = $parent.'/'.$names[mt_rand(0, count($names) - 1)].(mt_rand(0, 2) ? '' : '_'.$i);
        if (file_exists($path)) continue;
        if (mt_rand(0, 2) === 0) { mkdir($path); $dirs[] = $path; }
        else file_put_contents($path, str_repeat('z', mt_rand(0, 40)));
    }
    mkdir($dir.'/empty');
    mkdir($dir.'/.trash/20260101-000000-abcdef01/payload', 0775, true);
    file_put_contents($dir.'/.trash/20260101-000000-abcdef01/payload/photo-trashed', 'x');
    mkdir($dir.'/.uploads');
    file_put_contents($dir.'/.uploads/photo-staged', 'x');
    symlink($dir, $dirs[min(1, count($dirs) - 1)].'/photo-loop');
    symlink('/etc/hostname', $dir.'/photo-link');
};

$treeA = $tmp.'/a/files';
$build($treeA, 46);
$fsA = new FileService(['root_dir' => $treeA, 'read_only' => false]);
// Built now so that they have settled by the time they are needed; see the
// sleep below.
$listDir = $tmp.'/list/files';
mkdir($listDir.'/sub', 0775, true);
foreach (range(1, 20) as $i) file_put_contents($listDir.'/sub/f'.$i.'.txt', str_repeat('.', $i));
file_put_contents($listDir.'/other.txt', 'o');
$treeC = $tmp.'/c/files';
$build($treeC, 4646);

// --- before anything is stored, nothing is loaded ----------------------------

$cacheDir = $tmp.'/cache';
$cfg = static fn(array $over = []): array => $over + [
    'root_dir' => $treeA, 'cache_driver' => 'files', 'cache_path' => $cacheDir,
    'cache_ttl_seconds' => 30, 'cache_min_compute_ms' => 0,
];
Cache::configure($cfg());
$checks['a read with nothing stored misses'] = Cache::get('list_nothing') === null;
$checks['and does not load phpFastCache to find that out'] =
    !class_exists(\Phpfastcache\Helper\Psr16Adapter::class, false);

// --- a recorded walk answers exactly as search() does ------------------------

// Folders for the change checks further down, made now so they settle too.
mkdir($treeA.'/rows-dir');
file_put_contents($treeA.'/rows-dir/readme-grow.md', 'short');
mkdir($treeA.'/rm-dir');
file_put_contents($treeA.'/rm-dir/needle-removed.txt', 'r');
mkdir($treeA.'/swap-dir');
file_put_contents($treeA.'/swap-dir/swap-me', 'f');

$needles = ['ph', 'PHOTO', 'o', 'a', 'img', '日本', 'é', '.md', 'zz-none', 'x', '_1', 'a b', 'deep'];
$bounds = [[1, 20000], [3, 20000], [200, 20000], [200, 5], [2, 17], [500, 60]];
$starts = ['/', '/'.basename((string)(glob($treeA.'/*', GLOB_ONLYDIR)[0] ?? ''))];
$equivalent = static function (bool $threaded) use ($fsA, $needles, $bounds, $starts): bool {
    foreach ($starts as $start) {
        foreach ($bounds as [$limit, $max]) {
            $index = null;
            foreach ($needles as $needle) {
                // Threaded, as the cache hands each search the last one's
                // recording: replays, early stops and resumed walks in the mix.
                [$got, $next] = $fsA->searchWithIndex($start, $needle, $limit, $max, $threaded ? $index : null);
                $index = $next;
                if ($got !== $fsA->search($start, $needle, $limit, $max)) return false;
            }
        }
    }
    return true;
};
$checks['a fresh recording answers as search() does'] = $equivalent(false);

// Every stamp here is younger than STAMP_SETTLE_SECONDS. A change in the same
// tick would not move one, so a recording this new proves nothing: it is not
// worth keeping, and it is not replayed if it is handed back anyway.
[$a1, $young, $keep] = $fsA->searchWithIndex('/', 'photo', 200, 20000, null);
$checks['a walk over just-changed folders is not worth keeping'] = $keep === false && $young['settled'] === false;
file_put_contents($treeA.'/rm-dir/same-tick.txt', 's');
[$a2] = $fsA->searchWithIndex('/', 'same-tick', 200, 20000, $young);
$checks['nor is it replayed'] = count($a2['results']) === 1;
unlink($treeA.'/rm-dir/same-tick.txt');

// Let every stamp in the tree settle before the checks that need one.
sleep(FileService::STAMP_SETTLE_SECONDS + 1);
[, $settledIndex, $keep] = $fsA->searchWithIndex('/', 'zz-none', 200, 20000, null);
$checks['a walk over settled folders is'] = $keep === true && $settledIndex['settled'] === true;
$checks['and every replay of one answers as search() does, however it was left'] = $equivalent(true);

[$again, $after, $changed] = $fsA->searchWithIndex('/', 'photo', 200, 20000, $settledIndex);
$checks['a complete recording replays without touching it'] = $after === $settledIndex && $changed === false
    && $again === $fsA->search('/', 'photo', 200, 20000);
[, $partial] = $fsA->searchWithIndex('/', 'o', 1, 20000, null);
[$more, $extended, $changed] = $fsA->searchWithIndex('/', 'zz-none', 200, 20000, $partial);
$checks['a search that stopped early is carried on by the next'] = $changed === true
    && count($partial['dirs']) < count($extended['dirs']) && $more === $fsA->search('/', 'zz-none', 200, 20000);
$checks['nothing the walk may not enter is ever recorded'] = !array_filter($settledIndex['names'],
    static fn(string $n): bool => (bool)preg_match('/(?:^|\0)(?:photo-loop|photo-link|\.trash|\.uploads)(?:\0|$)/', $n));
$elsewhere = ['v' => 1, 'root' => '/elsewhere'] + $settledIndex;
[$r, , $changed] = $fsA->searchWithIndex('/', 'photo', 200, 20000, $elsewhere);
$checks['a recording of another folder is not used'] = $changed === true && $r === $fsA->search('/', 'photo', 200, 20000);
[$r] = $fsA->searchWithIndex('/', 'photo', 200, 20000, ['names' => 'garbage'] + $settledIndex);
$checks['nor is a damaged one'] = $r === $fsA->search('/', 'photo', 200, 20000);

// --- and notices every change to what it walked ------------------------------

// First, the one change it must not notice: a file rewritten in place moves
// no folder's stamp, and the rows are read from the disk anyway.
file_put_contents($treeA.'/rows-dir/readme-grow.md', str_repeat('grown', 100));
clearstatcache();
[$r, , $changed] = $fsA->searchWithIndex('/', 'readme-grow', 200, 20000, $settledIndex);
$checks['result rows come from the disk, never the recording'] = $changed === false
    && ($r['results'][0]['size'] ?? null) === 500;

$deepest = '';
foreach ($settledIndex['dirs'] as $d) if (substr_count($d, '/') > substr_count($deepest, '/')) $deepest = $d;
file_put_contents($deepest.'/needle-added.txt', 'n');
[$r] = $fsA->searchWithIndex('/', 'needle-added', 200, 20000, $settledIndex);
$checks['a file added deep in the tree is found at once'] = count($r['results']) === 1
    && $r === $fsA->search('/', 'needle-added', 200, 20000);

unlink($treeA.'/rm-dir/needle-removed.txt');
[$r] = $fsA->searchWithIndex('/', 'needle-removed', 200, 20000, $settledIndex);
$checks['and one removed is gone at once'] = $r['results'] === [];

$mtime = (int)filemtime($treeA.'/swap-dir');
unlink($treeA.'/swap-dir/swap-me');
mkdir($treeA.'/swap-dir/swap-me');
file_put_contents($treeA.'/swap-dir/swap-me/inside-swap', 'i');
// Put the folder's mtime back as it was: its ctime still moves, which is
// why the stamp carries both.
touch($treeA.'/swap-dir', $mtime);
clearstatcache();
[$r] = $fsA->searchWithIndex('/', 'inside-swap', 200, 20000, $settledIndex);
$checks['a file replaced by a folder, mtime put back, is still walked into'] = count($r['results']) === 1
    && (int)filemtime($treeA.'/swap-dir') === $mtime;

// --- listings -------------------------------------------------------------------

$fsL = new FileService(['root_dir' => $listDir, 'read_only' => false]);
Cache::configure($cfg(['root_dir' => $listDir, 'cache_path' => $tmp.'/list-cache']));
$fc = new FileCache($fsL);

$first = $fc->list('/sub');
$checks['a listing is what FileService lists'] = $first === $fsL->list('/sub');
$checks['and a slow-enough one is kept'] = is_file($tmp.'/list-cache/active');
file_put_contents($listDir.'/sub/f1.txt', 'rewritten in place by another program');
clearstatcache();
$checks['a file rewritten in place elsewhere may lag, by design'] = $fc->list('/sub') === $first;
Cache::bumpGeneration();
$bumped = $fc->list('/sub');
$checks['until anything CloudHub changes retires the generation'] = $bumped === $fsL->list('/sub') && $bumped !== $first;
file_put_contents($listDir.'/sub/added-elsewhere.txt', 'a');
$checks['a file added by anyone shows at once'] =
    in_array('added-elsewhere.txt', array_column($fc->list('/sub'), 'name'), true);
$checks['one folder is never answered with another'] = $fc->list('/') === $fsL->list('/');

Cache::configure($cfg(['root_dir' => $listDir, 'cache_path' => $tmp.'/ttl0', 'cache_ttl_seconds' => 0]));
(new FileCache($fsL))->list('/sub');
$checks['CACHE_TTL_SECONDS=0 keeps no listings'] = !file_exists($tmp.'/ttl0/active');
Cache::configure($cfg(['root_dir' => $listDir, 'cache_path' => $tmp.'/quick', 'cache_min_compute_ms' => 60000]));
$quick = new FileCache($fsL);
$quick->list('/sub');
$quick->search('/', 'f1', 200);
$checks['answers quicker than the threshold are never kept'] = !file_exists($tmp.'/quick/active');

// --- search, through the cache ---------------------------------------------------

$fsC = new FileService(['root_dir' => $treeC, 'read_only' => false]);
Cache::configure($cfg(['root_dir' => $treeC, 'cache_path' => $tmp.'/search-cache']));
$fcC = new FileCache($fsC);
$through = true;
foreach (['ph', 'o', 'img', 'zz-none'] as $needle) {
    $through = $through && $fcC->search('/', $needle, 200) === $fsC->search('/', $needle, 200);
}
$checks['search through the cache answers as search() does'] = $through;
$stored = glob($tmp.'/search-cache/cloudhub/Files/*/*/*.txt') ?: [];
$checks['and keeps its recording'] = count($stored) === 1;
[, $deepC] = $fsC->searchWithIndex('/', 'zz-none', 200, 20000, null);
$deepestC = end($deepC['dirs']);
file_put_contents($deepestC.'/cached-needle.txt', 'n');
$checks['which sees a new file at once, with no TTL involved'] =
    count($fcC->search('/', 'cached-needle', 200)['results']) === 1;

// --- favorites -------------------------------------------------------------------

Cache::configure($cfg(['root_dir' => $listDir, 'cache_path' => $tmp.'/describe-cache']));
$fcD = new FileCache($fsL);
$paths = ['/sub/f2.txt', '/sub/missing.txt', '/.trash/anything', '/other.txt'];
$d = $fcD->describeMany('favorites_7', $paths);
$checks['described rows are keyed by position'] = array_keys($d['rows']) === [0, 3]
    && $d['rows'][0] === $fsL->describe('/sub/f2.txt');
$checks['only a missing path is reported gone'] = $d['gone'] === ['/sub/missing.txt'];
$kept = ['/sub/f2.txt', '/other.txt'];
$first = $fcD->describeMany('favorites_7', $kept);
file_put_contents($listDir.'/sub/f2.txt', 'rewritten');
clearstatcache();
$checks['a set with nothing gone is kept'] = $fcD->describeMany('favorites_7', $kept) === $first;
$checks['nor is another account answered with it'] = $fcD->describeMany('favorites_8', $kept)['rows'][0]['size'] === 9;
Cache::bumpGeneration();
$checks['and a new generation re-reads it'] = $fcD->describeMany('favorites_7', $kept)['rows'][0]['size'] === 9;
$checks['as does another set of paths'] =
    $fcD->describeMany('favorites_7', ['/other.txt'])['rows'] === [0 => $fsL->describe('/other.txt')];

// --- the façade itself -----------------------------------------------------------

Cache::configure($cfg(['cache_path' => $tmp.'/facade']));
$checks['a stored value reads back'] = Cache::set('roundtrip', ['a' => 1], 60) && Cache::get('roundtrip') === ['a' => 1];
Cache::delete('roundtrip');
$checks['and is gone once deleted'] = Cache::get('roundtrip') === null;
$g1 = Cache::generation();
$checks['the generation is a stable token'] = is_string($g1) && preg_match('/^[0-9a-f]{24}$/', $g1) === 1
    && Cache::generation() === $g1;
Cache::bumpGeneration();
$checks['and bumping it replaces it'] = Cache::generation() !== $g1;
try { Cache::get('Not A Key'); $threw = false; } catch (InvalidArgumentException) { $threw = true; }
$checks['a malformed key is a caller bug, and says so'] = $threw;

// A generation that cannot be written means nothing may be cached under it.
Cache::configure($cfg(['root_dir' => $listDir, 'cache_path' => $tmp.'/stuck']));
mkdir($tmp.'/stuck/generation', 0775, true);
$checks['a generation that cannot be kept is none'] = Cache::generation() === null;
Cache::bumpGeneration();
$checks['and failing to retire it is logged'] = $logged('could not retire the cache generation');
$stuck = new FileCache($fsL);
$checks['with none, listings are simply uncached'] = $stuck->list('/sub') === $fsL->list('/sub')
    && !file_exists($tmp.'/stuck/active');

// Garbage: phpFastCache only deletes an expired file when something reads it.
Cache::configure($cfg(['cache_path' => $tmp.'/gc']));
Cache::set('warm', 1, 60);
$bucket = (string)(glob($tmp.'/gc/cloudhub/Files/*/*', GLOB_ONLYDIR)[0] ?? '');
file_put_contents($bucket.'/stale.txt', 'old');
touch($bucket.'/stale.txt', time() - Cache::MAX_TTL - 120);
file_put_contents($bucket.'/tmp_interrupted.txt1234', 'half');
touch($bucket.'/tmp_interrupted.txt1234', time() - Cache::MAX_TTL - 120);
@unlink($tmp.'/gc/gc');
Cache::set('trigger', 1, 60);
$checks['entries nobody read back are swept'] = $bucket !== '' && !file_exists($bucket.'/stale.txt')
    && !file_exists($bucket.'/tmp_interrupted.txt1234');
$checks['and live ones are left alone'] = Cache::get('warm') === 1 && Cache::get('trigger') === 1;

// A damaged entry: phpFastCache cannot unserialize it and says so.
Cache::configure($cfg(['cache_path' => $tmp.'/damaged']));
Cache::set('fragile', str_repeat('v', 100), 60);
$entry = (string)(glob($tmp.'/damaged/cloudhub/Files/*/*/*.txt')[0] ?? '');
file_put_contents($entry, 'a:2:{s:1:"x";');
$diagnostics = [];
set_error_handler(static function (int $no, string $msg) use (&$diagnostics): bool { $diagnostics[] = $msg; return true; });
try { $read = Cache::get('fragile'); $escaped = false; } catch (Throwable) { $read = 'threw'; $escaped = true; }
restore_error_handler();
$checks['a damaged entry reads as a miss'] = $read === null && !$escaped;
$checks['where phpFastCache keeps it is where the cache looks'] = $entry !== ''
    && str_ends_with($entry, (static function (string $key): string {
        $hash = md5($key);
        return '/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash.'.txt';
    })('ch'.substr(sha1($treeA), 0, 10).'_fragile'));
$checks['and is removed, so the next read is clean'] = $entry !== '' && !file_exists($entry);

// A backend that cannot start: its directory is taken by a file.
Cache::configure($cfg(['cache_path' => $tmp.'/blocked']));
mkdir($tmp.'/blocked', 0775, true);
file_put_contents($tmp.'/blocked/cloudhub', 'not a directory');
$stored = $read = 'not reached';
try {
    $stored = Cache::set('anything', 1, 60);
    $read = Cache::get('anything');
    $escaped = false;
} catch (Throwable) { $escaped = true; }
$checks['a backend that cannot start fails nothing'] = !$escaped && $stored === false && $read === null;
$checks['and turns the cache off for the request, saying why'] = !Cache::enabled()
    && Cache::status()['reason'] === 'start failed' && $logged('[cache] start failed');

// No vendor/ at all: a copy of the class with nothing beside it.
$bare = $tmp.'/bare';
mkdir($bare.'/src/Helpers', 0775, true);
copy($root.'/src/Helpers/Cache.php', $bare.'/src/Helpers/Cache.php');
$script = $bare.'/probe.php';
file_put_contents($script, '<?php declare(strict_types=1); ini_set("error_log", '.var_export($bare.'/log', true).');'
    .'require '.var_export($bare.'/src/Helpers/Cache.php', true).'; use CloudHub\Helpers\Cache;'
    .'Cache::configure(["root_dir" => '.var_export($treeA, true).', "cache_path" => '.var_export($bare.'/c', true).', "cache_min_compute_ms" => 0]);'
    .'echo json_encode([Cache::set("k", 1, 60), Cache::get("k"), Cache::status()["reason"], Cache::generation() !== null]);');
$out = shell_exec(escapeshellarg(PHP_BINARY).' -d display_errors=1 '.escapeshellarg($script).' 2>&1');
$checks['without vendor/ the cache is a quiet no-op'] =
    trim((string)$out) === '[false,null,"phpFastCache is not installed (vendor\/ is missing)",true]';

// --- where it may not live ---------------------------------------------------------

$refused = static function (array $over): ?string {
    Cache::configure($over);
    return Cache::enabled() ? null : Cache::status()['reason'];
};
$checks['a cache inside ROOT_DIR is refused'] =
    str_contains((string)$refused($cfg(['cache_path' => $treeA.'/.cache'])), 'inside ROOT_DIR');
mkdir($tmp.'/lnk', 0775, true);
symlink($treeA, $tmp.'/lnk/into-root');
$checks['however it is spelled'] =
    str_contains((string)$refused($cfg(['cache_path' => $tmp.'/lnk/into-root/c'])), 'inside ROOT_DIR')
    && str_contains((string)$refused($cfg(['cache_path' => strtoupper($treeA).'/c'])), 'inside ROOT_DIR');
$checks['as is one the web server serves'] =
    str_contains((string)$refused($cfg(['cache_path' => $root.'/public/cache'])), 'inside public/');
$checks['a driver nobody has heard of is refused'] =
    str_contains((string)$refused($cfg(['cache_driver' => 'memcachd'])), 'unknown CACHE_DRIVER');
$checks['and none turns it off'] = $refused($cfg(['cache_driver' => 'none'])) === 'disabled by CACHE_DRIVER'
    && Cache::generation() === null;
Cache::configure($cfg(['cache_path' => $tmp.'/healthy']));
$checks['a healthy cache proves itself to the diagnostics'] = Cache::status(true)['working'] === true;

// --- phpFastCache is configured for a directory attackers may aim at --------------

$cacheSource = (string)file_get_contents($root.'/src/Helpers/Cache.php');
$checks['the directory is not named after the Host header'] = str_contains($cacheSource, "'securityKey' => 'cloudhub',");
$checks['it never falls back to the shared temp directory'] = str_contains($cacheSource, "'autoTmpFallback' => false,");
$checks['entries are written whole or not at all'] = str_contains($cacheSource, "'secureFileManipulation' => true,");
$checks['a dead Redis costs a request one second, not five'] = str_contains($cacheSource, "'timeout' => 1,");

// --- the routes --------------------------------------------------------------------

$index = (string)file_get_contents($root.'/public/index.php');
$checks['listings go through the cache'] = str_contains($index, "file_cache()->list((string)(\$_GET['path']??'/'))");
$checks['as does search'] = str_contains($index, "file_cache()->search((string)(\$_GET['path']??'/'), \$q, \$limit);");
$checks['the cache is configured on first use only'] = substr_count($index, 'Cache::configure(') === 1
    && str_contains($index, 'if (!$ready) { Cache::configure($config); $ready = true; }');
$hook = strpos($index, '$cacheNeutralWrites = [');
$checks['every change to the store retires the generation, after the guard'] = $hook !== false
    && $hook > strpos($index, 'Authorization::requireWrite();')
    && str_contains($index, "if (!in_array(\$method, ['GET', 'HEAD', 'OPTIONS', 'PROPFIND'], true) && !in_array(\$path, \$cacheNeutralWrites, true)) {\n    register_shutdown_function(static function (): void {\n        cache_ready();\n        Cache::bumpGeneration();");
preg_match('/\$cacheNeutralWrites = \[(.*?)\];/s', $index, $m);
$neutral = array_map(static fn(string $s): string => trim($s, " \n'"), explode(',', $m[1] ?? ''));
$checks['and only routes that cannot change a file are exempt'] = $neutral === ['/api/auth/login', '/api/auth/logout',
    '/api/uploads/init', '/api/uploads/chunk', '/api/uploads/cancel', '/api/uploads/cleanup', '/api/thumbnail/video',
    '/api/files/download-zip', '/api/users/me/password', '/api/duplicates/scan'];
$checks['finishing an upload is not among them'] = !in_array('/api/uploads/complete', $neutral, true);

// --- the bundled dependency ----------------------------------------------------------

$composer = json_decode((string)file_get_contents($root.'/composer.json'), true);
$lock = json_decode((string)file_get_contents($root.'/composer.lock'), true);
$installed = json_decode((string)file_get_contents($root.'/vendor/composer/installed.json'), true);
$versions = static fn(array $packages): array => array_column(array_map(
    static fn(array $p): array => [$p['name'], $p['version'].'@'.$p['source']['reference']], $packages), 1, 0);
$checks['phpFastCache 9.2 is what composer.json asks for'] =
    ($composer['require']['phpfastcache/phpfastcache'] ?? null) === '^9.2';
$checks['and what vendor/ holds is exactly what the lock file pins'] =
    $versions($lock['packages'] ?? []) === $versions($installed['packages'] ?? [])
    && ($versions($lock['packages'] ?? [])['phpfastcache/phpfastcache'] ?? null) === '9.2.4@7c24491baf23ffb637b18e485f517144cd9af203';
$checks['vendor/ carries only what runs, and the licences'] =
    !is_dir($root.'/vendor/phpfastcache/phpfastcache/tests') && !is_dir($root.'/vendor/phpfastcache/phpfastcache/.git')
    && is_file($root.'/vendor/phpfastcache/phpfastcache/LICENCE') && is_file($root.'/vendor/psr/cache/LICENSE.txt')
    && is_file($root.'/vendor/psr/simple-cache/LICENSE.md');

$rmrf($tmp);

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
