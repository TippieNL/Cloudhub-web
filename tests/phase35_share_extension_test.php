<?php
declare(strict_types=1);

/**
 * The file extension a share link now carries.
 *
 * `…/share/TOKEN` reads as `…/share/TOKEN.png`. The suffix is decoration: it
 * makes a pasted link legible as what it points at, and nothing on the way back
 * in consults it. Two properties therefore matter more than the cosmetics, and
 * both are asserted here against the real functions and the real patterns
 * rather than against the shape of the diff:
 *
 *  - what is served is still decided from the stored file, so a link edited to
 *    ".html" cannot talk the server into a Content-Type it chose;
 *  - the pattern that decides which URLs run with no session at all has widened
 *    by exactly the suffix and by nothing else.
 */

$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$view = (string)file_get_contents($root.'/views/pages/share.php');
$checks = [];

/** Lift one top-level function out of a PHP file, braces balanced. */
function extract_function35(string $source, string $name): string {
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

// The functions under test, run for real. Extracting them keeps this a test of
// the shipped code without booting the front controller, which would need a
// database and a session.
require_once $root.'/src/Services/Security.php';
$lifted = '';
foreach (['mime_type', 'media_mime_type', 'mime_renders_markup', 'share_media_kind',
          'public_origin', 'share_url', 'share_url_suffix'] as $name) {
    $fn = extract_function35($index, $name);
    $checks["$name() could be lifted from index.php"] = $fn !== '';
    $lifted .= $fn."\n";
}
eval('use CloudHub\Services\Security;'."\n".$lifted);

// --- the suffix a filename may contribute --------------------------------

// A name shapes a URL other people paste, so only a plausible extension is
// allowed to reach it. Everything else contributes nothing at all rather than
// something surprising.
$suffixes = [
    'photo.png' => '.png',
    'clip.MP4' => '.mp4',            // normalised, so one file has one URL
    'archive.tar.gz' => '.gz',       // the last component only
    'README' => '',                  // no extension, no suffix
    'trailing.' => '',
    '.hidden' => '',                 // a dotfile has a name, not an extension
    '.hidden.png' => '.png',         // but it can still have one
    'weird.p h p' => '',
    'weird.php;' => '',
    'weird.a/b' => '',
    // A null byte cannot survive in a path PHP will open, and in any case
    // stops at the last dot: what reaches the URL is the clean extension.
    "weird.png\0.html" => '.html',
    'weird.'.str_repeat('a', 11) => '',   // one over the ten-character bound
    'weird.'.str_repeat('a', 10) => '.'.str_repeat('a', 10),
    'weird.'.str_repeat('a', 40) => '',
    'weird.%2e%2e' => '',
    'weird.pn g' => '',
    'weird.pn-g' => '',
];
$suffixOk = true;
foreach ($suffixes as $name => $want) {
    $got = share_url_suffix($name);
    if ($got !== $want) { $suffixOk = false; echo '  suffix('.addcslashes($name, "\0..\37").') = '.var_export($got, true).', want '.var_export($want, true).PHP_EOL; }
}
$checks['only a plausible extension reaches the URL'] = $suffixOk;

// --- the URL that is handed out ------------------------------------------

$_SERVER['HTTP_HOST'] = 'files.example';
unset($_SERVER['HTTPS']);
$token = str_repeat('A', 43);
$checks['a share URL carries the extension'] =
    share_url([], '/Cloud File Hub', $token, '/photos/holiday.png')
        === 'http://files.example/Cloud File Hub/share/'.$token.'.png';
$checks['a file without one still gets a working URL'] =
    share_url([], '', $token, '/notes/README') === 'http://files.example/share/'.$token;
$checks['the file is optional, as older callers left it'] =
    share_url([], '', $token) === 'http://files.example/share/'.$token;

// --- what comes back in --------------------------------------------------

/** The two share patterns, taken from the shipped source rather than retyped. */
function share_patterns35(string $index): array {
    preg_match_all("/preg_match\('(#\^\/share\/[^']*#)'/", $index, $m);
    return $m[1];
}
$patterns = share_patterns35($index);
$checks['both share patterns were found in index.php'] = count($patterns) === 2;
[$gate, $route] = $patterns + ['', ''];

// Every spelling of a share URL resolves, and the capture is always the bare
// token -- share_resolve() is handed exactly what it was handed before.
$accepted = [
    '/share/'.$token,
    '/share/'.$token.'.png',
    '/share/'.$token.'/raw',
    '/share/'.$token.'/download',
    '/share/'.$token.'/raw.png',
    '/share/'.$token.'.png/raw',
    '/share/'.$token.'.png/download.png',
];
$routeOk = $gateOk = true;
foreach ($accepted as $path) {
    if (preg_match($route, $path, $m) !== 1 || ($m[1] ?? '') !== $token) { $routeOk = false; echo '  route rejected or miscaptured: '.$path.PHP_EOL; }
    if (preg_match($gate, $path) !== 1) { $gateOk = false; echo '  gate rejected: '.$path.PHP_EOL; }
}
$checks['every spelling of a share URL is routed'] = $routeOk;
$checks['the capture is always the bare token'] = $routeOk;
$checks['the public gate accepts the same set'] = $gateOk;

// Links already in circulation predate the suffix, so the bare form matters
// separately from the rest.
$checks['links without a suffix still resolve'] =
    preg_match($route, '/share/'.$token, $m) === 1 && $m[1] === $token;

// --- the gate has not widened beyond the suffix --------------------------

// This pattern decides which URLs run with no session and no authentication,
// so what it refuses is the security-relevant half.
$refused = [
    '/share/short'                              => 'a token below the length floor',
    '/share/'.$token.'x'.str_repeat('y', 200)   => 'a token above the length ceiling',
    '/share/'.$token.'/../admin'                => 'traversal after the token',
    '/share/'.$token.'.png/x'                   => 'an unknown variant',
    '/share/'.$token.'/raw/raw'                 => 'a doubled variant',
    '/share/'.$token.'.'.str_repeat('a', 11)    => 'an eleven-character suffix',
    '/share/'.$token.'..png'                    => 'a doubled dot',
    '/share/'.$token.'.pn g'                    => 'a space in the suffix',
    '/share/'.$token.'.png/'                    => 'a trailing slash',
    '/share/'.$token.'/raw?x=1'                 => 'a query string in the path',
    '/api/files/list'                           => 'an authenticated endpoint',
    '/share/'.$token.'/../../api/files/list'    => 'an authenticated endpoint reached sideways',
    '/shareX/'.$token                           => 'a lookalike prefix',
    '/share/'.$token."\n/raw"                   => 'a newline before the variant',
];
$refusedOk = true;
foreach ($refused as $path => $why) {
    if (preg_match($gate, $path) === 1) { $refusedOk = false; echo '  gate wrongly accepts '.$why.': '.addcslashes($path, "\0..\37").PHP_EOL; }
}
$checks['the set of session-less URLs is unchanged apart from the suffix'] = $refusedOk;
$checks['the gate is anchored at both ends'] =
    str_starts_with($gate, '#^/share/') && str_ends_with($gate, '$#');

// --- the suffix never decides what is served -----------------------------

$tmp = sys_get_temp_dir().'/cloudhub-p35-'.bin2hex(random_bytes(5));
mkdir($tmp, 0775, true);
// A real PNG, one pixel.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($tmp.'/pixel.png', $png);
file_put_contents($tmp.'/page.html', '<h1>hi</h1>');

// The route resolves a token to a path and types that path. Reaching it through
// a ".html" suffix changes the URL and nothing else.
$checks['a png reached through a .html suffix is still a png'] =
    preg_match($route, '/share/'.$token.'.html', $m) === 1
    && media_mime_type($tmp.'/pixel.png') === 'image/png'
    && share_media_kind($tmp.'/pixel.png') === 'image';
// And the converse: an html file dressed as .png is still refused inline.
$checks['markup dressed as .png is still not inline media'] =
    preg_match($route, '/share/'.$token.'.png', $m) === 1
    && share_media_kind($tmp.'/page.html') === 'other';

@unlink($tmp.'/pixel.png'); @unlink($tmp.'/page.html'); @rmdir($tmp);

// Structural, because the above can only show that typing from the file gives
// the right answer -- not that the route asks the file rather than the URL.
$routeBody = substr($index, (int)strpos($index, "preg_match('".$route."'"));
$routeBody = substr($routeBody, 0, (int)strpos($routeBody, "\n    if (\$path === '/api/thumbnail'"));
$checks['the route types from the file, never from the URL'] =
    str_contains($routeBody, 'share_media_kind($file)')
    && str_contains($routeBody, 'media_mime_type($file)')
    // $m[] is read for the token and the variant only; nothing derives a type
    // from it.
    && preg_match_all('/\$m\[/', $routeBody) === 2;
$checks['the served bytes still carry nosniff and the sandbox CSP'] =
    str_contains($index, "header('X-Content-Type-Options: nosniff')")
    && str_contains($routeBody, "default-src 'none'; sandbox;");

// --- one address, spelled one way ----------------------------------------

/*
 * og:image and og:video were written by appending "/raw" to the page URL,
 * re-deriving an address the payload already carries as rawUrl. With a suffix
 * on the page URL that spells one address two ways.
 */
$checks['link previews use the payload rawUrl'] =
    str_contains($view, '<meta property="og:image" content="<?= $rawUrl ?>">')
    && str_contains($view, '<meta property="og:video" content="<?= $rawUrl ?>">')
    && !str_contains($view, '<?= $pageUrl ?>/raw');
$checks['the payload builds raw and download with the same suffix'] =
    str_contains($index, "'rawUrl' => \$bytesUrl.'/raw'.\$suffix")
    && str_contains($index, "'downloadUrl' => \$bytesUrl.'/download'.\$suffix")
    && str_contains($index, "\$suffix = share_url_suffix(\$file);");
// og:image and og:video are only honoured as absolute URLs, and until now they
// were absolute by borrowing the page URL. Taking them from the payload instead
// only works if the payload's URLs are absolute too.
$checks['the media URLs in the payload are absolute'] =
    str_contains($index, "\$bytesUrl = public_origin(\$config).\$basePath.'/share/'.\$share['token'];");
// The three places that hand a URL to a person all pass the file, so a link is
// the same wherever it is copied from.
$checks['every caller of share_url passes the file'] =
    substr_count($index, 'share_url($config, $basePath, ') === 3
    && substr_count($index, 'share_url($config, $basePath, (string)$r[\'token\'], $rel)') === 1
    && substr_count($index, 'share_url($config, $basePath, (string)$x[\'token\'], (string)$x[\'file_path\'])') === 1
    && substr_count($index, 'share_url($config, $basePath, (string)$share[\'token\'], $file)') === 1;

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
