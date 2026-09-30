<?php
declare(strict_types=1);

/**
 * The browser client reports failures instead of misreading them.
 *
 * Checked in Chromium against a live install (the original app.js failed the
 * first three, and passed the delegation checks, which pin that behaviour
 * held):
 *
 *   - a ZIP the server refused vanished as an unhandled rejection: the button
 *     appeared to do nothing;
 *   - any error while opening the first page -- a 500 listing the root --
 *     landed in the sign-in check's catch and showed the sign-in form to
 *     somebody who was signed in;
 *   - the storage-server screens had no error handling at all;
 *   - every render attached six or seven listeners to each card, some 20,000
 *     on a 3,200-file folder, after a document-wide query for each kind.
 *
 * CI has no browser, so this holds the shape of those fixes in place.
 */
$root = dirname(__DIR__);
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$play = (string)file_get_contents($root.'/views/pages/play.php');
$appView = (string)file_get_contents($root.'/views/pages/app.php');
$checks = [];

/** Lift one function out of app.js, braces balanced. */
function extract_js51(string $source, string $signature): string {
    $start = strpos($source, $signature);
    if ($start === false) return '';
    $open = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $open, $n = strlen($source); $i < $n; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') { $depth--; if ($depth === 0) return substr($source, $start, $i-$start+1); }
    }
    return '';
}

// The ZIP built while the browser waits moved into its own function when
// large selections began going through background tasks.
$zip = extract_js51($app, 'async function downloadZipNow(paths)');
$checks['a refused ZIP is reported'] = str_contains($zip, 'try {') && str_contains($zip, "} catch (e) {\n        toast(e.message);");

$boot = substr($app, (int)strrpos($app, '(async () => {'));
$checks['the first page opens through openRoute()'] = str_contains($boot, 'await openRoute();') && !str_contains($boot, 'await route();');
$checks['so only the sign-in check can show the sign-in form'] =
    str_contains(extract_js51($app, 'async function openRoute()'), 'toast(e.message);')
    && substr_count($boot, "\$('#login').style.display = 'flex';") === 1;
$checks['signing in opens the page the same way'] = str_contains(extract_js51($app, 'async function login(u, p)'), 'await openRoute();');

$servers = extract_js51($app, 'async function servers()');
$checks['the server list reports a failure'] = str_contains($servers, '} catch (e) {') && str_contains($servers, 'toast(e.message);');
$checks['server types are escaped'] = str_contains($servers, '${esc(String(s.type))}') && !str_contains($app, '${s.type}');

$render = extract_js51($app, 'function renderFiles()');
$checks['renderFiles() attaches no per-card listeners'] = $render !== '' && !str_contains($render, 'addEventListener');
$checks['the list listens once, for every kind'] =
    str_contains($app, "const fileList = \$('#file-list');")
    && str_contains($app, "fileList.addEventListener('click', e => {")
    && str_contains($app, "fileList.addEventListener('change', e => {")
    && str_contains($app, "fileList.addEventListener('contextmenu', e => {")
    && str_contains($app, "fileList.addEventListener('dblclick', e => {")
    && str_contains($app, "fileList.addEventListener('error', e => {");
$checks['a thumbnail error is caught on the way down, since it does not bubble'] = (bool)preg_match("/fileList\.addEventListener\('error', e => \{.*?\n\}, true\);/s", $app);

$checks['inline bootstrap JSON cannot end its script'] =
    substr_count($play, 'JSON_HEX_TAG') >= 4 && substr_count($appView, 'JSON_HEX_TAG') >= 4
    && !str_contains($play, 'JSON_UNESCAPED_SLASHES) ?>') && !str_contains($appView, 'JSON_UNESCAPED_SLASHES) ?>');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
