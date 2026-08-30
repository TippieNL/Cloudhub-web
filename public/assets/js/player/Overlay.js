export class Overlay {
    constructor(container) {
        this.container = container;
        this.video = container.querySelector('video');
        this.hideTimer = null;
        this.visible = true;
        this.listeners = [];
        this.init();
    }

    init() {
        // Mouse/pen movement is activity. Do not use a generic touchstart or
        // touch pointermove listener here: mobile browsers can emit a stream of
        // pointer events while the finger is down, which would continually reset
        // the auto-hide timer.
        const pointerMoveHandler = (event) => {
            if (event.pointerType === 'touch') {
                return;
            }
            this.showControlsTemporarily();
        };
        this.container.addEventListener('pointermove', pointerMoveHandler, { passive: true });
        this.listeners.push(['pointermove', pointerMoveHandler]);

        const focusHandler = () => this.showControlsTemporarily();
        this.container.addEventListener('focusin', focusHandler);
        this.listeners.push(['focusin', focusHandler]);

        const pointerDownHandler = (event) => {
            this.showControlsTemporarily();
        };
        this.container.addEventListener('pointerdown', pointerDownHandler, { passive: true });
        this.listeners.push(['pointerdown', pointerDownHandler]);

        if (this.video) {
            const playHandler = () => this.showControlsTemporarily();
            const pauseHandler = () => this.showControls();
            const endedHandler = () => this.showControls();

            this.video.addEventListener('play', playHandler);
            this.video.addEventListener('pause', pauseHandler);
            this.video.addEventListener('ended', endedHandler);

            this.listeners.push(
                ['video:play', playHandler],
                ['video:pause', pauseHandler],
                ['video:ended', endedHandler]
            );
        }

        this.showControls();
    }

    showControls() {
        clearTimeout(this.hideTimer);
        this.hideTimer = null;
        this.visible = true;
        this.container.classList.remove('cfh-autohide', 'cfh-hide-cursor');
    }

    showControlsTemporarily() {
        this.showControls();

        if (!this.video || this.video.paused || this.video.ended) {
            return;
        }

        const delay = this.getHideDelay();
        this.hideTimer = window.setTimeout(() => {
            this.hideTimer = null;

            if (this.video && !this.video.paused && !this.video.ended) {
                this.visible = false;
                this.container.classList.add('cfh-autohide', 'cfh-hide-cursor');
            }
        }, delay);
    }

    getHideDelay() {
        const hasTouch = window.matchMedia?.('(hover: none) and (pointer: coarse)').matches;
        return hasTouch ? 5000 : 3000;
    }

    toggleControls() {
        if (this.visible) {
            clearTimeout(this.hideTimer);
            this.hideTimer = null;
            this.visible = false;
            this.container.classList.add('cfh-autohide', 'cfh-hide-cursor');
            return;
        }

        this.showControlsTemporarily();
    }

    destroy() {
        clearTimeout(this.hideTimer);
        this.hideTimer = null;

        this.listeners.forEach(([type, handler]) => {
            if (type.startsWith('video:')) {
                this.video?.removeEventListener(type.slice(6), handler);
            } else {
                this.container.removeEventListener(type, handler);
            }
        });

        this.listeners = [];
    }
}
