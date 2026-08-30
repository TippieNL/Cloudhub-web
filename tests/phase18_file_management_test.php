<?php
declare(strict_types=1);

/**
 * Move, copy, recursive search and the trash.
 *
 * Three gaps a user hits within minutes of real use:
 *   - the only way to move a file was to rename it in place, because the
 *     client pinned the parent folder even though the server accepted any
 *     destination;
 *   - the search box filtered the folder already on screen, so a file one
 *     folder over looked like it did not exist;
 *   - delete unlinked recursively, with no undo anywhere in the application.
 *
 * The filesystem half runs for real against a temporary root. The route and
 * UI wiring is asserted against source, as in the previous rounds.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
use CloudHub\Services\FileService;

$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$view = (string)file_get_contents($root.'/views/pages/app.php');
$conf = (string)file_get_contents($root.'/config/config.php');
$env = (string)file_get_contents($root.'/.env.example');

function rmrf(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf($p.'/'.$n); @rmdir($p); }
}

$base = sys_get_temp_dir().'/cloudhub-p18-'.bin2hex(random_bytes(5));
mkdir($base.'/Photos/2024', 0775, true);
mkdir($base.'/Docs', 0775, true);
file_put_contents($base.'/Photos/alpha.txt', 'alpha');
file_put_contents($base.'/Photos/2024/beta-report.txt', 'beta');
file_put_contents($base.'/root-note.txt', 'root');
$fs = new FileService(['root_dir' => $base, 'read_only' => false]);

$checks = [];

// --- reserved names -----------------------------------------------------
// Without this the trash could be browsed, searched and hard-deleted through
// the ordinary file routes, which defeats the point of having one.
$checks['the trash cannot be addressed as a path'] = (function () use ($fs): bool {
    try { $fs->sanitize('/.trash'); return false; } catch (RuntimeException) { return true; }
})();
$checks['a path below the trash is refused too'] = (function () use ($fs): bool {
    try { $fs->sanitize('/.trash/20260101-000000-deadbeef/payload/x'); return false; } catch (RuntimeException) { return true; }
})();
$checks['a user folder of the same name deeper down is still usable'] =
    str_ends_with($fs->sanitize('/Photos/.trash'), '/Photos/.trash');

// --- search -------------------------------------------------------------
$found = $fs->search('/', 'beta');
$checks['search reaches into subfolders'] =
    count($found['results']) === 1 && $found['results'][0]['path'] === '/Photos/2024/beta-report.txt';
$checks['search matches folders as well as files'] = (function () use ($fs): bool {
    $r = $fs->search('/', 'photos');
    return count($r['results']) === 1 && $r['results'][0]['isDirectory'] === true;
})();
$checks['search is case-insensitive'] = count($fs->search('/', 'BETA')['results']) === 1;
$checks['search can start below the root'] = $fs->search('/Docs', 'beta')['results'] === [];
$checks['a result cap is reported, not hidden'] = (function () use ($fs): bool {
    $r = $fs->search('/', 't', 1);
    return count($r['results']) === 1 && $r['truncated'] === true;
})();
$checks['the node budget is reported too'] = (function () use ($fs): bool {
    $r = $fs->search('/', 'nothing-matches-this', 200, 2);
    return $r['results'] === [] && $r['truncated'] === true;
})();
$checks['search results carry the same shape as a listing'] = (function () use ($fs): bool {
    // Compare like with like: a directory row has no extension key.
    $listed = array_values(array_filter($fs->list('/Photos'), fn($e) => $e['name'] === 'alpha.txt'))[0];
    $found = $fs->search('/', 'alpha')['results'][0];
    return $listed === $found;
})();

// --- copy ---------------------------------------------------------------
$fs->copyTree($base.'/Photos', $base.'/Docs/Photos-copy');
$checks['copy is recursive'] = is_file($base.'/Docs/Photos-copy/2024/beta-report.txt');
$checks['the original survives a copy'] = is_file($base.'/Photos/2024/beta-report.txt');

// --- free name ----------------------------------------------------------
$checks['a free name is returned unchanged'] = $fs->freeName($base.'/nothing.txt') === $base.'/nothing.txt';
$checks['a taken name gains a suffix before the extension'] =
    $fs->freeName($base.'/root-note.txt') === $base.'/root-note (2).txt';

// --- measure ------------------------------------------------------------
$measured = $fs->measure($base.'/Photos');
$checks['a folder measures its whole tree'] = $measured['files'] === 2 && $measured['bytes'] === 9;

// --- trash --------------------------------------------------------------
$meta = $fs->trash($base.'/root-note.txt', 'tester');
$checks['trashing removes the original'] = !file_exists($base.'/root-note.txt');
$checks['the item is kept under its own name'] = is_file($base.'/.trash/'.$meta['id'].'/payload/root-note.txt');
$checks['metadata records where it came from'] =
    $meta['originalPath'] === '/root-note.txt' && $meta['deletedBy'] === 'tester' && $meta['bytes'] === 4;
$checks['metadata is stored beside the payload, not inside it'] = is_file($base.'/.trash/'.$meta['id'].'/meta.json');
$checks['the trash is invisible to listings'] =
    !in_array('.trash', array_column($fs->list('/'), 'name'), true);
$checks['the trash is invisible to search'] = $fs->search('/', 'root-note')['results'] === [];

$listed = $fs->trashList();
$checks['the trash lists what was deleted'] = count($listed) === 1 && $listed[0]['name'] === 'root-note.txt';

// Restoring must never overwrite whatever now occupies the original path.
file_put_contents($base.'/root-note.txt', 'a different file');
$restored = $fs->restore($meta['id']);
$checks['restore does not overwrite a name that is taken'] =
    $restored['renamed'] === true
    && file_get_contents($base.'/root-note.txt') === 'a different file'
    && file_get_contents($base.'/root-note (2).txt') === 'root';
$checks['a restored entry leaves the trash'] = $fs->trashList() === [];

// A folder deleted along with its parent must still be restorable.
mkdir($base.'/Temp/Inner', 0775, true);
file_put_contents($base.'/Temp/Inner/deep.txt', 'deep');
$folderMeta = $fs->trash($base.'/Temp/Inner', null);
rmrf($base.'/Temp');
$fs->restore($folderMeta['id']);
$checks['restore recreates a missing parent folder'] = is_file($base.'/Temp/Inner/deep.txt');

// --- trash guards -------------------------------------------------------
$checks['the storage root cannot be trashed'] = (function () use ($fs, $base): bool {
    try { $fs->trash($base); return false; } catch (RuntimeException) { return true; }
})();
$checks['a malformed trash id is refused'] = (function () use ($fs): bool {
    try { $fs->restore('../../etc'); return false; } catch (RuntimeException) { return true; }
})();
$checks['purging an unknown id is refused'] = (function () use ($fs): bool {
    try { $fs->trashPurge('20260101-000000-deadbeef'); return false; } catch (RuntimeException) { return true; }
})();
$fs->trash($base.'/Photos/alpha.txt', null);
$checks['a retention of 0 keeps everything'] = $fs->trashPurgeExpired(0) === 0 && count($fs->trashList()) === 1;
$checks['emptying the trash removes every entry'] = $fs->trashPurge(null) === 1 && $fs->trashList() === [];

rmrf($base);

// --- routes -------------------------------------------------------------
foreach (['move', 'copy'] as $verb) {
    $checks["/api/files/$verb is a POST route"] = str_contains($index, "\$path === '/api/files/$verb' && \$method === 'POST'");
}
$checks['bulk relocation reports failures per item, not one verdict'] =
    str_contains($index, "\$failed[] = ['path' => \$rel, 'message' => \$e->getMessage()];")
    && str_contains($index, "return ['success' => \$failed === [], 'completed' => \$done, 'failed' => \$failed];");
$checks['a folder cannot be moved into itself'] = str_contains($index, 'A folder cannot be moved into itself');
$checks['moving into the same folder is refused, copying is not'] =
    str_contains($index, "if (\$verb === 'move' && dirname(\$source) === rtrim(\$destination, '/'))");
$checks['relocation never silently overwrites'] =
    str_contains($index, "if (!\$config['allow_overwrite'])throw new RuntimeException('An item with that name is already there', 409);")
    && str_contains($index, '$target = $fs->freeName($target);');
$checks['a one-character search is refused'] = str_contains($index, "if (mb_strlen(\$q) < 2)");
$checks['search does not hold the session lock'] = (function () use ($index): bool {
    $at = strpos($index, "'/api/files/search' && \$method === 'GET'");
    return $at !== false && str_contains(substr($index, $at, 200), 'release_session_lock();');
})();

// Trash routes: listing is a read, restoring and purging are writes. The
// generic guard applies the write check to every POST that is not exempt, so
// the assertion is that these were not added to the exempt list.
preg_match('/\$writeExemptPost = \[(.*?)\];/', $index, $exempt);
$checks['restore and purge still require the write capability'] =
    isset($exempt[1]) && !str_contains($exempt[1], '/api/trash');
$checks['the trash listing is a GET'] = str_contains($index, "\$path === '/api/trash' && \$method === 'GET'");
$checks['delete moves to the trash when it is enabled'] =
    str_contains($index, "\$meta = \$fs->trash(\$p, Auth::user()['username'] ?? null);")
    && str_contains($index, "'trashed' => true");
$checks['delete still honours an opt-out'] =
    str_contains($index, "if (!\$config['trash_enabled']) {") && str_contains($index, "'message' => 'Deleted permanently'");
$checks['expired entries are purged as deletions happen'] =
    str_contains($index, "\$fs->trashPurgeExpired((int)\$config['trash_retention_days']);");
$checks['the new operations are audited'] = (function () use ($index): bool {
    foreach (['file.trash', 'file.restore', 'file.purge', "'file.'.\$verb"] as $event) {
        if (!str_contains($index, $event)) return false;
    }
    return true;
})();

// --- configuration ------------------------------------------------------
$checks['the trash is configurable'] =
    str_contains($conf, "'trash_enabled'=>env_bool('TRASH_ENABLED',true)")
    && str_contains($conf, "'trash_retention_days'=>(int)env('TRASH_RETENTION_DAYS',30)");
$checks['the trash settings are documented'] =
    str_contains($env, 'TRASH_ENABLED=true') && str_contains($env, 'TRASH_RETENTION_DAYS=30');

// --- UI -----------------------------------------------------------------
$checks['the search field has a scope toggle'] =
    str_contains($view, 'id="scope-all"') && str_contains($app, "function setScope(scope)");
$checks['an all-folders search is debounced'] = str_contains($app, 'searchTimer = setTimeout(runSearch, 250)');
$checks['a stale search cannot overwrite a newer one'] = str_contains($app, 'if (run !== searchRun) return;');
$checks['search results say which folder they are in'] = str_contains($app, '` · in ${esc(parentLabel(f.path))}`');
$checks['move and copy are offered on a selection'] =
    str_contains($view, 'id="selection-move"') && str_contains($view, 'id="selection-copy"');
$checks['move and copy are offered on one item'] =
    str_contains($app, 'data-cmd="move"') && str_contains($app, 'data-cmd="copy"');
$checks['the destination is chosen by browsing folders'] =
    str_contains($view, 'id="picker-overlay"') && str_contains($app, 'function pickFolder(');
$checks['the picker offers folders only'] = str_contains($app, 'entries.filter(f => f.isDirectory)');
$checks['a Trash page exists'] = str_contains($view, 'id="trash-page"') && str_contains($app, 'async function loadTrash()');
$checks['/trash is a page route'] =
    (bool)preg_match("/in_array\(\\\$path, \[(.*?)\], true\)/", $index, $pages)
    && str_contains($pages[1], "'/trash'")
    && str_contains($app, "(p === '/trash')");
// Promising "cannot be undone" when the file goes to the trash, or promising
// the trash when the server deletes permanently, are both lies the user acts on.
$checks['the delete prompt matches what the server will do'] =
    str_contains($app, 'const trashed = await trashEnabled();')
    && str_contains($app, "trashed ? 'It can be restored from the trash.' : 'This cannot be undone.'");
$checks['a viewer sees the trash without the buttons'] =
    str_contains($app, "const canWrite = S.role === 'editor' || S.role === 'admin';")
    && str_contains($app, 'canWrite ? `<div class="actions">');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
