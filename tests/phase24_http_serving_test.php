<?php
declare(strict_types=1);

/**
 * Range serving, request-body parsing and the inline-rendering rule.
 *
 * serve_file_range() answers through header() and exit(), neither of which
 * says anything useful in the CLI SAPI -- so the function's own source is
 * lifted out of public/index.php and exercised over real HTTP by the built-in
 * server. That way these assertions are about what a browser receives, not
 * about the shape of the source.
 */

$root = dirname(__DIR__);
$checks = [];
$tmp = sys_get_temp_dir().'/cloudhub-p24-'.bin2hex(random_bytes(5));
mkdir($tmp, 0775, true);

/** Lift one top-level function out of a PHP file, braces balanced. */
function extract_function(string $source, string $name): string {
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

$index = (string)file_get_contents($root.'/public/index.php');
$fn = extract_function($index, 'serve_file_range');
// serve_file_range() names the file through this helper.
$disposition = extract_function($index, 'content_disposition');
$checks['serve_file_range() could be lifted from index.php'] = $fn !== '' && str_contains($fn, 'Content-Range');
// The per-range cap is what keeps one large video off the single-worker
// built-in server's only thread; pin that it is applied to a range answer.
$checks['a range answer is capped to one chunk'] =
    str_contains($index, 'const MEDIA_RANGE_CHUNK_BYTES')
    && str_contains($fn, 'MEDIA_RANGE_CHUNK_BYTES');

// A harness that serves whichever fixture the query string names. The per-range
// cap is a module const in index.php, so define it here (small, for the tiny
// fixtures) before the lifted function that reads it.
file_put_contents($tmp.'/harness.php', "<?php\n".
    "const MEDIA_RANGE_CHUNK_BYTES = 16;\n".$disposition."\n".$fn."\n".
    '$f = $_GET["f"] ?? "";'."\n".
    '$path = __DIR__."/".basename($f);'."\n".
    '$d = ($_GET["d"] ?? "") === "attachment" ? "attachment" : "inline";'."\n".
    'serve_file_range($path, "application/octet-stream", $d, $_SERVER["REQUEST_METHOD"]);'."\n");

file_put_contents($tmp.'/empty.bin', '');
file_put_contents($tmp.'/data.bin', str_repeat('A', 100));

$port = 8100 + random_int(0, 300);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $tmp, $tmp.'/harness.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp
);

$base = 'http://127.0.0.1:'.$port.'/harness.php';
for ($i = 0; $i < 200; $i++) { if (@file_get_contents($base.'?f=data.bin') !== false) break; usleep(25000); }

/** @return array{status:int,headers:string,body:string} */
function fetch(string $url, array $headers = []): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $raw = implode("\n", $http_response_header ?? []);
    preg_match('~HTTP/\S+\s+(\d+)~', $raw, $m);
    return ['status' => (int)($m[1] ?? 0), 'headers' => $raw, 'body' => (string)$body];
}

// A zero-length file has no satisfiable byte range. This answered 206 with
// "Content-Range: bytes 0--1/0" -- a range running backwards, over a file
// with no bytes in it.
$r = fetch($base.'?f=empty.bin', ['Range: bytes=-5']);
$checks['a suffix range on a zero-byte file is 416'] = $r['status'] === 416;
$checks['and it does not emit a backwards Content-Range'] = !str_contains($r['headers'], '0--1');
$checks['416 reports the unsatisfiable size'] = str_contains($r['headers'], 'Content-Range: bytes */0');

// The explicit form was already correct; it must stay that way.
$r = fetch($base.'?f=empty.bin', ['Range: bytes=0-']);
$checks['an explicit range on a zero-byte file is still 416'] = $r['status'] === 416;

// A zero-byte file with no range at all is a legitimate empty 200.
$r = fetch($base.'?f=empty.bin');
$checks['a zero-byte file with no range is 200'] = $r['status'] === 200 && $r['body'] === '';

// Ordinary suffix and explicit ranges keep working.
$r = fetch($base.'?f=data.bin', ['Range: bytes=-5']);
$checks['a suffix range on a real file still serves the tail'] =
    $r['status'] === 206 && $r['body'] === 'AAAAA' && str_contains($r['headers'], 'Content-Range: bytes 95-99/100');

$r = fetch($base.'?f=data.bin', ['Range: bytes=10-19']);
$checks['an explicit range still serves that window'] =
    $r['status'] === 206 && strlen($r['body']) === 10 && str_contains($r['headers'], 'Content-Range: bytes 10-19/100');

$r = fetch($base.'?f=data.bin', ['Range: bytes=200-300']);
$checks['a range past the end is 416'] = $r['status'] === 416;

// An open-ended range is shortened to one chunk (16 bytes in this harness),
// so a single large video cannot hold the single-worker server open. The
// client is answered with what it can ask more of, not the whole file.
$r = fetch($base.'?f=data.bin', ['Range: bytes=0-']);
$checks['an open-ended range is capped to one chunk'] =
    $r['status'] === 206 && strlen($r['body']) === 16 && str_contains($r['headers'], 'Content-Range: bytes 0-15/100');

// Only inline media is chunked. A download manager resuming an attachment
// with "bytes=N-" takes the answer as the rest of the file, so a short one
// would be saved as a truncated download.
$r = fetch($base.'?f=data.bin&d=attachment', ['Range: bytes=10-']);
$checks['a resumed attachment gets the whole remainder'] =
    $r['status'] === 206 && strlen($r['body']) === 90 && str_contains($r['headers'], 'Content-Range: bytes 10-99/100');

// A name outside ASCII travels in filename*, which is what browsers use now
// that downloads are saved under the name the server gives.
file_put_contents($tmp.'/Überweisung 写真.bin', 'x');
$r = fetch($base.'?f='.rawurlencode('Überweisung 写真.bin').'&d=attachment');
$checks['a non-ASCII name is sent as filename*'] =
    str_contains($r['headers'], "filename*=UTF-8''".rawurlencode('Überweisung 写真.bin'));

$r = fetch($base.'?f=data.bin');
$checks['no range serves the whole file'] =
    $r['status'] === 200 && strlen($r['body']) === 100 && str_contains($r['headers'], 'Content-Length: 100');

// An unreadable file must fail before Content-Length is committed, so the
// error body cannot be read as the file's bytes.
file_put_contents($tmp.'/locked.bin', str_repeat('B', 100));
chmod($tmp.'/locked.bin', 0000);
if (@fopen($tmp.'/locked.bin', 'rb') === false) {
    $r = fetch($base.'?f=locked.bin');
    $checks['an unreadable file does not emit a file Content-Length'] = !str_contains($r['headers'], 'Content-Length: 100');
} else {
    echo "[SKIP] unreadable-file check needs an unprivileged user\n";
}
chmod($tmp.'/locked.bin', 0644);

if (is_resource($server)) { proc_terminate($server); proc_close($server); }

// --- request body ---------------------------------------------------------

// Exercised over HTTP rather than in-process: php://input is empty in the CLI
// SAPI, so a CLI probe would "pass" every case by reading nothing at all.
file_put_contents($tmp.'/body.php', "<?php\n".
    "require '".$root."/src/Helpers/Http.php';\n".
    'try { $v = \CloudHub\Helpers\Http::body(); echo "OK:".json_encode($v); }'."\n".
    'catch (Throwable $e) { echo "THREW"; }'."\n");

$bodyPort = 8500 + random_int(0, 300);
$bodyServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:'.$bodyPort, '-t', $tmp],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $bp, $tmp
);
$bodyUrl = 'http://127.0.0.1:'.$bodyPort.'/body.php';
for ($i = 0; $i < 200; $i++) { if (@file_get_contents($bodyUrl) !== false) break; usleep(25000); }

/** POST a raw body and return what the parser made of it. */
$postBody = static function(string $raw) use ($bodyUrl): string {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json",
        'content' => $raw, 'ignore_errors' => true, 'timeout' => 10,
    ]]);
    return (string)@file_get_contents($bodyUrl, false, $ctx);
};

// {} is a valid empty object and means exactly what an empty body means,
// which the parser one line earlier already accepts.
$checks['an empty JSON object is accepted'] = $postBody('{}') === 'OK:[]';
$checks['an empty body is still accepted'] = $postBody('') === 'OK:[]';
$checks['a populated object is still accepted'] = str_contains($postBody('{"a":1}'), '"a":1');
$checks['a JSON array is still refused'] = !str_contains($postBody('[1,2]'), 'OK:');
$checks['a populated array is refused as a list'] = str_contains($postBody('[1,2]'), 'INVALID_JSON');
$checks['malformed JSON is still refused'] = !str_contains($postBody('{oops'), 'OK:');

if (is_resource($bodyServer)) { proc_terminate($bodyServer); proc_close($bodyServer); }

// --- inline rendering rule ------------------------------------------------

// Proved by calling the real functions, not by matching their source: the
// rule is "SVG and friends never render inline", and a source needle cannot
// tell an allowlist entry from a denylist entry.
$mimeFns = '';
foreach (['mime_type', 'media_mime_type', 'mime_renders_markup', 'preview_is_text', 'share_media_kind'] as $needed) {
    $lifted = extract_function($index, $needed);
    if ($lifted === '') { $mimeFns = ''; break; }
    $mimeFns .= $lifted."\n";
}
$checks['the mime functions could be lifted'] = $mimeFns !== '';

$fixtures = $tmp.'/mime';
@mkdir($fixtures, 0775, true);
file_put_contents($fixtures.'/x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
file_put_contents($fixtures.'/x.html', '<html><body><script>alert(1)</script></body></html>');
file_put_contents($fixtures.'/x.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
file_put_contents($fixtures.'/x.txt', 'plain');

$probe = $tmp.'/mime.php';
file_put_contents($probe, "<?php\n".$mimeFns."\n".
    '$out = [];'."\n".
    'foreach (glob(__DIR__."/mime/*") as $f) {'."\n".
    '  $m = mime_type($f);'."\n".
    '  $inline = (str_starts_with($m, "image/") || str_starts_with($m, "audio/") || $m === "application/pdf" || $m === "text/plain") && !mime_renders_markup($m);'."\n".
    '  $out[basename($f)] = ["mime" => $m, "inline" => $inline, "text" => preview_is_text($m), "shareKind" => share_media_kind($f)];'."\n".
    '}'."\n".
    'echo json_encode($out);'."\n");

$verdicts = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe).' 2>/dev/null'), true) ?: [];
$checks['the mime probe ran'] = $verdicts !== [];
$checks['an SVG is refused inline preview'] = ($verdicts['x.svg']['inline'] ?? true) === false;
$checks['an SVG is not inline media on the share path'] = ($verdicts['x.svg']['shareKind'] ?? '') === 'other';
$checks['an HTML file is refused inline preview'] = ($verdicts['x.html']['inline'] ?? true) === false;
$checks['an HTML file is not inline media on the share path'] = ($verdicts['x.html']['shareKind'] ?? '') === 'other';
// The rule must not have swallowed ordinary previewable files.
$checks['a PNG still previews inline'] = ($verdicts['x.png']['inline'] ?? false) === true;
$checks['a PNG is still inline media on the share path'] = ($verdicts['x.png']['shareKind'] ?? '') === 'image';
$checks['plain text still previews inline'] = ($verdicts['x.txt']['inline'] ?? false) === true;
// Source files preview as text/plain, which a browser never renders as
// markup -- so an HTML or SVG file shows its source instead of a 415.
$checks['an HTML file previews as plain text'] = ($verdicts['x.html']['text'] ?? false) === true;
$checks['an SVG previews as plain text'] = ($verdicts['x.svg']['text'] ?? false) === true;
$checks['a PNG is not turned into text'] = ($verdicts['x.png']['text'] ?? true) === false;
$checks['the preview route sends text as text/plain'] =
    str_contains($index, "if (preview_is_text(\$mime)) {")
    && str_contains($index, "serve_file_range(\$f, 'text/plain; charset=utf-8', 'inline'");

foreach (glob($fixtures.'/*') ?: [] as $f) @unlink($f);
@rmdir($fixtures);


$checks['one predicate names the rule'] = str_contains($index, 'function mime_renders_markup(string $mime): bool');
$checks['the share path uses it'] = str_contains($index, 'if (mime_renders_markup($mime))return \'other\';');
$checks['the authenticated preview uses it too'] = str_contains($index, '&& !mime_renders_markup($mime);');
$checks['SVG is no longer matched by the image/* prefix alone'] =
    !str_contains($index, "\$inline = str_starts_with(\$mime, 'image/') || str_starts_with(\$mime, 'audio/') || \$mime === 'application/pdf' || \$mime === 'text/plain';");

// Content-Length is never interpolated from an unchecked filesize().
$checks['the thumbnail route checks filesize()'] = str_contains($index, '$cachedSize = @filesize($cache);');
$checks['the download route checks filesize()'] = str_contains($index, '$downloadSize = @filesize($f);');
$checks['no unchecked filesize() reaches a header'] =
    !str_contains($index, "header('Content-Length: '.filesize(");

// cleanup
foreach (glob($tmp.'/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
