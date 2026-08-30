export class FullscreenManager {
    constructor(container, btnFullscreen) {
        this.container = container;
        this.btnFullscreen = btnFullscreen;
        this.video = container.querySelector('video');
        this.listeners = [];
        this.init();
    }

    on(target, event, handler) {
        target.addEventListener(event, handler);
        this.listeners.push([target, event, handler]);
    }

    init() {
        this.on(this.btnFullscreen, 'click', () => this.toggle());
        this.on(document, 'fullscreenchange', () => this.updateState());
        this.on(document, 'webkitfullscreenchange', () => this.updateState());
        this.on(document, 'fullscreenerror', (event) => {
            console.warn('[CloudHub Player] Fullscreen request failed:', event);
            this.updateState();
        });
        this.updateState();
    }

    isFullscreen() {
        return Boolean(
            document.fullscreenElement === this.container ||
            document.webkitFullscreenElement === this.container ||
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            this.video?.webkitDisplayingFullscreen
        );
    }

    async toggle() {
        try {
            if (this.isFullscreen()) {
                if (document.exitFullscreen) {
                    await document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (this.video?.webkitExitFullscreen) {
                    this.video.webkitExitFullscreen();
                }
                return;
            }

            // iOS Safari exposes fullscreen primarily on the video element.
            if (this.video?.webkitEnterFullscreen) {
                this.video.webkitEnterFullscreen();
                return;
            }

            if (!this.container.requestFullscreen && !this.container.webkitRequestFullscreen) {
                this.showUnsupported();
                return;
            }

            if (this.container.requestFullscreen) {
                await this.container.requestFullscreen();
            } else {
                this.container.webkitRequestFullscreen();
            }
        } catch (error) {
            console.warn('[CloudHub Player] Fullscreen unavailable:', error);
            this.showUnsupported();
        } finally {
            this.updateState();
        }
    }

    showUnsupported() {
        this.btnFullscreen.setAttribute('aria-label', 'Fullscreen unavailable');
        this.btnFullscreen.title = 'Fullscreen is not supported by this browser';
    }

    updateState() {
        const isFS = this.isFullscreen();
        const enter = this.btnFullscreen.querySelector('.cfh-icon-fs-enter');
        const exit = this.btnFullscreen.querySelector('.cfh-icon-fs-exit');

        if (enter) enter.hidden = isFS;
        if (exit) exit.hidden = !isFS;

        this.btnFullscreen.setAttribute('aria-pressed', String(isFS));
        this.btnFullscreen.setAttribute('aria-label', isFS ? 'Exit Fullscreen' : 'Enter Fullscreen');
    }

    destroy() {
        this.listeners.forEach(([target, event, handler]) => {
            target.removeEventListener(event, handler);
        });
        this.listeners = [];
    }
}
