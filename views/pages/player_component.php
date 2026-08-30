<?php
/**
 * Cloud File Hub - Custom Media Player Component.
 *
 * Included only by views/pages/play.php. It renders markup and no scripts of
 * its own, so it needs no CSP nonce: play.php carries the module tag.
 *
 * @var array $mediaFile
 */
$fileName = htmlspecialchars($mediaFile['name'] ?? 'Video Playback', ENT_QUOTES, 'UTF-8');
$folderPath = htmlspecialchars(dirname($mediaFile['path'] ?? '/'), ENT_QUOTES, 'UTF-8');
$fileSize = htmlspecialchars($mediaFile['formatted_size'] ?? '', ENT_QUOTES, 'UTF-8');
$streamUrl = htmlspecialchars($mediaFile['stream_url'] ?? '', ENT_QUOTES, 'UTF-8');
$spriteUrl = htmlspecialchars($mediaFile['sprite_url'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div id="cfh-player-container" class="cfh-player-container" tabindex="0" role="region" aria-label="Media Player">
    <video id="cfh-video-element" class="cfh-video-element" src="<?= $streamUrl ?>" data-sprite="<?= $spriteUrl ?>" playsinline webkit-playsinline preload="metadata" aria-label="<?= $fileName ?>"></video>

    <div id="cfh-player-status" class="cfh-player-status" role="status" aria-live="polite" hidden></div>
    
    <!-- Subtitle Render Layer -->
    <div id="cfh-subtitle-display" class="cfh-subtitle-display" aria-live="off"></div>

    <!-- Gesture Visual Feedback Overlay -->
    <div id="cfh-gesture-feedback" class="cfh-gesture-feedback" aria-hidden="true"></div>

    <!-- Top Navigation & Controls Overlay -->
    <div id="cfh-top-overlay" class="cfh-overlay cfh-top-overlay">
        <div class="cfh-top-left">
            <button type="button" class="cfh-btn" id="cfh-btn-back" aria-label="Go Back">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </button>
            <div class="cfh-media-meta">
                <span class="cfh-media-title" id="cfh-media-title"><?= $fileName ?></span>
                <span class="cfh-media-path"><?= $folderPath ?><?php if ($fileSize): ?> • <?= $fileSize ?><?php endif; ?></span>
            </div>
        </div>
        <div class="cfh-top-right">
            <button class="cfh-btn cfh-dropdown-trigger" data-menu="quality" id="cfh-trigger-quality" aria-label="Quality Settings" aria-expanded="false">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15a3 3 0 100-6 3 3 0 000 6z"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            </button>
            <button class="cfh-btn cfh-dropdown-trigger" data-menu="audio" id="cfh-trigger-audio" aria-label="Audio Tracks" aria-expanded="false">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            </button>
            <button class="cfh-btn cfh-dropdown-trigger" data-menu="subtitles" id="cfh-trigger-subtitles" aria-label="Subtitle Selection" aria-expanded="false">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2v10z"/></svg>
            </button>
        </div>
    </div>

    <!-- Dropdown Menus Container -->
    <div id="cfh-menus-container" class="cfh-menus-container">
        <div class="cfh-menu" id="cfh-menu-quality" hidden>
            <div class="cfh-menu-title">Quality</div>
            <ul class="cfh-menu-list" id="cfh-quality-list"></ul>
        </div>
        <div class="cfh-menu" id="cfh-menu-audio" hidden>
            <div class="cfh-menu-title">Audio Track</div>
            <ul class="cfh-menu-list" id="cfh-audio-list"></ul>
        </div>
        <div class="cfh-menu" id="cfh-menu-subtitles" hidden>
            <div class="cfh-menu-title">Subtitles</div>
            <ul class="cfh-menu-list" id="cfh-subtitles-list"></ul>
        </div>
        <div class="cfh-menu" id="cfh-menu-speed" hidden>
            <div class="cfh-menu-title">Playback Speed</div>
            <ul class="cfh-menu-list" id="cfh-speed-list"></ul>
        </div>
    </div>

    <!-- Thumbnail Scrubbing Preview Box -->
    <div id="cfh-thumb-preview" class="cfh-thumb-preview" hidden>
        <div class="cfh-thumb-frame" id="cfh-thumb-frame"></div>
        <span class="cfh-thumb-time" id="cfh-thumb-time">00:00</span>
    </div>

    <!-- Bottom Overlay -->
    <div id="cfh-bottom-overlay" class="cfh-overlay cfh-bottom-overlay">
        <!-- Interactive Seek Bar Container -->
        <div class="cfh-seekbar-container" id="cfh-seekbar-container" role="slider" aria-label="Seek Bar" aria-valuemin="0" aria-valuemax="0" aria-valuenow="0">
            <div class="cfh-seekbar-track">
                <div class="cfh-seekbar-buffered" id="cfh-seekbar-buffered"></div>
                <div class="cfh-seekbar-played" id="cfh-seekbar-played">
                    <div class="cfh-seekbar-thumb"></div>
                </div>
            </div>
        </div>

        <div class="cfh-bottom-controls">
            <div class="cfh-controls-left">
                <button type="button" class="cfh-btn" id="cfh-btn-play" aria-label="Play">
                    <svg class="cfh-icon-play" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                    <svg class="cfh-icon-pause" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" hidden><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                </button>
                <button type="button" class="cfh-btn" id="cfh-btn-prev" aria-label="Previous Media">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
                </button>
                <button type="button" class="cfh-btn" id="cfh-btn-next" aria-label="Next Media">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
                </button>
                <div class="cfh-time-display">
                    <span id="cfh-time-current">0:00</span>
                    <span class="cfh-time-sep">/</span>
                    <span id="cfh-time-duration">0:00</span>
                </div>
            </div>

            <div class="cfh-controls-right">
                <div class="cfh-volume-group">
                    <button type="button" class="cfh-btn" id="cfh-btn-mute" aria-label="Mute">
                        <svg class="cfh-icon-vol-high" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/></svg>
                        <svg class="cfh-icon-vol-mute" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" hidden><path d="M11 5L6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
                    </button>
                    <div class="cfh-volume-slider-wrapper">
                        <input type="range" class="cfh-volume-slider" id="cfh-volume-slider" min="0" max="1" step="0.05" value="1" aria-label="Volume Slider">
                    </div>
                </div>

                <button class="cfh-btn cfh-dropdown-trigger" data-menu="speed" id="cfh-btn-speed-label" aria-label="Speed Control" aria-expanded="false">1x</button>
                <button type="button" class="cfh-btn" id="cfh-btn-pip" aria-label="Picture in Picture">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7h-8v6h8V7z"/><path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14z"/></svg>
                </button>
                <button type="button" class="cfh-btn" id="cfh-btn-fullscreen" aria-label="Toggle Fullscreen">
                    <svg class="cfh-icon-fs-enter" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>
                    <svg class="cfh-icon-fs-exit" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" hidden><path d="M8 3v3a2 2 0 01-2 2H3m18 0h-3a2 2 0 01-2-2V3m0 18v-3a2 2 0 012-2h3M3 16h3a2 2 0 012 2v3"/></svg>
                </button>
            </div>
        </div>
    </div>
</div>

