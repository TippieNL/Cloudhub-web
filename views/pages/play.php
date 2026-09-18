
<?php
/**
 * Standalone Cloud File Hub media player page.
 *
 * @var array|null $mediaFile
 * @var array $config
 * @var string $basePath
 * @var string $assetBase
 * @var string $frontController
 */
$nonce = htmlspecialchars(\CloudHub\Services\Security::cspNonce(), ENT_QUOTES, 'UTF-8');
$front = htmlspecialchars($frontController, ENT_QUOTES, 'UTF-8');
$assets = htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($mediaFile['name'] ?? 'Media Player', ENT_QUOTES, 'UTF-8') ?> · Cloud File Hub</title>
    <link rel="icon" href="<?= $assets ?>/favicon.png">
    <link rel="stylesheet" href="<?= $assets ?>/assets/css/app.css?v=<?= (int)@filemtime(dirname(__DIR__, 2).'/public/assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= $assets ?>/assets/css/player.css?v=<?= (int)@filemtime(dirname(__DIR__, 2).'/public/assets/css/player.css') ?>">

    <style>
        html, body.player-page {
            width: 100%;
            height: 100dvh;
            margin: 0;
            overflow: hidden;
            background: #000;
        }

        body.player-page {
            overscroll-behavior: none;
            -webkit-overflow-scrolling: auto;
        }

        body.player-page #cfh-player-container {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100dvh;
            min-height: 100dvh;
            max-height: 100dvh;
        }

        body.player-page #cfh-bottom-overlay {
            padding-bottom: calc(14px + env(safe-area-inset-bottom));
        }

        body.player-page #cfh-bottom-controls {
            min-height: 56px;
            flex-wrap: wrap;
            row-gap: 8px;
        }

        body.player-page .cfh-controls-left,
        body.player-page .cfh-controls-right {
            flex-wrap: wrap;
        }

        body.player-page .cfh-time-display {
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            body.player-page #cfh-bottom-overlay {
                padding: 10px 16px calc(12px + env(safe-area-inset-bottom));
                gap: 8px;
            }

            body.player-page #cfh-bottom-controls {
                min-height: 48px;
            }

            body.player-page .cfh-controls-left,
            body.player-page .cfh-controls-right {
                gap: 6px;
            }
        }
    </style>

</head>
<body class="player-page">
    <?php if (isset($mediaFile)): ?>
        <?php include __DIR__ . '/player_component.php'; ?>
    <?php else: ?>
        <main style="padding:24px;max-width:720px;margin:0 auto;">
            <h1>Media not available</h1>
            <p>The requested file could not be opened as video or audio.</p>
            <p><a href="<?= $front ?>">Return to files</a></p>
        </main>
    <?php endif; ?>

    <?php
    /*
     * The player is a module graph three levels deep: the browser cannot see
     * PlayerUI's twelve imports until PlayerUI itself has downloaded and
     * parsed, and VTTParser only after SubtitleManager does. Preloading the
     * dependencies starts every request in the same round trip; the graph
     * itself is unchanged, so this is a hint and nothing depends on it.
     */
    ?>
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/PlayerSettings.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/SubtitleManager.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/ThumbnailProvider.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/ThumbnailPreview.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/SeekBar.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/KeyboardShortcuts.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/TouchGestures.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/VolumeManager.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/PlaybackSpeed.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/PictureInPicture.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/FullscreenManager.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/Overlay.js">
    <link rel="modulepreload" href="<?= $assets ?>/assets/js/player/VTTParser.js">
    <script type="module" nonce="<?= $nonce ?>" src="<?= $assets ?>/assets/js/player/PlayerUI.js"></script>
    <script nonce="<?= $nonce ?>">
        window.CLOUDHUB_BASE = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;
        window.CLOUDHUB_FRONT = <?= json_encode($frontController, JSON_UNESCAPED_SLASHES) ?>;
        window.CLOUDHUB_ROUTE = <?= json_encode($path, JSON_UNESCAPED_SLASHES) ?>;
        <?php
        /*
         * So a failure can name the codec and offer a way out of it, and so the
         * subtitle menu is right the first time it is opened.
         *
         * JSON_HEX_TAG matters here: both the file's name and the subtitle
         * labels built from filenames end up in this block, and a file named to
         * contain "</script>" would otherwise end it early.
         */
        ?>
        window.CLOUDHUB_MEDIA = <?= json_encode([
            'codec' => $mediaFile['codec'] ?? null,
            'codecWidelySupported' => $mediaFile['codecWidelySupported'] ?? null,
            'downloadUrl' => $mediaFile['download_url'] ?? null,
            'name' => $mediaFile['name'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.CLOUDHUB_SUBTITLES = <?= json_encode($mediaFile['subtitles'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</body>
</html>
