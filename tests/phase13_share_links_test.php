<?php
declare(strict_types=1);

/**
 * Public share links.
 *
 * A share token is the only credential a recipient has, so the guarantees that
 * matter are: the routes sit outside the authenticated guard, expiry and
 * revocation are enforced on every variant, media streams with byte ranges so
 * shared video can be seeked, and nothing script-capable is ever served inline
 * from this origin.
 *
 * Route behaviour is asserted against the source: exercising it live needs
 * MySQL, which this environment has no server for.
 */
$root = dirname(__DIR__);
$index = (string)file_get_contents($root.'/public/index.php');
$view = (string)file_get_contents($root.'/views/pages/share.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$appView = (string)file_get_contents($root.'/views/pages/app.php');

$checks = [];

// --- public reachability -------------------------------------------------
// The guard protects /api/ and /webdav only; /share/ must stay outside it.
$checks['share routes are outside the authenticated guard'] =
    str_contains($index, "\$isProtectedApi = (str_starts_with(\$path, '/api/')&&!\$isAuthEndpoint) || str_starts_with(\$path, '/webdav')")
    && !str_contains($index, "str_starts_with(\$path, '/share')&&\$isProtectedApi");
$checks['viewer, raw and download variants are routed'] =
    (bool)preg_match('#\^/share/\(\[A-Za-z0-9_-\]\{20,128\}\)\(\?:/\(raw\|download\)\)\?\$#', $index);
$checks['anonymous viewers get no session'] =
    str_contains($index, '$isPublicShare') && str_contains($index, 'if (!$isPublicShare) Auth::startSession($config);');

// --- token, expiry, revocation ------------------------------------------
$checks['tokens are 256 bits of randomness'] = str_contains($index, 'random_bytes(32)');
$checks['expiry is enforced in one place'] =
    str_contains($index, 'function share_resolve(') && str_contains($index, "throw new RuntimeException('Share link has expired', 410)");
$checks['every share route resolves through share_resolve'] =
    substr_count($index, 'share_resolve($fs, $m[1])') === 1;
$checks['a missing token is a 404'] = str_contains($index, "'Share link not found or expired', 404");
$checks['revoke deletes the token'] = str_contains($index, 'DELETE FROM share_links WHERE token=?');

// --- media delivery ------------------------------------------------------
$checks['shared bytes are range-streamed'] =
    (bool)preg_match('/serve_file_range\(\$file, media_mime_type\(\$file\)/', $index);
$checks['range helper advertises byte ranges'] =
    str_contains($index, "header('Accept-Ranges: bytes')") && str_contains($index, '$status = 206');
$checks['range helper answers 416 when unsatisfiable'] = str_contains($index, 'http_response_code(416)');
$checks['image extensions resolve without libmagic'] =
    str_contains($index, "'gif' => 'image/gif'") && str_contains($index, "'png' => 'image/png'");

// --- what may render inline ---------------------------------------------
$checks['only image, video and audio render inline'] =
    str_contains($index, 'function share_media_kind(') && str_contains($index, "return 'image'")
    && str_contains($index, "return 'video'") && str_contains($index, "return 'audio'");
$checks['svg is never treated as inline media'] =
    (bool)preg_match("/if \(\\\$mime === 'image\/svg\\+xml'\)return 'other';/", $index);
$checks['non-media is served as an attachment'] =
    str_contains($index, "\$disposition = (\$variant === 'raw' && \$kind !== 'other')?'inline':'attachment';");
$checks['raw bytes keep the sandbox CSP'] =
    (bool)preg_match("/default-src 'none'; sandbox;.*script-src 'none'/", $index);
$checks['shared links are not indexable'] = str_contains($index, 'X-Robots-Tag: noindex, nofollow');

// --- the public page -----------------------------------------------------
$checks['viewer never loads the application bundle'] =
    !str_contains($view, 'assets/js/app.js') && !str_contains($view, 'CLOUDHUB_UPLOAD_LIMITS');
$checks['viewer escapes the file name'] = str_contains($view, 'htmlspecialchars((string)$shareFile[\'name\']');
$checks['viewer exposes only the basename'] =
    str_contains($index, "'name' => basename(\$file)") && !str_contains($view, '$shareFile[\'path\']');
$checks['viewer script carries the CSP nonce'] = str_contains($view, '<script nonce="<?= $nonce ?>">');
$checks['viewer offers a download'] = str_contains($view, 'share-btn-primary') && str_contains($view, 'downloadUrl');

// --- in-app dialog -------------------------------------------------------
$checks['share dialog markup exists'] = str_contains($appView, 'id="share-overlay"') && str_contains($appView, 'id="share-url"');
$checks['dialog offers copy and revoke'] = str_contains($app, "shareUI.copy.addEventListener") && str_contains($app, "shareUI.revoke.addEventListener");
$checks['dialog opens without imposing a lifetime'] =
    str_contains($app, "if (hours !== undefined) body.expiresInHours = hours;");
$checks['confirmation sits above other modals'] =
    str_contains((string)file_get_contents($root.'/public/assets/css/app.css'), '#confirm-overlay,#input-overlay{z-index:40}');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
