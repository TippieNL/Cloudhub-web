export class TouchGestures {
    constructor(container, playerUI) {
        this.container = container;
        this.playerUI = playerUI;
        this.video = playerUI.video;

        this.tapTimer = null;
        this.tapCount = 0;
        this.longPressTimer = null;
        this.longPressActive = false;
        this.moved = false;
        this.startX = 0;
        this.startY = 0;
        this.startTarget = null;
        this.lastTapX = 0;
        this.lastTapY = 0;
        this.listeners = [];

        this.init();
    }

    init() {
        this.on(this.container, 'touchstart', (event) => this.onTouchStart(event), { passive: true });
        this.on(this.container, 'touchmove', (event) => this.onTouchMove(event), { passive: true });
        this.on(this.container, 'touchend', (event) => this.onTouchEnd(event), { passive: true });
        this.on(this.container, 'touchcancel', () => this.cancelGesture(), { passive: true });
    }

    on(target, event, handler, options) {
        target.addEventListener(event, handler, options);
        this.listeners.push([target, event, handler, options]);
    }

    isInteractiveTarget(target) {
        return Boolean(target?.closest?.(
            'button, input, select, textarea, a, [role="slider"], .cfh-menu, .cfh-seekbar-container'
        ));
    }

    onTouchStart(event) {
        if (event.touches.length !== 1) {
            this.cancelGesture();
            return;
        }

        const touch = event.touches[0];
        this.startX = touch.clientX;
        this.startY = touch.clientY;
        this.startTarget = event.target;
        this.moved = false;
        this.longPressActive = false;

        // Any touch is user activity. Keep the controls visible immediately and
        // restart the longer touch-friendly auto-hide timer.
        this.playerUI.overlay?.showControlsTemporarily();

        if (this.isInteractiveTarget(event.target)) {
            return;
        }

        clearTimeout(this.longPressTimer);
        this.longPressTimer = window.setTimeout(() => {
            if (!this.moved && !this.video.paused) {
                this.longPressActive = true;
                this.video.playbackRate = 2;
                this.playerUI.showFeedback('2×');
            }
        }, 600);
    }

    onTouchMove(event) {
        if (event.touches.length !== 1 || this.isInteractiveTarget(this.startTarget)) return;

        const touch = event.touches[0];
        const deltaX = touch.clientX - this.startX;
        const deltaY = touch.clientY - this.startY;

        if (Math.hypot(deltaX, deltaY) > 12) {
            this.moved = true;
            clearTimeout(this.longPressTimer);
        }
    }

    onTouchEnd() {
        clearTimeout(this.longPressTimer);

        if (this.isInteractiveTarget(this.startTarget)) {
            this.resetGesture();
            return;
        }

        if (this.longPressActive) {
            this.restoreSpeed();
            this.resetGesture();
            return;
        }

        if (this.moved) {
            this.resetGesture();
            return;
        }

        const now = performance.now();
        const isDoubleTap = this.tapCount === 1 &&
            now - this.lastTapTime < 300 &&
            Math.hypot(this.startX - this.lastTapX, this.startY - this.lastTapY) < 60;

        if (isDoubleTap) {
            clearTimeout(this.tapTimer);
            const rect = this.container.getBoundingClientRect();
            const x = this.startX - rect.left;
            const offset = x < rect.width / 2 ? -10 : 10;
            this.seekBy(offset);
            this.tapCount = 0;
            return;
        }

        this.tapCount = 1;
        this.lastTapTime = now;
        this.lastTapX = this.startX;
        this.lastTapY = this.startY;

        clearTimeout(this.tapTimer);
        this.tapTimer = window.setTimeout(() => {
            // A normal mobile tap is activity, not a request to hide the UI.
            // Keep the controls visible for the touch-friendly auto-hide period.
            this.playerUI.overlay?.showControlsTemporarily();
            this.tapCount = 0;
        }, 300);
    }

    seekBy(delta) {
        if (!Number.isFinite(this.video.duration)) return;

        const next = Math.max(0, Math.min(this.video.duration, this.video.currentTime + delta));
        this.video.currentTime = next;
        this.playerUI.showFeedback(`${delta > 0 ? '+' : ''}${delta}s`);
        this.playerUI.overlay?.showControlsTemporarily();
    }

    restoreSpeed() {
        const speed = this.playerUI.speedManager?.currentSpeed ?? 1;
        this.video.playbackRate = speed;
        this.playerUI.showFeedback(`${speed}×`);
    }

    cancelGesture() {
        clearTimeout(this.longPressTimer);
        this.restoreSpeedIfNeeded();
        this.resetGesture();
    }

    restoreSpeedIfNeeded() {
        if (this.longPressActive) {
            const speed = this.playerUI.speedManager?.currentSpeed ?? 1;
            this.video.playbackRate = speed;
        }
    }

    resetGesture() {
        this.tapCount = 0;
        this.moved = false;
        this.longPressActive = false;
        this.startTarget = null;
    }

    destroy() {
        clearTimeout(this.tapTimer);
        clearTimeout(this.longPressTimer);
        this.restoreSpeedIfNeeded();

        this.listeners.forEach(([target, event, handler, options]) => {
            target.removeEventListener(event, handler, options);
        });
        this.listeners = [];
    }
}
