export class KeyboardShortcuts {
    constructor(playerUI) {
        this.playerUI = playerUI;
        this.video = playerUI.video;
        this.handler = (event) => this.handle(event);
        window.addEventListener('keydown', this.handler);
    }

    isTypingTarget(target) {
        return Boolean(target?.matches?.(
            'input, textarea, select, [contenteditable="true"], [role="textbox"]'
        ));
    }

    handle(event) {
        if (event.defaultPrevented || this.isTypingTarget(event.target)) return;

        // Never swallow browser or OS shortcuts. Alt+Left/Right are Back and
        // Forward on Windows and Linux, Cmd+Left/Right on macOS; the player was
        // calling preventDefault() on the bare key regardless of modifiers, so
        // the keyboard Back shortcut seeked the video instead of navigating.
        if (event.altKey || event.ctrlKey || event.metaKey) return;

        // Do not hijack shortcuts when another media element is active.
        const playerContainer = this.playerUI.container;
        if (!playerContainer || !playerContainer.isConnected) return;

        const duration = Number.isFinite(this.video.duration) ? this.video.duration : 0;
        const seek = (delta, label) => {
            if (!duration) return;
            this.video.currentTime = Math.max(0, Math.min(duration, this.video.currentTime + delta));
            this.playerUI.showFeedback(label);
            this.playerUI.overlay?.showControlsTemporarily();
        };

        switch (event.key) {
            case ' ':
            case 'k':
            case 'K':
                event.preventDefault();
                this.playerUI.togglePlay();
                break;
            case 'j':
            case 'J':
                event.preventDefault();
                seek(-10, '-10s');
                break;
            case 'l':
            case 'L':
                event.preventDefault();
                seek(10, '+10s');
                break;
            case 'ArrowLeft':
                event.preventDefault();
                seek(-5, '-5s');
                break;
            case 'ArrowRight':
                event.preventDefault();
                seek(5, '+5s');
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.playerUI.volumeManager?.setVolume(this.video.volume + 0.1);
                break;
            case 'ArrowDown':
                event.preventDefault();
                this.playerUI.volumeManager?.setVolume(this.video.volume - 0.1);
                break;
            case 'f':
            case 'F':
                event.preventDefault();
                this.playerUI.fullscreenManager?.toggle();
                break;
            case 'm':
            case 'M':
                event.preventDefault();
                this.playerUI.volumeManager?.toggleMute();
                break;
            case 'p':
            case 'P':
                if (!this.playerUI.pipManager?.btnPip.hidden) {
                    event.preventDefault();
                    this.playerUI.pipManager.toggle();
                }
                break;
            case 'Home':
                event.preventDefault();
                if (duration) this.video.currentTime = 0;
                break;
            case 'End':
                event.preventDefault();
                if (duration) this.video.currentTime = duration;
                break;
            default:
                if (/^[0-9]$/.test(event.key) && duration) {
                    event.preventDefault();
                    this.video.currentTime = duration * (Number(event.key) / 10);
                }
        }
    }

    destroy() {
        window.removeEventListener('keydown', this.handler);
    }
}
