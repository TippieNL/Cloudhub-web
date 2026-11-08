<?php
declare(strict_types=1);

/**
 * Opening a page never shows the sign-in form to someone who is signed in.
 *
 * Every page of the app is a full page load. The sign-in form was the page's
 * default, visible until the script had asked /api/auth/status who was signed
 * in, so it flashed up on every click of the menu. The server renders the
 * page knowing the answer: a signed-in visitor gets a loading screen, which
 * the script removes once the page has its content, and only a visitor who
 * needs to sign in is shown the form.
 */
$root = dirname(__DIR__);
$markup = (string)file_get_contents($root.'/views/pages/app.php');
$app = (string)file_get_contents($root.'/public/assets/js/app.js');
$css = (string)file_get_contents($root.'/public/assets/css/app.css');
$checks = [];

// --- what the server paints ---------------------------------------------------------
$checks['the page knows who is signed in before it paints'] =
    str_contains($markup, '$signedIn = \\CloudHub\\Services\\Auth::user() !== null;');
$checks['the sign-in form is only painted for a visitor who is not signed in'] =
    str_contains($markup, '<div id="login" class="overlay"<?= $signedIn ? \' style="display:none"\' : \'\' ?>>');
$checks['and the loading screen only for one who is'] =
    str_contains($markup, '<div id="app-loading" class="app-loading" role="status" aria-live="polite"<?= $signedIn ? \'\' : \' hidden\' ?>>');
$checks['it comes before the form and the header, so nothing else paints first'] =
    strpos($markup, 'id="app-loading"') < strpos($markup, 'id="login"') && strpos($markup, 'id="app-loading"') < strpos($markup, '<header>');
$checks['the menu entry for the page is marked while it loads'] = substr_count($markup, '<?= $navActive(') === 8;
$head = substr($markup, (int)strpos($markup, '<head>'), (int)strpos($markup, '</head>') - (int)strpos($markup, '<head>'));
$checks['a dark theme is applied before the first paint, not after the page'] =
    str_contains($head, "localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark')")
    && str_contains($head, 'nonce="<?= htmlspecialchars(\\CloudHub\\Services\\Security::cspNonce(), ENT_QUOTES) ?>"')
    && strpos($head, "classList.add('dark')") < strpos($head, 'app.css');

// --- what the script does with it -----------------------------------------------------
$boot = substr($app, (int)strrpos($app, '(async () => {'));
$checks['a visitor who is not signed in gets the form, and the screen goes'] =
    str_contains($boot, "    if (!d?.authenticated) {\n        \$('#login').style.display = 'flex';\n        hideLoading();\n        return;\n    }");
$checks['a signed-in one gets the page, and the screen goes once it has its content'] =
    strpos($boot, 'await openRoute();') < strpos($boot, "clearTimeout(slow);\n    hideLoading();");
$checks['but never stays more than a few seconds'] = str_contains($boot, 'const slow = setTimeout(hideLoading, 4000);');
$api = substr($app, (int)strpos($app, 'async function api(url, opt = {}) {'), 700);
$checks['a session that ends while the page loads shows the form, not the screen'] =
    str_contains($api, "\$('#login').style.display = 'flex';\n        hideLoading();");
$click = substr($app, (int)strpos($app, "document.addEventListener('click', e => {\n    if (e.defaultPrevented"), 1400);
$checks['a click on a link to another page brings the screen up at once'] = str_contains($click, 'showLoading();');
$checks['but not for a new tab, a download, or a jump within the page'] =
    str_contains($click, "if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;")
    && str_contains($click, 'if (url.hash && url.search === location.search) return;')
    && str_contains($click, 'e.metaKey || e.ctrlKey || e.shiftKey || e.altKey');
$checks['nor for an API answer, which is never a page'] = str_contains($click, ".startsWith('/api/')) return;");
$checks['and a navigation that never happens does not leave it up'] = str_contains($click, 'setTimeout(hideLoading, 8000);')
    && str_contains($app, "window.addEventListener('pageshow', e => { if (e.persisted) hideLoading(); });");

// --- how it looks ---------------------------------------------------------------------
$checks['the screen sits under the header and over the page'] =
    str_contains($css, '.app-loading{position:fixed;inset:0;z-index:4;') && str_contains($css, 'position:sticky;top:0;z-index:5}');
$checks['it is drawn in the theme, and its spinner waits before it appears'] =
    str_contains($css, 'background:var(--bg);transition:opacity .2s ease}') && str_contains($css, 'animation:app-loading-in .25s ease .15s forwards');
$checks['and it holds still for anyone who asked for less motion'] =
    str_contains($css, '@media(prefers-reduced-motion:reduce){.app-loading-ring{animation:none;');

$bad = false;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.PHP_EOL;
    $bad = $bad || !$ok;
}
exit($bad ? 1 : 0);
