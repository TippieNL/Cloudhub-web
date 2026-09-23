<?php
declare(strict_types=1);

/**
 * Downloads are streamed by the browser, not buffered in the page.
 *
 * download() fetched the whole file into a Blob before saving a byte -- up to
 * the upload limit, which is where a phone's tab gave up on a large video --
 * and revoked the Blob's URL straight after click(), which could cancel the
 * save in Firefox and Safari. It now asks with HEAD, so errors are reported
 * rather than saved as a "file" holding JSON, and hands the URL to the
 * browser's download manager; the name travels in filename* so it survives
 * outside ASCII.
 *
 * Checked by hand in Chromium against both builds, including a subdirectory
 * install under Apache and (Cloudhub-2) a kept file with the network cut; the
 * HEAD route itself is exercised over HTTP by tests/http/run.php.
 */
$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$checks = [];

/** Lift one top-level function out of a source file, braces balanced. */
function extract_function40(string $source, string $name): string {
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

// --- the header that names the file ------------------------------------------
$fn = extract_function40($index, 'content_disposition');
$checks['content_disposition() could be lifted from index.php'] = $fn !== '';
if ($fn !== '') {
    eval($fn);
    $value = content_disposition('attachment', 'Überweisung "Q3"\\写真.pdf');
    $checks['the real name travels in filename*'] = str_contains($value, "filename*=UTF-8''".rawurlencode('Überweisung "Q3"\\写真.pdf'));
    $checks['the plain form cannot break out of its quotes'] =
        (bool)preg_match('/^attachment; filename="[^"\\\\]*"; filename\*=/', $value);
    $checks['nor carry a header break'] = !preg_match('/[\r\n]/', content_disposition('attachment', "a\r\nSet-Cookie: x.pdf"));
}
$checks['every attachment is named through it'] =
    substr_count($index, "content_disposition('attachment', basename(") >= 1
    && str_contains($index, "header('Content-Disposition: '.content_disposition(\$disposition, basename(\$file)));");

// --- the download route answers HEAD without reading the file ---------------
$checks['the download route answers HEAD'] =
    str_contains($index, "\$path === '/api/files/download' && (\$method === 'GET' || \$method === 'HEAD')");
$checks['and only a GET reads the file'] = str_contains($index, "if (\$method === 'GET')readfile(\$f); exit;");

// --- the client ----------------------------------------------------------------
$download = extract_function40($app, 'download');
// The only Blob is an offline copy already in the browser (Cloudhub-2).
$checks['a single file is never read into the page'] = $download !== ''
    && substr_count($download, '.blob()') === substr_count($download, 'kept.blob()');
$checks['it asks with HEAD, then lets the browser save it'] =
    str_contains($app, "await api(url, { method: 'HEAD' });") && str_contains($app, 'clickDownload(appUrl(url), name);');
$checks['a Blob URL outlives the click'] = str_contains($app, 'setTimeout(() => URL.revokeObjectURL(url), 60000);')
    && !preg_match('/a\.click\(\);\s*URL\.revokeObjectURL/', $app);
$checks['a failed download is reported, not swallowed'] = str_contains($download, 'toast(error.message);');
if (is_file($root.'/public/assets/js/sw.js')) {
    // Cloudhub-2 keeps files for offline use; those still save with no network.
    $checks['a kept file is saved from the offline cache'] =
        str_contains($download, "caches.match(appUrl(url), { ignoreVary: true })") && str_contains($download, 'saveBlob(await kept.blob(), name);');
}

$bad = false;
foreach ($checks as $name => $ok) { echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL; $bad = $bad || !$ok; }
exit($bad ? 1 : 0);
