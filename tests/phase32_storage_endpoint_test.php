<?php
declare(strict_types=1);

/**
 * The storage diagnostic, and the two things it must never do.
 *
 * The version of this that lived at the project root was removed for being
 * reachable by anyone while dumping absolute paths and a listing of every user
 * file. Exposing it again over HTTP is only safe while it stays
 * administrator-only and reports counts rather than names, so both are checked
 * by looking at what the report actually contains.
 */
require dirname(__DIR__).'/src/Services/StorageDiagnostics.php';

use CloudHub\Services\StorageDiagnostics;

function rmrf32(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') rmrf32($p.'/'.$n); @rmdir($p); }
}

/** Files left behind in a directory, so a probe that does not clean up is visible. */
function listing32(string $dir): array {
    $out = array_values(array_filter(scandir($dir) ?: [], static fn($n) => $n !== '.' && $n !== '..'));
    sort($out);
    return $out;
}

$root = dirname(__DIR__);
$checks = [];

$base = sys_get_temp_dir().'/cloudhub-p32-'.bin2hex(random_bytes(5));
mkdir($base.'/files', 0775, true);
mkdir($base.'/files/.uploads', 0775, true);
mkdir($base.'/storage/.thumbnails', 0775, true);
mkdir($base.'/logs', 0775, true);

// A distinctive name, so "does the report leak filenames" is answerable.
$secret = 'my-private-holiday-photo-9f3a2b.jpg';
file_put_contents($base.'/files/'.$secret, str_repeat('x', 2048));

$config = [
    'root_dir' => $base.'/files',
    'upload_staging_dir' => '',
    'upload_chunk_mb' => 8,
];
$diag = new StorageDiagnostics($config, $base);

// --- the cheap call stays cheap ------------------------------------------

$before = listing32($base.'/files');
$report = $diag->report();
$after = listing32($base.'/files');

$checks['a plain report leaves no files behind'] = $before === $after;
$checks['and does not measure throughput'] = $report['throughput'] === null;

// --- measuring is opt-in, and cleans up ----------------------------------

$measured = $diag->report(2);
$checks['measuring reports throughput'] = is_array($measured['throughput'] ?? null);
$checks['it measures the storage root'] =
    isset($measured['throughput']['ROOT_DIR (served files)']['writeMbPerSecond']);
$checks['and the staging directory'] =
    isset($measured['throughput']['upload staging']['writeMbPerSecond']);
$checks['the probe file is removed afterwards'] = listing32($base.'/files') === $after;
$checks['the size it measured is reported'] = ($measured['throughput']['megabytes'] ?? 0) === 2;

// --- it reports counts, never names --------------------------------------

$json = json_encode($measured);
$checks['no filename reaches the report'] = !str_contains((string)$json, $secret);
$rootRow = null;
foreach ($measured['paths'] as $row) if ($row['label'] === 'ROOT_DIR (served files)') $rootRow = $row;
$checks['the storage root is described'] = $rootRow !== null;
$checks['it counts entries instead'] = ($rootRow['entries'] ?? null) === 2;   // the photo and .uploads
$checks['it probed writability for real'] = ($rootRow['writable'] ?? null) === true;

// --- the rename-versus-copy verdict, both directions ---------------------

$checks['same filesystem is reported as a rename'] =
    ($measured['finishing']['sameFilesystem'] ?? null) === true
    && str_contains($measured['finishing']['verdict'], 'instant rename');

// /dev/shm is a separate mount, which is the cross-device case for real.
$foreign = '/dev/shm/cloudhub-p32-'.bin2hex(random_bytes(5));
if (@mkdir($foreign, 0775, true)) {
    $across = (new StorageDiagnostics(array_merge($config, ['upload_staging_dir' => $foreign]), $base))->report();
    $checks['a different filesystem is reported as a copy'] =
        ($across['finishing']['sameFilesystem'] ?? null) === false
        && str_contains($across['finishing']['verdict'], 'DIFFERENT filesystems');
    rmrf32($foreign);
} else {
    echo "[SKIP] cross-device check needs a second writable filesystem\n";
}

// A staging directory that does not exist yet is not a different filesystem.
$notYet = (new StorageDiagnostics(array_merge($config, ['upload_staging_dir' => $base.'/files/.not-created']), $base))->report();
$checks['an uncreated staging directory is not a false alarm'] =
    ($notYet['finishing']['sameFilesystem'] ?? null) === true
    && ($notYet['finishing']['stagingExists'] ?? true) === false;

// --- the request-size warning --------------------------------------------

$checks['a request limit at or below the chunk size is flagged'] =
    StorageDiagnostics::iniBytes('8M') === 8 * 1048576
    && StorageDiagnostics::iniBytes('512K') === 512 * 1024
    && StorageDiagnostics::iniBytes('1G') === 1073741824;
$tight = (new StorageDiagnostics(array_merge($config, ['upload_chunk_mb' => 4096]), $base))->report();
$checks['an oversized chunk trips the warning'] = $tight['runtime']['warnings'] !== [];
$checks['and the warning reaches the problem list'] =
    in_array($tight['runtime']['warnings'][0], $tight['problems'], true);

// --- the route and the tool are the same report --------------------------

$index = (string)file_get_contents($root.'/public/index.php');
$checks['the route is administrator-only'] =
    str_contains($index, "if (\$path === '/api/system/storage' && \$method === 'GET') api_try(function()use(\$config) {\n    Authorization::requireAdmin();");
$checks['the route releases the session lock'] = (static function(string $i): bool {
    $at = strpos($i, "if (\$path === '/api/system/storage'");
    return $at !== false && str_contains(substr($i, $at, (int)strpos($i, "\n});", $at) - $at), 'release_session_lock();');
})($index);
$checks['measuring is opt-in on the route'] =
    str_contains($index, "empty(\$_GET['measure']) ? 0 : max(1, min(64, (int)(\$_GET['mb'] ?? 16)))");

$tool = (string)file_get_contents($root.'/tools/storage-check.php');
$checks['the tool renders the shared report'] =
    str_contains($tool, 'new StorageDiagnostics($config, dirname(__DIR__))')
    && str_contains($tool, "PHP_SAPI !== 'cli'");
$checks['the tool holds no report logic of its own'] =
    !str_contains($tool, 'function measure_io') && !str_contains($tool, 'stat($stagingDir)');

rmrf32($base);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
