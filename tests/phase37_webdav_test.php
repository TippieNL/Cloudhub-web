<?php
declare(strict_types=1);

/**
 * WebDAV under the application's own rules, over real HTTP.
 *
 * handle_webdav() answers through header() and exit(), so it is run behind the
 * built-in server with a real FileService and a session role chosen per
 * request. No database is involved, which is what lets this run in CI builds
 * that have none.
 *
 * Pins: write verbs need the write capability inside the handler too; a GET
 * is a download, never a page rendered on this origin; PROPFIND does not
 * advertise CloudHub's own directories and emits encoded hrefs; DELETE and an
 * overwriting MOVE honour the trash; a MOVE onto itself deletes nothing.
 */
$root = dirname(__DIR__);
$checks = [];
$tmp = sys_get_temp_dir().'/cloudhub-p37-'.bin2hex(random_bytes(5));
mkdir($tmp.'/files/docs', 0775, true);
mkdir($tmp.'/files/.trash', 0775, true);
file_put_contents($tmp.'/files/docs/page one.html', '<b>hello</b>');
file_put_contents($tmp.'/files/docs/keep.txt', 'keep');
file_put_contents($tmp.'/files/docs/other.txt', 'other');

file_put_contents($tmp.'/harness.php', "<?php\n".
    "declare(strict_types=1);\n".
    "require '".$root."/src/Helpers/Http.php';\n".
    "require '".$root."/src/Services/FileService.php';\n".
    "require '".$root."/src/Services/Auth.php';\n".
    "require '".$root."/src/Services/Authorization.php';\n".
    "require '".$root."/src/Services/WebDav.php';\n".
    'function mime_type(string $f): string { return function_exists("mime_content_type") ? (mime_content_type($f) ?: "application/octet-stream") : "application/octet-stream"; }'."\n".
    '$_SESSION = ["user_id" => 1, "username" => "tester", "role" => (string)($_GET["role"] ?? "viewer")];'."\n".
    '$config = ["root_dir" => '.var_export($tmp.'/files', true).', "read_only" => false, "allow_overwrite" => true, "allow_delete" => true, "trash_enabled" => true];'."\n".
    '$fs = new \CloudHub\Services\FileService($config);'."\n".
    '\CloudHub\Services\handle_webdav($fs, $config, "/webdav".(string)($_GET["p"] ?? "/"), $_SERVER["REQUEST_METHOD"]);'."\n");

$port = 8900 + random_int(0, 90);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $tmp.'/harness.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
$base = 'http://127.0.0.1:'.$port.'/';
for ($i = 0; $i < 200; $i++) { if (@file_get_contents($base.'?p=/') !== false || isset($http_response_header)) break; usleep(25000); }

/** @return array{status:int,headers:string,body:string} */
function dav(string $base, string $method, string $path, string $role, array $headers = []): array {
    $ch = curl_init($base.'?role='.rawurlencode($role).'&p='.rawurlencode($path));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

// --- the role gate lives in the handler as well as the front controller -----
$r = dav($base, 'MKCOL', '/docs/made-by-viewer', 'viewer');
$checks['a viewer cannot MKCOL'] = $r['status'] === 403 && !is_dir($tmp.'/files/docs/made-by-viewer');

$r = dav($base, 'MOVE', '/docs/other.txt', 'viewer', ['Destination: /webdav/docs/keep.txt', 'Overwrite: T']);
$checks['a viewer cannot MOVE one file over another'] = $r['status'] === 403
    && @file_get_contents($tmp.'/files/docs/keep.txt') === 'keep';

$r = dav($base, 'DELETE', '/docs/keep.txt', 'viewer');
$checks['a viewer cannot DELETE'] = $r['status'] === 403 && is_file($tmp.'/files/docs/keep.txt');

// --- a GET is a download, never a document on this origin ----------------
$r = dav($base, 'GET', '/docs/page one.html', 'viewer');
$checks['a GET still returns the bytes'] = $r['status'] === 200 && $r['body'] === '<b>hello</b>';
$checks['a GET is served as an attachment'] = (bool)preg_match('/Content-Disposition: attachment/i', $r['headers']);
$checks['a GET carries a sandbox CSP'] = (bool)preg_match("/Content-Security-Policy: default-src 'none'; sandbox/i", $r['headers']);

// --- PROPFIND: no internals, encoded hrefs --------------------------------
$r = dav($base, 'PROPFIND', '/', 'viewer', ['Depth: 1']);
$checks['PROPFIND answers 207'] = $r['status'] === 207;
$checks['PROPFIND does not advertise .trash'] = !str_contains($r['body'], '.trash');
$r = dav($base, 'PROPFIND', '/docs', 'viewer', ['Depth: 1']);
$checks['PROPFIND hrefs are percent-encoded'] = str_contains($r['body'], '/webdav/docs/page%20one.html')
    && !str_contains($r['body'], '<d:href>/webdav/docs/page one.html');

// --- a MOVE onto itself is refused, not a delete --------------------------
// The destination "exists" because it is the source. Displacing it trashed the
// file itself -- with the trash off, deleted it outright -- and then the
// rename failed on a source that was no longer there.
file_put_contents($tmp.'/files/docs/self.txt', 'self');
$r = dav($base, 'MOVE', '/docs/self.txt', 'editor', ['Destination: /webdav/docs/self.txt', 'Overwrite: T']);
$checks['a MOVE onto itself is refused'] = $r['status'] === 403;
$checks['and the file is still there, untouched'] = @file_get_contents($tmp.'/files/docs/self.txt') === 'self'
    && (glob($tmp.'/files/.trash/*/meta.json') ?: []) === [];

// --- an editor's destructive verbs honour the trash -----------------------
$r = dav($base, 'MOVE', '/docs/other.txt', 'editor', ['Destination: /webdav/docs/keep.txt', 'Overwrite: T']);
$checks['an editor can MOVE over a file'] = in_array($r['status'], [201, 204], true)
    && @file_get_contents($tmp.'/files/docs/keep.txt') === 'other';
$trashed = static fn(): array => array_values(array_filter(glob($tmp.'/files/.trash/*/meta.json') ?: [], 'is_file'));
$checks['the file the MOVE displaced went to the trash'] = count($trashed()) === 1;

$r = dav($base, 'DELETE', '/docs/keep.txt', 'editor');
$checks['an editor can DELETE'] = $r['status'] === 204 && !is_file($tmp.'/files/docs/keep.txt');
$checks['and the deleted file went to the trash'] = count($trashed()) === 2;

if (is_resource($server)) { proc_terminate($server); proc_close($server); }

// --- the shape that keeps the front controller's guard honest -------------
$index = (string)file_get_contents($root.'/public/index.php');
$checks['the front controller gates every non-read verb'] =
    str_contains($index, "!in_array(\$method, ['GET', 'HEAD', 'OPTIONS', 'PROPFIND'], true)");
$checks['the front controller passes bookkeeping hooks'] =
    str_contains($index, "'removed' => function(string \$rel): void {") && str_contains($index, 'shares_forget($rel);');

$rmrf = static function (string $p) use (&$rmrf): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (is_dir($p)) { foreach (scandir($p) ?: [] as $n) if ($n !== '.' && $n !== '..') $rmrf($p.'/'.$n); @rmdir($p); }
};
$rmrf($tmp);

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
