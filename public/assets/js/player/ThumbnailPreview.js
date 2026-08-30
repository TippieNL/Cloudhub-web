export class ThumbnailPreview {
    constructor(previewEl, frameEl, timeEl, provider) {
        this.previewEl = previewEl;
        this.frameEl = frameEl;
        this.timeEl = timeEl;
        this.provider = provider;
        this.lastSpriteUrl = '';
    }

    render(time, duration, pageX, parentRect) {
        if (!this.previewEl || !this.frameEl || !this.timeEl) return;

        const safeDuration = Number.isFinite(duration) ? Math.max(0, duration) : 0;
        const safeTime = Math.max(0, Math.min(Number(time) || 0, safeDuration));
        const thumb = this.provider?.getThumbnail(safeTime);

        if (!thumb) {
            this.hide();
            return;
        }

        if (thumb) {
            if (this.lastSpriteUrl !== thumb.spriteUrl) {
                const safeUrl = thumb.spriteUrl
                    .replace(/\\/g, '\\\\')
                    .replace(/"/g, '\\"')
                    .replace(/\r|\n/g, '');
                this.frameEl.style.backgroundImage = `url("${safeUrl}")`;
                this.lastSpriteUrl = thumb.spriteUrl;
            }
            this.frameEl.style.backgroundPosition = `-${thumb.x}px -${thumb.y}px`;
            this.frameEl.style.width = `${thumb.width}px`;
            this.frameEl.style.height = `${thumb.height}px`;
        } else {
            this.frameEl.style.backgroundImage = 'none';
        }

        this.timeEl.textContent = this.formatTime(safeTime);

        const previewWidth = this.previewEl.offsetWidth || 168;
        const minX = previewWidth / 2;
        const maxX = Math.max(minX, parentRect.width - minX);
        const left = Math.max(minX, Math.min(pageX - parentRect.left, maxX));

        this.previewEl.style.left = `${left}px`;
        this.previewEl.classList.add('cfh-visible');
        this.previewEl.hidden = false;
    }

    formatTime(seconds) {
        const total = Math.max(0, Math.floor(seconds));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const secs = total % 60;

        return hours > 0
            ? `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`
            : `${minutes}:${String(secs).padStart(2, '0')}`;
    }

    hide() {
        this.previewEl.classList.remove('cfh-visible');
        this.previewEl.hidden = true;
    }
}
