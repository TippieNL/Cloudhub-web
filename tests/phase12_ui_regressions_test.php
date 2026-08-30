<?php
declare(strict_types=1);

/**
 * Three user-visible regressions that no other test covers.
 */
$root = dirname(__DIR__);
$security = (string)file_get_contents($root.'/src/Services/Security.php');
$index = (string)file_get_contents($root.'/public/index.php');
$player = (string)file_get_contents($root.'/views/pages/player_component.php');
$playerCss = (string)file_get_contents($root.'/public/assets/css/player.css');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');

$checks = [];

// M1: the preview dialog frames PDFs from the same origin, so DENY /
// frame-ancestors 'none' left the PDF pane permanently empty.
$checks['preview still uses an iframe for PDFs'] = str_contains($app, 'class="preview-frame"');
$checks['X-Frame-Options allows same-origin framing'] =
    str_contains($security, 'X-Frame-Options: SAMEORIGIN') && !str_contains($security, 'X-Frame-Options: DENY');
$checks['CSP allows same-origin framing'] =
    str_contains($security, "frame-ancestors 'self'") && !str_contains($security, "frame-ancestors 'none'");
$checks['cross-origin framing is still refused'] =
    !str_contains($security, 'frame-ancestors *') && !str_contains($security, 'ALLOWALL');

// M2: the player swaps icons with the hidden attribute, which an inline
// display:none silently outranks -- those buttons rendered empty once toggled.
$checks['player icons carry no inline display:none'] = !str_contains($player, 'style="display:none;"');
foreach (['cfh-icon-pause', 'cfh-icon-vol-mute', 'cfh-icon-fs-exit'] as $icon) {
    $checks["$icon starts hidden via the attribute"] =
        (bool)preg_match('/class="'.preg_quote($icon, '/').'"[^>]*\shidden\b/', $player);
}
$checks['player.css hides svg[hidden] inside buttons'] = str_contains($playerCss, '.cfh-btn svg[hidden]');

// M4: download-zip is a read-only POST; requiring the write capability gave
// viewers a 403 on bulk download while single downloads worked.
// Assert membership rather than the exact array literal: other read-only POST
// routes are legitimately added to this list over time.
preg_match('/\$writeExemptPost = \[(.*?)\];/', $index, $exempt);
$checks['download-zip is exempt from the write check'] =
    isset($exempt[1]) && str_contains($exempt[1], "'/api/files/download-zip'");
$checks['download-zip still verifies CSRF'] =
    (bool)preg_match('/Auth::verifyCsrf\(\);.*?\$writeExemptPost|\$writeExemptPost.*?Auth::verifyCsrf\(\);/s', $index);
$checks['mutating routes still require write'] = str_contains($index, 'Authorization::requireWrite()');
$checks['server routes still require admin'] = str_contains($index, "str_starts_with(\$path, '/api/servers'))Authorization::requireAdmin()");

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
