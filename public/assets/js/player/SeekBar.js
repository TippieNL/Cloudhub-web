export class SeekBar {
    constructor(containerEl, playedEl, bufferedEl, video, preview) {
        this.containerEl = containerEl;
        this.playedEl = playedEl;
        this.bufferedEl = bufferedEl;
        this.video = video;
        this.preview = preview;
        this.isDragging = false;
        this.pointerId = null;
        this.pendingTime = null;
        this.listeners = [];

        this.init();
    }

    on(target, event, handler, options) {
        target.addEventListener(event, handler, options);
        this.listeners.push([target, event, handler, options]);
    }

    init() {
        this.on(this.video, 'timeupdate', () => this.updatePlayed());
        this.on(this.video, 'progress', () => this.updateBuffered());
        this.on(this.video, 'durationchange', () => {
            this.updatePlayed();
            this.updateBuffered();
            this.updateAria();
        });
        this.on(this.video, 'loadedmetadata', () => {
            this.updatePlayed();
            this.updateBuffered();
            this.updateAria();
        });

        this.on(this.containerEl, 'pointermove', (event) => {
            if (event.pointerType === 'touch' && !this.isDragging) return;
            this.handleHover(event);
            if (this.isDragging) this.updateFromPointer(event, false);
        });
        this.on(this.containerEl, 'pointerleave', () => {
            if (!this.isDragging) this.preview?.hide();
        });
        this.on(this.containerEl, 'pointerdown', (event) => this.startDrag(event));
        this.on(this.containerEl, 'pointerup', (event) => this.endDrag(event));
        this.on(this.containerEl, 'pointercancel', (event) => this.endDrag(event));
        this.on(this.containerEl, 'keydown', (event) => this.handleKeydown(event));
        this.on(this.containerEl, 'wheel', (event) => {
            if (!this.video.duration) return;
            event.preventDefault();
            const delta = event.deltaY > 0 ? -5 : 5;
            this.setTime(this.video.currentTime + delta);
        }, { passive: false });

        this.updatePlayed();
        this.updateBuffered();
    }

    getTimeFromEvent(event) {
        const rect = this.containerEl.getBoundingClientRect();
        const width = Math.max(rect.width, 1);
        const posX = Math.max(0, Math.min(event.clientX - rect.left, width));
        const percentage = posX / width;
        const duration = Number.isFinite(this.video.duration) ? this.video.duration : 0;

        return {
            percentage,
            time: percentage * duration,
            rect
        };
    }

    handleHover(event) {
        if (!Number.isFinite(this.video.duration) || this.video.duration <= 0) return;

        const { time, rect } = this.getTimeFromEvent(event);
        this.preview?.render(time, this.video.duration, event.clientX, rect);
    }

    startDrag(event) {
        if (event.button !== undefined && event.button !== 0) return;
        if (!Number.isFinite(this.video.duration) || this.video.duration <= 0) return;

        event.preventDefault();

        this.isDragging = true;
        this.pointerId = event.pointerId;
        this.containerEl.classList.add('cfh-dragging');

        try {
            this.containerEl.setPointerCapture(event.pointerId);
        } catch {
            // Pointer capture is optional.
        }

        this.updateFromPointer(event, true);
    }

    updateFromPointer(event, seekVideo) {
        const { time, rect } = this.getTimeFromEvent(event);
        this.pendingTime = time;

        if (seekVideo) {
            this.setTime(time, false);
        }

        const percentage = this.video.duration > 0
            ? (time / this.video.duration) * 100
            : 0;

        this.playedEl.style.width = `${Math.max(0, Math.min(100, percentage))}%`;
        this.containerEl.setAttribute('aria-valuenow', String(Math.round(percentage)));
        this.preview?.render(time, this.video.duration, event.clientX, rect);
    }

    endDrag(event = null) {
        if (!this.isDragging) return;

        if (event && this.pointerId !== null && event.pointerId !== this.pointerId) {
            return;
        }

        if (this.pendingTime !== null) {
            this.setTime(this.pendingTime, true);
        }

        if (this.pointerId !== null) {
            try {
                this.containerEl.releasePointerCapture(this.pointerId);
            } catch {
                // Pointer capture may already have been released.
            }
        }

        this.isDragging = false;
        this.pointerId = null;
        this.pendingTime = null;
        this.containerEl.classList.remove('cfh-dragging');
        this.preview?.hide();
    }

    setTime(time, showPreview = false) {
        if (!Number.isFinite(time) || !Number.isFinite(this.video.duration)) return;

        const safeTime = Math.max(0, Math.min(this.video.duration, time));
        try {
            this.video.currentTime = safeTime;
        } catch (error) {
            console.warn('[CloudHub Player] Seek failed:', error);
        }

        if (showPreview) {
            this.updatePlayed();
        }
    }

    handleKeydown(event) {
        let nextTime = null;
        const step = event.shiftKey ? 10 : 5;

        switch (event.key) {
            case 'ArrowLeft':
                nextTime = this.video.currentTime - step;
                break;
            case 'ArrowRight':
                nextTime = this.video.currentTime + step;
                break;
            case 'Home':
                nextTime = 0;
                break;
            case 'End':
                nextTime = this.video.duration;
                break;
            default:
                return;
        }

        event.preventDefault();
        this.setTime(nextTime);
    }

    updatePlayed() {
        if (this.isDragging || !Number.isFinite(this.video.duration) || this.video.duration <= 0) {
            if (!Number.isFinite(this.video.duration) || this.video.duration <= 0) {
                this.playedEl.style.width = '0%';
                this.updateAria();
            }
            return;
        }

        const pct = Math.max(0, Math.min(100, (this.video.currentTime / this.video.duration) * 100));
        this.playedEl.style.width = `${pct}%`;
        this.containerEl.setAttribute('aria-valuenow', String(Math.round(pct)));
    }

    updateAria() {
        const duration = Number.isFinite(this.video.duration) ? Math.max(0, this.video.duration) : 0;
        this.containerEl.setAttribute('aria-valuemax', String(Math.round(duration)));
        this.containerEl.setAttribute('aria-valuenow', String(Math.round(this.video.currentTime || 0)));
    }

    updateBuffered() {
        if (!Number.isFinite(this.video.duration) || this.video.duration <= 0) {
            this.bufferedEl.style.width = '0%';
            return;
        }

        const ranges = this.video.buffered;
        let bufferedEnd = 0;

        // Use the range containing currentTime. This avoids jumping backwards
        // when the browser creates multiple buffered ranges after seeking.
        for (let i = 0; i < ranges.length; i += 1) {
            const start = ranges.start(i);
            const end = ranges.end(i);
            if (this.video.currentTime >= start && this.video.currentTime <= end) {
                bufferedEnd = end;
                break;
            }
            bufferedEnd = Math.max(bufferedEnd, end);
        }

        const pct = Math.max(0, Math.min(100, (bufferedEnd / this.video.duration) * 100));
        this.bufferedEl.style.width = `${pct}%`;
    }

    destroy() {
        this.endDrag();
        this.preview?.hide();

        this.listeners.forEach(([target, event, handler, options]) => {
            target.removeEventListener(event, handler, options);
        });
        this.listeners = [];
    }
}
