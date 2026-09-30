<?php
declare(strict_types=1);

/**
 * A file name that is not UTF-8 no longer breaks the folder it is in.
 *
 * Linux file names are bytes, and one made on a Latin-1 system -- an old
 * archive unpacked, a Samba share, a WebDAV client -- has no JSON form.
 * Reproduced before the fix, with "caf\xE9.txt" in a folder:
 *
 *   - /api/files/list for that folder answered 500, every file in it with it;
 *   - deleting it wrote an empty meta.json and reported "Moved to trash", and
 *     the item was then unlisted, unrestorable and never purged;
 *   - a duplicate scan could never save its state, so it began again on
 *     every poll and never finished.
 *
 * CloudHub also stops creating such names, since it can list them only as a
 * lookalike it cannot address.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
use CloudHub\Services\FileService;

function rmrf49(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf49($p.'/'.$n); @rmdir($p); }
}

$root = dirname(__DIR__);
$checks = [];
$latin1 = "caf\xE9.txt";

// A project of its own: DuplicateFinder keeps its state beside the project's caches.
$project = sys_get_temp_dir().'/cloudhub-p49-'.bin2hex(random_bytes(5));
mkdir($project.'/storage/.cache', 0775, true);
mkdir($project.'/src/Services', 0775, true);
mkdir($project.'/src/Helpers', 0775, true);
foreach (['Services/FileService.php', 'Services/DuplicateFinder.php', 'Helpers/Http.php'] as $f) copy($root.'/src/'.$f, $project.'/src/'.$f);
$store = $project.'/store';
mkdir($store.'/docs', 0775, true);
file_put_contents($store.'/docs/'.$latin1, 'x');
file_put_contents($store.'/docs/ok.txt', 'y');

/** Run PHP in a subprocess, where exit() and the project's cache directory are its own. */
$run = static function(string $code) use ($project, $store): string {
    $script = $project.'/run-'.bin2hex(random_bytes(3)).'.php';
    file_put_contents($script, "<?php\ndeclare(strict_types=1);\n\$project = ".var_export($project, true).";\n\$store = ".var_export($store, true).";\n".$code);
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1');
    @unlink($script);
    return $out;
};

// --- the listing ---------------------------------------------------------------
$listing = json_decode($run(<<<'PHP'
require $project.'/src/Services/FileService.php';
require $project.'/src/Helpers/Http.php';
$fs = new CloudHub\Services\FileService(['root_dir' => $store, 'read_only' => false]);
CloudHub\Helpers\Http::json($fs->list('/docs'));
PHP), true);
$checks['a folder holding a non-UTF-8 name still lists'] = is_array($listing) && count($listing) === 2;
$checks['the name arrives with a replacement character, not as an error'] =
    is_array($listing) && in_array("caf\u{FFFD}.txt", array_column($listing, 'name'), true);

// --- the trash ---------------------------------------------------------------------
$fs = new FileService(['root_dir' => $store, 'read_only' => false]);
$code = null;
try { $fs->trash($fs->existing('/docs/'.$latin1), 'tester'); } catch (RuntimeException $e) { $code = $e->getCode(); }
$checks['deleting it is refused with a message, not a 500'] = $code === 422;
$checks['and the file is left exactly where it was'] = is_file($store.'/docs/'.$latin1);
$checks['with nothing orphaned in the trash'] = !is_dir($store.'/.trash') || array_diff(scandir($store.'/.trash') ?: [], ['.', '..']) === [];
$fs->trash($fs->existing('/docs/ok.txt'), 'tester');
$checks['an ordinary delete still reaches the trash'] = count($fs->trashList()) === 1;

// --- CloudHub does not create such names -------------------------------------------------
$refused = static function(callable $fn): ?int { try { $fn(); return null; } catch (RuntimeException $e) { return $e->getCode(); } };
$checks['an upload name that is not UTF-8 is refused'] = $refused(fn() => $fs->safeName("r\xE9sum\xE9.pdf")) === 400;
$checks['as is a new folder, rename or WebDAV target'] = $refused(fn() => $fs->destination("/docs/r\xE9sum\xE9.pdf")) === 400;
$checks['UTF-8 names are untouched'] = $fs->safeName('résumé.pdf') === 'résumé.pdf'
    && $fs->destination('/docs/résumé.pdf') === $fs->existing('/docs').'/résumé.pdf';
$checks['an existing one stays addressable, for WebDAV'] = $fs->existing('/docs/'.$latin1) === realpath($store.'/docs/'.$latin1);

// --- the duplicate scan finishes ------------------------------------------------------
mkdir($store.'/pics', 0775, true);
$jpeg = str_repeat("\xFF", 4096);
file_put_contents($store."/pics/a\xE9.jpg", $jpeg);
file_put_contents($store.'/pics/b.jpg', $jpeg);
file_put_contents($store.'/pics/c.jpg', $jpeg);
$scan = json_decode($run(<<<'PHP'
require $project.'/src/Services/FileService.php';
require $project.'/src/Services/DuplicateFinder.php';
$config = ['root_dir' => $store, 'read_only' => false, 'duplicate_min_bytes' => 1024, 'duplicate_scan_seconds' => 5, 'duplicate_max_files' => 1000];
$finder = new CloudHub\Services\DuplicateFinder($config, new CloudHub\Services\FileService($config));
$polls = 0;
$d = $finder->scan('/pics', true);
while (!$d['done'] && ++$polls < 10) $d = $finder->scan('/pics');
echo json_encode(['done' => $d['done'], 'polls' => $polls, 'files' => array_column($d['groups'][0]['files'] ?? [], 'path')]);
PHP), true);
$checks['a scan that meets such a name still finishes'] = is_array($scan) && $scan['done'] === true && $scan['polls'] < 10;
$checks['and still reports the duplicates it can reach'] = is_array($scan) && $scan['files'] === ['/pics/b.jpg', '/pics/c.jpg'];

rmrf49($project);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
