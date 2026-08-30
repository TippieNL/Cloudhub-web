<?php
declare(strict_types=1);

/**
 * GET /api/storage/me -- what the signed-in account is using.
 *
 * /api/storage/usage is admin-only, so an account with a quota had no way to
 * see how much of it it had spent; it found out when an upload came back 507.
 * This route answers that question for any signed-in caller.
 *
 * The response shape is shared with the Android build (TippieNL/Cloudhub-2).
 * One field deliberately diverges: `folders`. storageReport() returns that key
 * as a list of per-folder rows, and (int) on a non-empty array is 1 in PHP, so
 * casting it reports "1 folder" for every store that has any folders at all.
 * The cast emits no diagnostic, which is why the count is asserted for real
 * below rather than only against source.
 */
require dirname(__DIR__).'/src/Services/FileService.php';
use CloudHub\Services\FileService;

$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');

function rmrf20(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf20($p.'/'.$n); @rmdir($p); }
}

$checks = [];

// --- the folder count is a count ----------------------------------------

$base = sys_get_temp_dir().'/cloudhub-p20-'.bin2hex(random_bytes(5));
mkdir($base.'/Photos', 0775, true);
mkdir($base.'/Videos', 0775, true);
mkdir($base.'/Documents', 0775, true);
file_put_contents($base.'/Photos/a.jpg', str_repeat('i', 400));
file_put_contents($base.'/Videos/c.mp4', str_repeat('v', 1000));
file_put_contents($base.'/Documents/d.txt', str_repeat('d', 50));
file_put_contents($base.'/loose.bin', str_repeat('o', 7));

$fs = new FileService(['root_dir' => $base, 'read_only' => false]);
$report = $fs->storageReport();

$checks['storageReport reports folders as rows, not a count'] =
    is_array($report['folders']) && isset($report['folders'][0]['name']);
$checks['count() gives the real number of top-level folders'] =
    count($report['folders']) === 3;
// The bug this route must not reproduce: the cast collapses any non-empty
// list to 1, and reports 0 only when there are no folders at all.
$checks['(int) on that list would report 1 instead of 3'] =
    (int)$report['folders'] === 1;
$checks['a store with no folders counts zero either way'] =
    count((new FileService(['root_dir' => $base.'/Photos', 'read_only' => false]))
        ->storageReport()['folders']) === 0;

rmrf20($base);

// --- the route -----------------------------------------------------------

$checks['the route is registered for GET'] =
    str_contains($index, "\$path === '/api/storage/me' && \$method === 'GET'");

$handler = (string)(explode("if (\$path === '/api/trash'", (string)(
    explode("if (\$path === '/api/storage/me'", $index)[1] ?? ''))[0] ?? '');
$checks['the handler was found'] = $handler !== '';

$checks['the folder count uses count(), not a cast'] =
    str_contains($handler, "'folders' => count(\$report['folders'] ?? [])")
    && !str_contains($handler, "'folders' => (int)");

// Walking the whole store is expensive; a route every account can call must
// not be a way to make the server do that on demand.
$checks['the route never forces a fresh measurement'] =
    str_contains($handler, 'storage_report($fs, $config)')
    && !str_contains($handler, 'refresh');
$checks['?refresh stays on the admin route'] =
    str_contains($index, "storage_report(\$fs, \$config, !empty(\$_GET['refresh']))");

// Read-only, so it releases the session lock rather than serialising every
// other request behind it.
$checks['the session lock is released'] = str_contains($handler, 'release_session_lock()');

// Swept exactly as assert_upload_fits() does, so the figure shown is the
// figure that will refuse an upload.
$checks['the ledger is swept before reporting'] = str_contains($handler, 'ledger()->sweep($fs)');
$checks['usage is read for the calling account'] =
    str_contains($handler, "ledger()->usage((int)\$user['id'])");

// --- the shared contract with the Android build --------------------------

foreach (['usedBytes', 'quotaBytes', 'storeUsedBytes', 'storageLimitBytes',
    'diskFreeBytes', 'diskTotalBytes', 'files', 'folders', 'trash', 'versions',
    'cached', 'measuredAt', 'isAdmin'] as $field) {
    $checks["the response carries $field"] = str_contains($handler, "'$field' =>");
}
// This build has no file-versioning feature, so the key is never present in
// the report. It is still reported as zeroes so a shared client need not
// special-case its absence.
$checks['versions falls back to zeroes rather than being omitted'] =
    str_contains($handler, "\$report['versions'] ?? ['bytes' => 0, 'files' => 0]");

// --- authorization -------------------------------------------------------

// The generic guard covers every /api/ path that is not an auth endpoint, so
// this route requires a session without repeating the check itself.
$checks['the guard covers non-auth api paths'] =
    str_contains($index, "\$isProtectedApi = (str_starts_with(\$path, '/api/')&&!\$isAuthEndpoint)");
$checks['the guard requires a signed-in caller'] =
    str_contains($index, 'if ($isProtectedApi && $method !== \'OPTIONS\') {')
    && str_contains($index, 'Authorization::requireRead();');
// Anyone signed in, not just an administrator -- that is the point of it.
$checks['the route does not require admin'] = !str_contains($handler, 'requireAdmin');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
