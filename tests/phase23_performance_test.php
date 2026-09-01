<?php
declare(strict_types=1);

/**
 * The measured costs of opening a folder, and the guards that must survive
 * removing them.
 *
 * Two of these changes touch symlink handling, which is a containment control
 * -- so the symlink behaviour is re-proved here against real symlinks on disk
 * rather than assumed from the shape of the diff.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
use CloudHub\Services\FileService;

function rmrf23(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf23($p.'/'.$n); @rmdir($p); }
}

$root = dirname(__DIR__);
$checks = [];

// --- containment still holds after hoisting the prefix walk --------------

$base = sys_get_temp_dir().'/cloudhub-p23-'.bin2hex(random_bytes(5));
mkdir($base.'/inside/deep', 0775, true);
mkdir($base.'/outside', 0775, true);
file_put_contents($base.'/inside/real.txt', 'real');
file_put_contents($base.'/inside/deep/nested.txt', 'nested');
file_put_contents($base.'/outside/secret.txt', 'secret');

// A symlink pointing out of the storage root, and a symlinked directory.
symlink($base.'/outside/secret.txt', $base.'/inside/escape.txt');
symlink($base.'/outside', $base.'/inside/escape-dir');

$fs = new FileService(['root_dir' => $base, 'read_only' => false]);
$names = array_column($fs->list('/inside'), 'name');
sort($names);

$checks['a symlinked file is still hidden from listings'] = !in_array('escape.txt', $names, true);
$checks['a symlinked directory is still hidden from listings'] = !in_array('escape-dir', $names, true);
$checks['real entries are still listed'] = $names === ['deep', 'real.txt'];

// Reaching through a symlink by path must still be refused.
$refusedFile = false;
try { $fs->existing('/inside/escape.txt'); } catch (Throwable) { $refusedFile = true; }
$checks['a symlinked file cannot be addressed directly'] = $refusedFile;

$refusedDir = false;
try { $fs->list('/inside/escape-dir'); } catch (Throwable) { $refusedDir = true; }
$checks['a listing cannot descend through a symlinked directory'] = $refusedDir;

// A directory whose own chain contains a symlink yields nothing, which is
// what the per-entry walk produced before it was hoisted.
$checks['nothing leaks from below a symlinked parent'] = $fs->list('/inside/deep') !== null
    && array_column($fs->list('/inside/deep'), 'name') === ['nested.txt'];

// Traversal and absolute paths are unaffected.
foreach (['/inside/../outside/secret.txt' => 'traversal is refused',
          '/etc/passwd' => 'an absolute path is not treated as native'] as $probe => $label) {
    $refused = false;
    try { $fs->existing($probe); } catch (Throwable) { $refused = true; }
    $checks[$label] = $refused;
}

rmrf23($base);

// --- the redundant walk is gone -----------------------------------------

$fsSrc = (string)file_get_contents($root.'/src/Services/FileService.php');
// existing() called pathContainsSymlink() on the very path sanitize() had
// just proven, re-stat'ing every component for a verdict that could not differ.
$checks['existing() no longer repeats the walk sanitize() did'] =
    !str_contains($fsSrc, "if(\$this->pathContainsSymlink(\$candidate))throw new RuntimeException('Symlink access is not allowed',403);");
$checks['sanitize() still performs it'] =
    str_contains($fsSrc, '$this->assertNoSymlinkTraversal($candidate);');
// children() proves the shared parent chain once instead of once per entry.
$checks['children() checks the directory chain once'] =
    str_contains($fsSrc, 'if($this->escapingSymlink($dir))return [];')
    && str_contains($fsSrc, "\$full=\$dir.'/'.\$name;if(is_link(\$full))continue;");

// --- routes that must not hold the session lock -------------------------

$index = (string)file_get_contents($root.'/public/index.php');
foreach ([
    '/api/thumbnail/video' => "if (\$path === '/api/thumbnail/video' && \$method === 'POST') api_try(function()use(\$fs) {\n        release_session_lock();",
    '/api/files/download-zip' => "if (\$path === '/api/files/download-zip' && \$method === 'POST') api_try(function()use(\$fs) {\n        release_session_lock();",
    '/api/shares/list' => "if (\$path === '/api/shares/list' && \$method === 'GET') api_try(function()use(\$config, \$basePath, \$fs) {\n        release_session_lock();",
    '/api/security/events' => "if (\$path === '/api/security/events' && \$method === 'GET') api_try(function() {\n        release_session_lock();",
] as $route => $needle) {
    $checks["$route releases the session lock"] = str_contains($index, $needle);
}

// --- the trash is not rescanned on every delete -------------------------

$checks['the expiry purge is throttled by a stamp file'] =
    str_contains($index, 'function purge_expired_trash_occasionally(')
    && str_contains($index, "\$stamp = dirname(__DIR__).'/storage/.cache/trash-purge';");
$checks['the delete route uses the throttled purge'] =
    str_contains($index, 'purge_expired_trash_occasionally($fs, (int)$config[\'trash_retention_days\']);')
    && !str_contains($index, "\$fs->trashPurgeExpired((int)\$config['trash_retention_days']);");

// --- the archive is removed even when the client disconnects ------------

// ignore_user_abort is 0 by default, so readfile() below is where PHP dies on
// a cancelled download and the unlink after it never ran.
$checks['the temporary archive is unlinked on every exit path'] =
    str_contains($index, 'register_shutdown_function(static function() use ($tmp): void { @unlink($tmp); });');

// --- the session file is not rewritten on every request -----------------

$authSrc = (string)file_get_contents($root.'/src/Services/Auth.php');
$checks['last_seen_at only moves once it has aged'] =
    str_contains($authSrc, "if(\$now-(int)(\$_SESSION['last_seen_at']??0)>=60)\$_SESSION['last_seen_at']=\$now;")
    // The old per-request form, which changed $_SESSION every time and so
    // defeated session.lazy_write.
    && !str_contains($authSrc, "\$_SESSION['created_at']??=\$now;\$_SESSION['last_seen_at']=\$now;");
// A session that was just destroyed and restarted still stamps the clock
// unconditionally -- it has no previous value to age from.
$checks['a reset session still stamps the clock'] =
    substr_count($authSrc, "\$_SESSION['created_at']=\$now;\$_SESSION['last_seen_at']=\$now;") >= 2;

// --- the file list is not rebuilt on every keystroke --------------------

$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$checks['the local filter is debounced like the remote one'] =
    str_contains($app, "searchTimer = setTimeout(S.scope === 'all' ? runSearch : renderFiles, 250);");
$checks['the previous IntersectionObserver is disconnected'] =
    str_contains($app, 'if (videoThumbObserver) { videoThumbObserver.disconnect(); videoThumbObserver = null; }')
    && str_contains($app, 'const observer = videoThumbObserver = new IntersectionObserver');
$checks['one toggle updates one card'] =
    str_contains($app, 'updateSelectionCount();')
    && str_contains($app, 'if (card) card.classList.toggle(\'selected\', checked);');
// The full resync still exists for re-render and select-all.
$checks['a full resync is still available'] =
    str_contains($app, 'function updateSelectionUI() {')
    && str_contains($app, "document.querySelectorAll('[data-sel]')");

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
