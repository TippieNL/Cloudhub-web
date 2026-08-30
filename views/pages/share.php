<?php
/**
 * Public share viewer.
 *
 * Rendered for visitors who are not logged in, so it must not reference the
 * application shell, its JavaScript, or any authenticated endpoint. Everything
 * it needs arrives in $shareFile; the bytes come from the separate /raw route.
 *
 * @var array  $shareFile  name, kind, size, mime, rawUrl, downloadUrl, pageUrl, expiresAt
 * @var array  $config
 * @var string $basePath
 * @var string $assetBase
 */
$nonce = htmlspecialchars(\CloudHub\Services\Security::cspNonce(), ENT_QUOTES, 'UTF-8');
$assets = htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8');
$name = htmlspecialchars((string)$shareFile['name'], ENT_QUOTES, 'UTF-8');
$kind = (string)$shareFile['kind'];
$mime = htmlspecialchars((string)$shareFile['mime'], ENT_QUOTES, 'UTF-8');
$rawUrl = htmlspecialchars((string)$shareFile['rawUrl'], ENT_QUOTES, 'UTF-8');
$downloadUrl = htmlspecialchars((string)$shareFile['downloadUrl'], ENT_QUOTES, 'UTF-8');
$pageUrl = htmlspecialchars((string)$shareFile['pageUrl'], ENT_QUOTES, 'UTF-8');

$bytes = (int)$shareFile['size'];
$units = ['B', 'KB', 'MB', 'GB', 'TB'];
$unit = 0;
$value = (float)$bytes;
while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
$size = htmlspecialchars(($unit === 0 ? (string)$bytes : number_format($value, 1)).' '.$units[$unit], ENT_QUOTES, 'UTF-8');

$expiresLabel = '';
if (!empty($shareFile['expiresAt'])) {
    $ts = strtotime((string)$shareFile['expiresAt']);
    if ($ts) $expiresLabel = htmlspecialchars(gmdate('j M Y, H:i', $ts).' UTC', ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $name ?></title>
    <link rel="icon" href="<?= $assets ?>/favicon.png">
    <link rel="stylesheet" href="<?= $assets ?>/assets/css/share.css?v=<?= (int)@filemtime(dirname(__DIR__, 2).'/public/assets/css/share.css') ?>">

    <!-- Link previews in chat clients. Only the file name is exposed. -->
    <meta property="og:title" content="<?= $name ?>">
    <meta property="og:type" content="<?= $kind === 'video' ? 'video.other' : 'website' ?>">
    <meta property="og:url" content="<?= $pageUrl ?>">
    <meta property="og:site_name" content="Cloud File Hub">
    <?php if ($kind === 'image'): ?>
    <meta property="og:image" content="<?= $pageUrl ?>/raw">
    <meta name="twitter:card" content="summary_large_image">
    <?php elseif ($kind === 'video'): ?>
    <meta property="og:video" content="<?= $pageUrl ?>/raw">
    <meta property="og:video:type" content="<?= $mime ?>">
    <meta name="twitter:card" content="player">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
</head>
<body class="share-page">
    <main class="share-shell">
        <div class="share-stage share-stage-<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($kind === 'image'): ?>
                <img class="share-media" src="<?= $rawUrl ?>" alt="<?= $name ?>" decoding="async">
            <?php elseif ($kind === 'video'): ?>
                <video class="share-media" src="<?= $rawUrl ?>" controls playsinline preload="metadata"
                       controlslist="nodownload" poster="">
                    Your browser cannot play this video.
                    <a href="<?= $downloadUrl ?>">Download it instead</a>.
                </video>
            <?php elseif ($kind === 'audio'): ?>
                <div class="share-audio">
                    <div class="share-audio-icon" aria-hidden="true">&#9835;</div>
                    <audio class="share-media" src="<?= $rawUrl ?>" controls preload="metadata">
                        Your browser cannot play this audio.
                        <a href="<?= $downloadUrl ?>">Download it instead</a>.
                    </audio>
                </div>
            <?php endif; ?>
        </div>

        <footer class="share-bar">
            <div class="share-meta">
                <h1 class="share-name" title="<?= $name ?>"><?= $name ?></h1>
                <p class="share-sub">
                    <span><?= $size ?></span>
                    <?php if ($expiresLabel !== ''): ?>
                        <span class="share-dot" aria-hidden="true">&middot;</span>
                        <span>Link expires <?= $expiresLabel ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="share-actions">
                <button type="button" class="share-btn" id="share-copy" data-url="<?= $pageUrl ?>">Copy link</button>
                <a class="share-btn share-btn-primary" href="<?= $downloadUrl ?>" download>Download</a>
            </div>
        </footer>
    </main>

    <script nonce="<?= $nonce ?>">
        // Progressive enhancement only: the download link and the media element
        // both work with JavaScript disabled.
        (function () {
            var button = document.getElementById('share-copy');
            if (!button) return;
            button.addEventListener('click', function () {
                var url = button.dataset.url || location.href;
                var done = function () {
                    var original = button.textContent;
                    button.textContent = 'Copied';
                    setTimeout(function () { button.textContent = original; }, 1600);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link:', url); });
                } else {
                    window.prompt('Copy this link:', url);
                }
            });
        })();
    </script>
</body>
</html>
