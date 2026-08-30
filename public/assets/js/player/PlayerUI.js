import { PlayerSettings } from './PlayerSettings.js';
import { SubtitleManager } from './SubtitleManager.js';
import { ThumbnailProvider } from './ThumbnailProvider.js';
import { ThumbnailPreview } from './ThumbnailPreview.js';
import { SeekBar } from './SeekBar.js';
import { KeyboardShortcuts } from './KeyboardShortcuts.js';
import { TouchGestures } from './TouchGestures.js';
import { VolumeManager } from './VolumeManager.js';
import { PlaybackSpeed } from './PlaybackSpeed.js';
import { PictureInPicture } from './PictureInPicture.js';
import { FullscreenManager } from './FullscreenManager.js';
import { Overlay } from './Overlay.js';

export class PlayerUI {
    constructor(containerId) {
        this.container = document.getElementById(containerId);

        if (!this.container) {
            console.error('[CloudHub Player] Container not found:', containerId);
            return;
        }

        this.video = this.container.querySelector('#cfh-video-element');

        if (!this.video) {
            console.error('[CloudHub Player] Video element missing.');
            return;
        }

        this.settings = new PlayerSettings();
        this.components = new Map();
        this.listeners = [];
        this.feedbackTimer = null;
        this.destroyed = false;

        this.initialize();
    }

    initialize() {
        this.initOverlay();
        this.initVolume();
        this.initSpeed();
        this.initPiP();
        this.initFullscreen();
        this.initSubtitles();
        this.initThumbnail();
        this.initSeekbar();
        this.initKeyboard();
        this.initGestures();
        this.initSpeedMenu();
        this.initOptionalControls();
        this.bindEvents();

        this.updatePlayState(!this.video.paused);
        this.updateMediaState();
    }

    initComponent(name, factory) {
        try {
            const component = factory();
            if (component) this.components.set(name, component);
            return component;
        } catch (error) {
            console.error(`[CloudHub Player] Failed to initialize ${name}:`, error);
            return null;
        }
    }

    initOverlay() {
        this.overlay = this.initComponent('Overlay', () => new Overlay(this.container));
    }

    initVolume() {
        const slider = this.container.querySelector('#cfh-volume-slider');
        const mute = this.container.querySelector('#cfh-btn-mute');
        if (!slider || !mute) return;

        this.volumeManager = this.initComponent(
            'Volume',
            () => new VolumeManager(this.video, slider, mute, this.settings)
        );
    }

    initSpeed() {
        const label = this.container.querySelector('#cfh-btn-speed-label');
        if (!label) return;

        this.speedManager = this.initComponent(
            'Playback Speed',
            () => new PlaybackSpeed(this.video, label, this.settings)
        );
    }

    initPiP() {
        const btn = this.container.querySelector('#cfh-btn-pip');
        if (!btn) return;

        this.pipManager = this.initComponent(
            'Picture in Picture',
            () => new PictureInPicture(this.video, btn)
        );
    }

    initFullscreen() {
        const btn = this.container.querySelector('#cfh-btn-fullscreen');
        if (!btn) return;

        this.fullscreenManager = this.initComponent(
            'Fullscreen',
            () => new FullscreenManager(this.container, btn)
        );
    }

    initSubtitles() {
        const display = this.container.querySelector('#cfh-subtitle-display');
        if (!display) return;

        this.subtitleManager = this.initComponent(
            'Subtitles',
            () => new SubtitleManager(this.video, display, this.settings)
        );
    }

    initThumbnail() {
        const preview = this.container.querySelector('#cfh-thumb-preview');
        const frame = this.container.querySelector('#cfh-thumb-frame');
        const time = this.container.querySelector('#cfh-thumb-time');
        if (!preview || !frame || !time) return;

        this.thumbProvider = new ThumbnailProvider(this.video.dataset.sprite || '');

        this.thumbPreview = this.initComponent(
            'Thumbnail Preview',
            () => new ThumbnailPreview(preview, frame, time, this.thumbProvider)
        );
    }

    initSeekbar() {
        const container = this.container.querySelector('#cfh-seekbar-container');
        const played = this.container.querySelector('#cfh-seekbar-played');
        const buffered = this.container.querySelector('#cfh-seekbar-buffered');
        if (!container || !played || !buffered) return;

        this.seekBar = this.initComponent(
            'SeekBar',
            () => new SeekBar(container, played, buffered, this.video, this.thumbPreview)
        );
    }

    initKeyboard() {
        this.keyboard = this.initComponent('Keyboard', () => new KeyboardShortcuts(this));
    }

    initGestures() {
        this.gestures = this.initComponent('Touch Gestures', () => new TouchGestures(this.container, this));
    }


    initOptionalControls() {
        // These controls currently have no backend-provided track/quality data.
        // Hiding them is preferable to exposing empty menus that appear broken.
        ['quality', 'audio', 'subtitles'].forEach((menuName) => {
            const trigger = this.container.querySelector(`[data-menu="${menuName}"]`);
            const menu = this.container.querySelector(`#cfh-menu-${menuName}`);

            if (!trigger || !menu) return;

            const hasItems = menu.querySelector('.cfh-menu-item');
            if (!hasItems) {
                trigger.hidden = true;
                menu.hidden = true;
            }
        });
    }

    initSpeedMenu() {
        const list = this.container.querySelector('#cfh-speed-list');
        if (!list || !this.speedManager) return;

        list.replaceChildren();

        this.speedManager.allowedSpeeds.forEach((speed) => {
            const item = document.createElement('li');
            item.className = 'cfh-menu-item';
            item.dataset.speed = String(speed);
            item.setAttribute('role', 'menuitemradio');
            item.setAttribute('aria-checked', String(speed === this.speedManager.currentSpeed));
            item.tabIndex = 0;
            item.textContent = `${speed}×`;

            const select = () => {
                this.speedManager.setSpeed(speed);
                this.updateSpeedMenu();
                this.hideAllMenus();
                this.overlay?.showControlsTemporarily();
            };

            item.addEventListener('click', select);
            item.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    select();
                }
            });

            list.appendChild(item);
        });

        this.updateSpeedMenu();
    }

    updateSpeedMenu() {
        const list = this.container.querySelector('#cfh-speed-list');
        if (!list || !this.speedManager) return;

        list.querySelectorAll('[data-speed]').forEach((item) => {
            const active = Number(item.dataset.speed) === this.speedManager.currentSpeed;
            item.classList.toggle('cfh-active', active);
            item.setAttribute('aria-checked', String(active));
        });
    }

    on(target, event, handler, options) {
        if (!target) return;
        target.addEventListener(event, handler, options);
        this.listeners.push([target, event, handler, options]);
    }

    bindEvents() {
        const back = this.container.querySelector('#cfh-btn-back');
        const play = this.container.querySelector('#cfh-btn-play');
        const video = this.video;

        this.on(back, 'click', (event) => {
            event.preventDefault();
            this.goBack();
        });

        this.on(play, 'click', () => this.togglePlay());

        this.on(video, 'play', () => {
            this.updatePlayState(true);
            this.hideStatus();
            this.overlay?.showControlsTemporarily();
        });

        this.on(video, 'pause', () => {
            this.updatePlayState(false);
            this.overlay?.showControls();
        });

        this.on(video, 'ended', () => {
            this.updatePlayState(false);
            this.overlay?.showControls();
        });

        this.on(video, 'loadedmetadata', () => this.updateMediaState());
        this.on(video, 'durationchange', () => this.updateMediaState());
        this.on(video, 'timeupdate', () => {
            const current = this.container.querySelector('#cfh-time-current');
            if (current) current.textContent = this.formatTime(video.currentTime);
        });

        this.on(video, 'waiting', () => this.showStatus('Buffering…', false));
        this.on(video, 'stalled', () => this.showStatus('Buffering…', false));
        this.on(video, 'canplay', () => this.hideStatus());
        this.on(video, 'playing', () => this.hideStatus());

        this.on(video, 'error', () => this.handleMediaError());

        this.container.querySelectorAll('.cfh-dropdown-trigger').forEach((button) => {
            this.on(button, 'click', (event) => {
                event.stopPropagation();
                this.toggleMenu(button.dataset.menu);
            });
        });

        this.on(document, 'click', (event) => {
            if (!this.container.contains(event.target)) return;
            if (!event.target.closest('.cfh-menu') && !event.target.closest('.cfh-dropdown-trigger')) {
                this.hideAllMenus();
            }
        });
    }

    async togglePlay() {
        if (this.video.ended) {
            this.video.currentTime = 0;
        }

        if (this.video.paused) {
            try {
                await this.video.play();
            } catch (error) {
                console.warn('[CloudHub Player] Playback failed:', error);
                this.showStatus(
                    error?.name === 'NotAllowedError'
                        ? 'Playback was blocked by the browser. Press Play again.'
                        : 'Unable to play this media.',
                    true
                );
            }
        } else {
            this.video.pause();
        }
    }

    updatePlayState(playing) {
        const play = this.container.querySelector('.cfh-icon-play');
        const pause = this.container.querySelector('.cfh-icon-pause');
        const button = this.container.querySelector('#cfh-btn-play');

        if (play) play.hidden = playing;
        if (pause) pause.hidden = !playing;
        if (button) button.setAttribute('aria-label', playing ? 'Pause' : 'Play');
    }

    updateMediaState() {
        const duration = this.container.querySelector('#cfh-time-duration');
        if (duration) {
            duration.textContent = this.formatTime(this.video.duration);
        }
    }

    handleMediaError() {
        const error = this.video.error;
        const code = error?.code;

        let message = 'Unable to load this media.';
        if (code === MediaError.MEDIA_ERR_NETWORK) {
            message = 'Network error while loading the media.';
        } else if (code === MediaError.MEDIA_ERR_DECODE) {
            message = 'This video format or codec is not supported by the browser.';
        } else if (code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED) {
            message = 'This media format is not supported by the browser.';
        }

        this.showStatus(message, true);
        this.updatePlayState(false);
    }

    showStatus(message, persistent = false) {
        const status = this.container.querySelector('#cfh-player-status');
        if (!status) return;

        status.textContent = message;
        status.hidden = false;
        status.classList.toggle('cfh-status-persistent', persistent);
    }

    hideStatus() {
        const status = this.container.querySelector('#cfh-player-status');
        if (status && !status.classList.contains('cfh-status-persistent')) {
            status.hidden = true;
        }
    }

    /**
     * Leave the player the way the browser's own Back button would.
     *
     * This used to gate history.back() on a same-origin document.referrer, but
     * the application sends `Referrer-Policy: no-referrer`, so the referrer is
     * always empty and that branch could never run. Every press fell through to
     * a fresh navigation, which *pushed* a history entry instead of popping
     * one: the folder the user was browsing was lost, and pressing the
     * browser's Back button afterwards landed them straight back on the player,
     * playing again, with no way out of the loop.
     */
    goBack() {
        const front = window.CLOUDHUB_FRONT || '/';

        if (window.history.length > 1) {
            let leaving = false;
            const onLeave = () => { leaving = true; };
            window.addEventListener('pagehide', onLeave, { once: true });

            window.history.back();

            // history.back() is asynchronous, and does nothing at all when the
            // previous entry is no longer available. If we are still here
            // shortly afterwards, navigate instead -- with replace(), so the
            // player is never left stacked behind the page we land on.
            window.setTimeout(() => {
                window.removeEventListener('pagehide', onLeave);
                if (!leaving) window.location.replace(front);
            }, 500);
            return;
        }

        // Opened directly, e.g. in a new tab: there is nothing to go back to.
        window.location.replace(front);
    }

    toggleMenu(menuName) {
        const target = this.container.querySelector(`#cfh-menu-${menuName || ''}`);
        if (!target) return;

        const shouldOpen = target.hidden;
        this.hideAllMenus();

        target.hidden = !shouldOpen;

        const trigger = this.container.querySelector(`[data-menu="${menuName || ''}"]`);
        if (trigger) trigger.setAttribute('aria-expanded', String(shouldOpen));

        if (shouldOpen && menuName === 'speed') {
            this.updateSpeedMenu();
        }
    }

    showFeedback(message, duration = 1200) {
        const element = this.container.querySelector('#cfh-gesture-feedback');
        if (!element) return;

        element.textContent = message;
        element.classList.add('cfh-active');

        clearTimeout(this.feedbackTimer);
        this.feedbackTimer = window.setTimeout(() => {
            element.classList.remove('cfh-active');
            element.textContent = '';
        }, duration);
    }

    hideAllMenus() {
        this.container.querySelectorAll('.cfh-menu').forEach((menu) => {
            menu.hidden = true;
        });

        this.container.querySelectorAll('.cfh-dropdown-trigger').forEach((trigger) => {
            trigger.setAttribute('aria-expanded', 'false');
        });
    }

    formatTime(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) return '0:00';

        const total = Math.floor(seconds);
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;

        return hours > 0
            ? `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
            : `${minutes}:${String(secs).padStart(2, '0')}`;
    }

    destroy() {
        if (this.destroyed) return;
        this.destroyed = true;

        clearTimeout(this.feedbackTimer);

        this.listeners.forEach(([target, event, handler, options]) => {
            target.removeEventListener(event, handler, options);
        });
        this.listeners = [];

        this.components.forEach((component) => {
            if (typeof component?.destroy === 'function') component.destroy();
        });
        this.components.clear();

        if (window.cfhPlayer === this) {
            window.cfhPlayer = null;
        }
    }
}

function bootstrap() {
    const container = document.getElementById('cfh-player-container');
    if (!container || window.cfhPlayer) return;

    window.cfhPlayer = new PlayerUI(container.id);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
