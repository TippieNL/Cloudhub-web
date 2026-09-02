<?php
declare(strict_types=1);

/**
 * Byte-identical duplicate detection over photos and videos.
 *
 * Run against a real fixture tree, because every interesting case here is
 * about what is actually on disk: files that share a size but not their
 * contents, files that share their edges but not their middle, and files that
 * change between one slice of a resumable scan and the next.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
require dirname(__DIR__).'/src/Services/DuplicateFinder.php';

use CloudHub\Services\FileService;
use CloudHub\Services\DuplicateFinder;

function rmrf29(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf29($p.'/'.$n); @rmdir($p); }
}

$root = dirname(__DIR__);
$checks = [];

// The finder writes its state beside the other caches, so each run gets its
// own project directory rather than trampling the real one.
$project = sys_get_temp_dir().'/cloudhub-p29-'.bin2hex(random_bytes(5));
mkdir($project.'/storage/.cache', 0775, true);
mkdir($project.'/src/Services', 0775, true);
copy($root.'/src/Services/FileService.php', $project.'/src/Services/FileService.php');
copy($root.'/src/Services/DuplicateFinder.php', $project.'/src/Services/DuplicateFinder.php');

$store = $project.'/store';
mkdir($store.'/Photos/2024', 0775, true);
mkdir($store.'/Backup', 0775, true);
mkdir($store.'/Docs', 0775, true);

/** Run a scan to completion in a subprocess, so cacheDir resolves inside $project. */
$runScan = static function(string $project, string $store, string $path, array $overrides = [], int $budget = 30): array {
    $driver = $project.'/driver.php';
    file_put_contents($driver, "<?php\n".
        "require __DIR__.'/src/Services/FileService.php';\n".
        "require __DIR__.'/src/Services/DuplicateFinder.php';\n".
        'use CloudHub\Services\FileService; use CloudHub\Services\DuplicateFinder;'."\n".
        '$cfg = json_decode($argv[2], true);'."\n".
        '$fs = new FileService(["root_dir" => $argv[1], "read_only" => false]);'."\n".
        '$d = new DuplicateFinder($cfg, $fs);'."\n".
        '$p = $d->begin($argv[3]);'."\n".
        '$slices = 1;'."\n".
        'while (!$p["done"] && $slices < 500) { $p = $d->advance(); $slices++; }'."\n".
        '$p["slices"] = $slices;'."\n".
        'echo json_encode($p);'."\n");

    $config = array_merge([
        'duplicate_min_bytes' => 1024,
        'duplicate_scan_seconds' => $budget,
        'duplicate_max_files' => 50000,
    ], $overrides);

    $out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($driver).' '
        .escapeshellarg($store).' '.escapeshellarg(json_encode($config)).' '.escapeshellarg($path).' 2>&1');
    return json_decode((string)$out, true) ?: ['_raw' => $out];
};

/** Group signature as a sorted list of paths, for order-independent comparison. */
$signature = static function(array $result): array {
    $sig = [];
    foreach ($result['groups'] ?? [] as $g) {
        $paths = array_map(static fn(array $f): string => $f['path'], $g['files']);
        sort($paths);
        $sig[] = implode(' + ', $paths);
    }
    sort($sig);
    return $sig;
};

// --- fixtures -------------------------------------------------------------

$photo = random_bytes(40000);
file_put_contents($store.'/Photos/holiday.jpg', $photo);
file_put_contents($store.'/Backup/holiday.jpg', $photo);          // a real duplicate
file_put_contents($store.'/Photos/2024/holiday-copy.jpg', $photo); // and a third copy

// Same size, different content: must not be grouped.
file_put_contents($store.'/Photos/beach.jpg', random_bytes(40000));

// Same size, identical first and last 64 KB, different middle. This is the
// case the partial-hash stage exists to separate, and the one an
// implementation that stops at the edges reports as a false duplicate.
$edge = random_bytes(65536);
$mid = 40000;
file_put_contents($store.'/Photos/edges-a.jpg', $edge.str_repeat('A', $mid).$edge);
file_put_contents($store.'/Photos/edges-b.jpg', $edge.str_repeat('B', $mid).$edge);

// Same size, same edges, same middle: a genuine duplicate that only a full
// read can confirm.
$whole = $edge.str_repeat('C', $mid).$edge;
file_put_contents($store.'/Photos/big-a.mp4', $whole);
file_put_contents($store.'/Backup/big-b.mp4', $whole);

// A single copy of something is not a duplicate.
file_put_contents($store.'/Photos/unique.png', random_bytes(30000));

// Below the minimum size: every tiny file would otherwise group with every
// other tiny file of the same length.
file_put_contents($store.'/Photos/tiny-a.jpg', str_repeat('t', 64));
file_put_contents($store.'/Photos/tiny-b.jpg', str_repeat('t', 64));

// Not media: duplicated, but out of scope.
$doc = random_bytes(20000);
file_put_contents($store.'/Docs/notes.pdf', $doc);
file_put_contents($store.'/Docs/notes-copy.pdf', $doc);

// A symlink to a real file must not be reported as a copy of it.
symlink($store.'/Photos/unique.png', $store.'/Photos/unique-link.png');

// Reserved directories are never walked.
mkdir($store.'/.trash/20240101-000000-abcdef12/payload', 0775, true);
file_put_contents($store.'/.trash/20240101-000000-abcdef12/payload/holiday.jpg', $photo);
mkdir($store.'/.thumbnails/images', 0775, true);
file_put_contents($store.'/.thumbnails/images/x.webp', $photo);

// Age every fixture by a minute. A cached digest is only trusted when the file
// is strictly older than the moment it was hashed, so files written in the same
// second as the scan are re-hashed by design -- see the same-second check
// further down. Real photos are not written in the second they are scanned.
$aged = time() - 60;
$ageAll = static function(string $dir) use (&$ageAll, $aged): void {
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..') continue;
        $full = $dir.'/'.$n;
        if (is_link($full)) continue;
        if (is_dir($full)) { $ageAll($full); continue; }
        @touch($full, $aged);
    }
};
$ageAll($store);

// --- the scan -------------------------------------------------------------

$r = $runScan($project, $store, '/');
$checks['the scan completed'] = ($r['done'] ?? false) === true;
if (!($r['done'] ?? false)) echo '       raw: '.substr(json_encode($r), 0, 400).PHP_EOL;

$sig = $signature($r);
$checks['the three copies of one photo are one group'] =
    in_array('/Backup/holiday.jpg + /Photos/2024/holiday-copy.jpg + /Photos/holiday.jpg', $sig, true);
$checks['a full-read duplicate is confirmed'] =
    in_array('/Backup/big-b.mp4 + /Photos/big-a.mp4', $sig, true);
$checks['exactly those two groups are reported'] = count($sig) === 2;

$flat = implode(' ', $sig);
$checks['same size but different content is not a duplicate'] = !str_contains($flat, 'beach.jpg');
// The one an edges-only implementation gets wrong.
$checks['same edges but different middle is not a duplicate'] = !str_contains($flat, 'edges-');
$checks['a single copy is not a duplicate'] = !str_contains($flat, 'unique.png');
$checks['files below the minimum size are skipped'] = !str_contains($flat, 'tiny-');
$checks['non-media files are ignored'] = !str_contains($flat, 'notes');
$checks['a symlink is not a copy of its target'] = !str_contains($flat, 'unique-link');
$checks['the trash is never walked'] = !str_contains($flat, '.trash');
$checks['the thumbnail cache is never walked'] = !str_contains($flat, '.thumbnails');

// Reclaimable is what deleting the extra copies frees: three copies of a
// 40000-byte photo free 80000, not 120000.
$holiday = null; $big = null;
foreach ($r['groups'] as $g) {
    $names = implode(',', array_map(static fn(array $f): string => $f['path'], $g['files']));
    if (str_contains($names, 'holiday')) $holiday = $g;
    if (str_contains($names, 'big-')) $big = $g;
}
$checks['reclaimable counts copies beyond the first'] =
    $holiday !== null && $holiday['reclaimable'] === 40000 * 2;
$checks['and the group reports its own size'] = $holiday !== null && $holiday['bytes'] === 40000;
$checks['the total is the sum over groups'] =
    $big !== null && ($r['reclaimable'] ?? -1) === (40000 * 2) + strlen($whole);
$checks['duplicate file count excludes the copy being kept'] = ($r['duplicateFiles'] ?? -1) === 3;
$checks['largest reclaim is listed first'] =
    ($r['groups'][0]['reclaimable'] ?? 0) >= ($r['groups'][1]['reclaimable'] ?? 0);

// --- scoping to a folder --------------------------------------------------

$scoped = $runScan($project, $store, '/Photos');
$scopedFlat = implode(' ', $signature($scoped));
$checks['a scoped scan stays inside its folder'] =
    str_contains($scopedFlat, '/Photos/holiday.jpg') && !str_contains($scopedFlat, '/Backup/');

// --- resuming across slices ----------------------------------------------

// A budget of zero seconds is clamped to one, and the deadline is checked
// between groups, so this forces the work across many slices.
rmrf29($project.'/storage/.cache');
mkdir($project.'/storage/.cache', 0775, true);
$sliced = $runScan($project, $store, '/', ['duplicate_scan_seconds' => 1], 1);
$checks['a resumed scan finishes'] = ($sliced['done'] ?? false) === true;
$checks['and reaches exactly the same groups'] = $signature($sliced) === $sig;

// --- the hash cache -------------------------------------------------------

$cached = $runScan($project, $store, '/');
$checks['a re-scan computes no digests'] = ($cached['computed'] ?? -1) === 0;
$checks['but still walks the same candidates'] = ($cached['hashed'] ?? 0) > 0;
$checks['and still reports the same groups'] = $signature($cached) === $sig;

// A changed mtime must not keep the old digest.
touch($store.'/Backup/holiday.jpg', $aged + 5);
$afterTouch = $runScan($project, $store, '/');
$checks['a changed mtime recomputes the digest'] = ($afterTouch['computed'] ?? 0) > 0;
$checks['and the groups are unchanged when the bytes are'] = $signature($afterTouch) === $sig;

// Content changing under a reused path must change the answer.
file_put_contents($store.'/Backup/holiday.jpg', random_bytes(40000));
touch($store.'/Backup/holiday.jpg', $aged + 10);
$afterEdit = $runScan($project, $store, '/');
$editFlat = implode(' ', $signature($afterEdit));
$checks['an edited copy leaves the group'] =
    str_contains($editFlat, '/Photos/2024/holiday-copy.jpg + /Photos/holiday.jpg')
    && !str_contains($editFlat, '/Backup/holiday.jpg');

/*
 * The racy-clean rule itself.
 *
 * mtime has one-second granularity, so a file rewritten in the same second in
 * which it was hashed still matches its cache key. Trusting that entry would
 * report two files as identical when they are not -- the one way this feature
 * could talk somebody into deleting a file that is not a duplicate. A digest
 * is therefore only reused when the file is strictly older than the moment it
 * was taken.
 */
$racy = $store.'/Photos/racy.jpg';
$twin = $store.'/Backup/racy.jpg';
$shared = random_bytes(50000);
file_put_contents($racy, $shared);
file_put_contents($twin, $shared);
$now = time();
touch($racy, $now); touch($twin, $now);
$racyFirst = $runScan($project, $store, '/');
$checks['a same-second pair is still detected'] =
    str_contains(implode(' ', $signature($racyFirst)), '/Backup/racy.jpg + /Photos/racy.jpg');

// The racy state is constructed rather than raced for: whether the scan
// subprocess lands in the same second as the write is luck, and a check that
// depends on luck is not a check. Every cached digest is stamped back to its
// file's own mtime second, which is exactly "hashed in the second this file
// was last written".
$hashFile = $project.'/storage/.cache/duplicate-hashes.json';
$entries = json_decode((string)file_get_contents($hashFile), true) ?: [];
$racyKeys = 0;
foreach ($entries as $key => $entry) {
    if (!str_contains($key, 'racy.jpg')) continue;
    $entries[$key]['w'] = $now;   // taken in the same second as mtime
    $racyKeys++;
}
file_put_contents($hashFile, json_encode($entries));
$checks['the racy fixture is in the hash cache'] = $racyKeys > 0;

// Now rewrite one of them, same size, same mtime second.
file_put_contents($racy, random_bytes(50000));
touch($racy, $now);
$racySecond = $runScan($project, $store, '/');
$checks['a digest taken in the file\'s own mtime second is not reused'] =
    !str_contains(implode(' ', $signature($racySecond)), '/Backup/racy.jpg + /Photos/racy.jpg');

@unlink($racy); @unlink($twin);

// --- the walk cap is reported, not hidden --------------------------------

$capped = $runScan($project, $store, '/', ['duplicate_max_files' => 3]);
$checks['hitting the file cap is reported'] = ($capped['truncated'] ?? false) === true;
$checks['an uncapped scan is not marked truncated'] = ($r['truncated'] ?? true) === false;

// --- deletion is not re-implemented here ---------------------------------

$src = (string)file_get_contents($root.'/src/Services/DuplicateFinder.php');
foreach (['trash(', 'deleteTree(', 'copy(', 'file_put_contents($full'] as $forbidden) {
    $checks['the finder never calls '.$forbidden.')'] = !str_contains($src, $forbidden);
}
// It does rename, but only its own cache file into place -- the atomic-write
// pattern the thumbnail and usage caches already use.
$checks['the only rename is the atomic cache write'] =
    substr_count($src, 'rename(') === 1 && str_contains($src, '!@rename($tmp, $file)');
// It writes only its own two cache files, both outside the storage root.
$checks['it only unlinks its own state'] =
    substr_count($src, '@unlink(') === 2
    && str_contains($src, "@unlink(\$this->cacheDir.'/duplicates.json')");
$checks['its caches live outside the storage root'] =
    str_contains($src, "dirname(__DIR__, 2).'/storage/.cache'");

// --- the page is actually wired up ---------------------------------------

// No database here, so an authenticated end-to-end run is not possible; what
// is checkable is that every id the script reaches for exists in the markup,
// which is where a wiring mistake would actually be.
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$markup = (string)file_get_contents($root.'/views/pages/app.php');
$index = (string)file_get_contents($root.'/public/index.php');
$css = (string)file_get_contents($root.'/public/assets/css/app.css');

preg_match_all("/\\\$\('#(dupe-[a-z-]+)'\)/", $app, $ids);
$missing = [];
foreach (array_unique($ids[1]) as $id) {
    if (!str_contains($markup, 'id="'.$id.'"')) $missing[] = $id;
}
$checks['every element the script uses exists in the markup'] = $missing === [];
if ($missing !== []) echo '       missing from app.php: '.implode(', ', $missing).PHP_EOL;
$checks['the script does reach for those elements'] = count(array_unique($ids[1])) >= 6;

$checks['the page route is served'] = str_contains($index, "'/trash', '/storage', '/duplicates'], true)");
$checks['the nav offers it'] = str_contains($markup, 'data-route="/duplicates"');
$checks['the section exists'] = str_contains($markup, 'id="duplicates-page"');
$checks['routing hides it with the others'] =
    str_contains($app, "'trash', 'storage', 'duplicates'].forEach");
$checks['routing shows it'] = str_contains($app, "} else if (p === '/duplicates') {");
$checks['opening the tab does not start a scan'] =
    str_contains($app, "await api('/api/duplicates/scan')")
    && !str_contains($app, "await api('/api/duplicates/scan', { method: 'POST', body: { path, restart } })).json();\n            if (d.done)");

// Deletion goes through the one tested route, and the danger button matches
// the class the stylesheet actually defines.
$checks['deleting uses the ordinary delete route'] =
    str_contains($app, "await api('/api/files/delete', { method: 'DELETE', body: { path } });");
$checks['no bulk delete endpoint was invented'] = !str_contains($index, '/api/duplicates/delete');
$checks['the delete button uses the defined danger class'] =
    str_contains($markup, 'id="dupe-delete" type="button" class="danger-button"')
    && str_contains($css, '.danger-button{');
$checks['a group cannot be emptied from this page'] =
    str_contains($app, 'g.files.every(f => dupeState.selected.has(f.path))');
$checks['the styles are defined'] = str_contains($css, '.dupe-group{') && str_contains($css, '.dupe-thumb{');

// The scan route is read-only and must not hold the session lock while the
// browser polls it.
// Scoped to the route body rather than pinned to the lines next to it: a
// comment between them is not a behaviour change, and an assertion that breaks
// on one is measuring the wrong thing.
$scanRoute = (static function(string $index): string {
    $at = strpos($index, "if (\$path === '/api/duplicates/scan' && \$method === 'POST')");
    if ($at === false) return '';
    return substr($index, $at, (int)strpos($index, "\n});", $at) - $at);
})($index);
$checks['the scan route releases the session lock'] =
    $scanRoute !== '' && str_contains($scanRoute, 'release_session_lock();');
$checks['and polling it does not need write on the read path'] =
    str_contains($index, "// The operative check for reading a finished scan");

// --- the response contract -----------------------------------------------

/*
 * A second client codes against these names now, so a rename is a breaking
 * change rather than a refactor and has to fail here rather than in somebody
 * else's app. Adding a field is free; renaming or removing one is not.
 */
$expectedTop = ['path', 'done', 'scanned', 'candidates', 'hashed', 'computed', 'toHash',
    'truncated', 'groups', 'duplicateFiles', 'reclaimable', 'startedAt', 'finishedAt'];
$actualTop = array_keys($r);
sort($actualTop);
$wantTop = $expectedTop; sort($wantTop);
// 'slices' is added by this file's own driver, not by the API.
$actualTop = array_values(array_diff($actualTop, ['slices']));
$checks['the scan response carries exactly the documented fields'] = $actualTop === $wantTop;
if ($actualTop !== $wantTop) {
    echo '       extra: '.implode(', ', array_diff($actualTop, $wantTop)).PHP_EOL;
    echo '       missing: '.implode(', ', array_diff($wantTop, $actualTop)).PHP_EOL;
}

$groupKeys = array_keys($r['groups'][0]);
sort($groupKeys);
$checks['a group carries exactly the documented fields'] = $groupKeys === ['bytes', 'count', 'files', 'reclaimable'];
$fileKeys = array_keys($r['groups'][0]['files'][0]);
sort($fileKeys);
$checks['a file in a group carries exactly the documented fields'] = $fileKeys === ['bytes', 'mtime', 'path'];

// The README documents that shape; it must not drift from what is returned.
$readme = (string)file_get_contents($root.'/README.md');
$start = strpos($readme, '## Duplicate finder');
$checks['the README documents the endpoint'] = $start !== false;
if ($start !== false) {
    $section = substr($readme, $start, (int)strpos($readme, '## Resumable large-file uploads') - $start);
    $undocumented = [];
    foreach ($expectedTop as $field) {
        if (!str_contains($section, '"'.$field.'"')) $undocumented[] = $field;
    }
    $checks['every response field appears in the documented example'] = $undocumented === [];
    if ($undocumented !== []) echo '       undocumented: '.implode(', ', $undocumented).PHP_EOL;

    foreach (['POST /api/duplicates/scan', 'GET /api/duplicates/scan', 'DELETE /api/duplicates/scan',
              'DELETE /api/files/delete', 'X-CSRF-Token', 'editor account'] as $needed) {
        $checks['the README covers '.$needed] = str_contains($section, $needed);
    }
}

// The limits a second client would otherwise hardcode.
$checks['the config route publishes the duplicate limits'] =
    str_contains($index, "'duplicateMinBytes' => \$config['duplicate_min_bytes']")
    && str_contains($index, "'duplicateScanSeconds' => \$config['duplicate_scan_seconds']")
    && str_contains($index, "'duplicateMaxFiles' => \$config['duplicate_max_files']");

// Editor-only scanning is a decision, and the guard says so.
$checks['the guard records why scanning is not write-exempt'] =
    str_contains($index, 'POST /api/duplicates/scan is deliberately NOT on this list')
    && !str_contains($index, "\$writeExemptPost = ['/api/files/download-zip', '/api/thumbnail/video', '/api/users/me/password', '/api/duplicates/scan']");

rmrf29($project);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
