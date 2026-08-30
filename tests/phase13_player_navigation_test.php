<?php
declare(strict_types=1);

/**
 * Leaving the video player.
 *
 * Two defects made "Back" appear broken while a video was playing:
 *
 *  1. goBack() gated history.back() on a same-origin document.referrer, but the
 *     application sends `Referrer-Policy: no-referrer`, so the referrer is
 *     always empty and that branch was unreachable. Every press fell through to
 *     location.assign(), which pushed a history entry instead of popping one:
 *     the browser's Back button then landed straight back on the player.
 *
 *  2. KeyboardShortcuts called preventDefault() on ArrowLeft/ArrowRight without
 *     inspecting modifiers, swallowing Alt+Left and Cmd+Left -- the browser's
 *     Back shortcut -- and seeking the video instead.
 *
 * Browser behaviour is covered separately; these assertions pin the source so
 * neither defect can quietly return.
 */
$root = dirname(__DIR__);
$player = (string)file_get_contents($root.'/public/assets/js/player/PlayerUI.js');
$keys = (string)file_get_contents($root.'/public/assets/js/player/KeyboardShortcuts.js');
$security = (string)file_get_contents($root.'/src/Services/Security.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');

$checks = [];

// The premise: the referrer really is unavailable, so nothing may depend on it.
$checks['the app still sends Referrer-Policy: no-referrer'] =
    str_contains($security, "header('Referrer-Policy: no-referrer')");
// Check executable code, not prose: the fix is explained in a comment that
// necessarily names document.referrer.
$playerCode = preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], '', $player);
$checks['goBack does not depend on document.referrer'] =
    !str_contains((string)$playerCode, 'document.referrer');

// Leaving must pop history, and must never stack the player behind the app.
$checks['goBack prefers history.back()'] = str_contains($player, 'window.history.back();');
$checks['goBack falls back with replace(), not assign()'] =
    str_contains($player, 'window.location.replace(front)') && !str_contains($player, 'window.location.assign(front)');
$checks['the fallback is guarded so it cannot double-navigate'] =
    str_contains($player, "window.addEventListener('pagehide', onLeave") && str_contains($player, 'if (!leaving)');

// Browser and OS shortcuts must reach the browser.
$checks['player shortcuts ignore alt/ctrl/meta'] =
    (bool)preg_match('/if \(event\.altKey \|\| event\.ctrlKey \|\| event\.metaKey\) return;/', $keys);
$checks['the modifier guard runs before any preventDefault'] = (function () use ($keys): bool {
    $guard = strpos($keys, 'event.altKey || event.ctrlKey || event.metaKey');
    $first = strpos($keys, 'event.preventDefault()');
    return $guard !== false && $first !== false && $guard < $first;
})();
$checks['unmodified keys still drive the player'] =
    str_contains($keys, "case 'ArrowLeft':") && str_contains($keys, 'seek(-5');

// Back should return the user to the folder they were browsing.
$checks['the open folder is remembered per tab'] =
    str_contains($app, "sessionStorage.getItem('cfh_path')") && str_contains($app, "sessionStorage.setItem('cfh_path', p)");
$checks['a vanished folder falls back to the root'] =
    str_contains($app, "That folder is no longer available") && str_contains($app, "response = await api('/api/files/list?path=%2F')");
$checks['storage failures do not break the listing'] =
    (bool)preg_match('/try \{ sessionStorage\.setItem\(.cfh_path., p\); \} catch \{\}/', $app);

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
