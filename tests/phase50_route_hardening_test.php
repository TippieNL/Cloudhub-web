<?php
declare(strict_types=1);

/**
 * Route fixes from the 2026-09 audit, each reproduced against a live MariaDB
 * install before it was made:
 *
 *   - /api/servers/active handed every signed-in account, viewers included,
 *     each server's whole configuration: hosts, accounts and the HTTP
 *     adapter's custom headers, bearer token and all. Only three key names
 *     were masked, even for administrators.
 *   - a ZIP of "/" walked CloudHub's own .trash and .uploads with scandir(),
 *     handing out deleted files and other accounts' bookkeeping.
 *   - several uploads started together each passed the quota check at init,
 *     then all landed: 1.8 MB stored against a 1 MB quota.
 *   - a WebDAV PUT whose body stopped short of its Content-Length was
 *     committed, replacing the file with the first part of the new one.
 *
 * The functions are lifted out of index.php and run; the rest needs MySQL
 * and a web server, so the wiring is pinned against the source.
 */
$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$webdav = (string)file_get_contents($root.'/src/Services/WebDav.php');
$checks = [];

/** Lift one top-level function out of a source file, braces balanced. */
function extract_function50(string $source, string $name): string {
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) return '';
    $open = strpos($source, '{', $start);
    if ($open === false) return '';
    $depth = 0;
    for ($i = $open, $n = strlen($source); $i < $n; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') { $depth--; if ($depth === 0) return substr($source, $start, $i-$start+1); }
    }
    return '';
}

// --- storage server configuration ---------------------------------------------------
$lifted = extract_function50($index, 'mask_server').extract_function50($index, 'public_server');
$checks['mask_server() and public_server() could be lifted from index.php'] =
    str_contains($lifted, 'function mask_server(') && str_contains($lifted, 'function public_server(');
eval($lifted);
$server = ['id' => 2, 'name' => 'Offsite', 'type' => 'http_api', 'isActive' => true, 'isDefault' => false,
    'config' => ['apiEndpoint' => 'https://backup.example/api', 'apiKey' => 'k-123', 'basePath' => '/srv',
        'headers' => ['Authorization' => 'Bearer sk_live_SECRET'], 'passphrase' => 'hunter2', 'password' => '',
        'privateKey' => 'PEM', 'username' => 'svc']];
$masked = mask_server($server);
$checks['request headers are masked for administrators'] = $masked['config']['headers'] === '••••••••';
$checks['as are passphrases, keys and tokens'] = $masked['config']['passphrase'] === '••••••••'
    && $masked['config']['apiKey'] === '••••••••' && $masked['config']['privateKey'] === '••••••••';
$checks['what is not a credential stays readable'] = $masked['config']['apiEndpoint'] === 'https://backup.example/api'
    && $masked['config']['username'] === 'svc' && $masked['config']['basePath'] === '/srv';
$checks['an empty credential is not dressed up as a set one'] = $masked['config']['password'] === '';
$checks['no secret survives masking'] = !str_contains(json_encode($masked), 'sk_live_SECRET');
$public = public_server($server);
$checks['everyone else gets the name and type only'] =
    array_keys($public) === ['id', 'name', 'type', 'isActive', 'isDefault'] && !isset($public['config']);
$checks['/api/servers/active picks by role'] =
    str_contains($index, "array_map(Authorization::isAdmin() ? 'mask_server' : 'public_server', \$repo->all(true))");
$checks['server input is validated before it reaches the database'] =
    str_contains($index, '$b = server_input(Http::body(), false);') && str_contains($index, '$b = server_input(Http::body(), true);')
    && str_contains($index, "'config must be an object'");

// --- the ZIP walk ------------------------------------------------------------------------
$zipStart = strpos($index, "if (\$path === '/api/files/download-zip'");
$zipRoute = $zipStart === false ? '' : substr($index, $zipStart, (int)strpos($index, "if (\$path === '/api/shares/create'", $zipStart) - $zipStart);
$checks['the ZIP walks folders with the listing rules'] =
    str_contains($zipRoute, '$entries = $fs->childPaths($full);') && !str_contains($zipRoute, 'scandir($full)');
$checks['already-compressed media is stored, not deflated again'] =
    str_contains($zipRoute, '$zip->setCompressionName($entry, ZipArchive::CM_STORE);')
    && str_contains($index, 'const ZIP_STORED_EXTENSIONS = [');
$checks['building the archive may outlast max_execution_time'] = str_contains($zipRoute, '@set_time_limit(0);');

// --- the quota is checked where the file lands -----------------------------------------------
$completeStart = strpos($index, "if (\$path === '/api/uploads/complete'");
$complete = $completeStart === false ? '' : substr($index, $completeStart, 900);
$checks['completing an upload checks the quota again'] =
    str_contains($complete, "\$staged = (int)(uploads()->status(\$id)['size'] ?? 0);")
    && strpos($complete, 'assert_upload_fits($fs, $config, $staged);') < strpos($complete, '$done = uploads()->complete($id);');

// --- WebDAV PUT --------------------------------------------------------------------------------
$checks['a PUT shorter than its Content-Length is discarded'] =
    str_contains($webdav, 'if($ok&&ctype_digit($declared)&&$size!==(int)$declared){@unlink($tmp);http_response_code(400);exit;}')
    && strpos($webdav, '$size!==(int)$declared') < strpos($webdav, '@rename($tmp,$full)');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
