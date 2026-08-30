export class ThumbnailProvider {
    constructor(spriteUrl, options = {}) {
        this.spriteUrl = spriteUrl || '';
        this.interval = Number(options.interval) > 0 ? Number(options.interval) : 5;
        this.thumbWidth = Number(options.thumbWidth) > 0 ? Number(options.thumbWidth) : 160;
        this.thumbHeight = Number(options.thumbHeight) > 0 ? Number(options.thumbHeight) : 90;
        this.cols = Number(options.cols) > 0 ? Number(options.cols) : 5;
        this.isLoaded = Boolean(this.spriteUrl);
        this.image = null;
        this.failed = false;

        if (this.spriteUrl) {
            this.preload();
        }
    }

    preload() {
        this.image = new Image();
        this.image.decoding = 'async';
        this.image.onload = () => {
            this.isLoaded = true;
            this.failed = false;
        };
        this.image.onerror = () => {
            this.isLoaded = false;
            this.failed = true;
        };
        this.image.src = this.spriteUrl;
    }

    getThumbnail(time) {
        if (!this.isLoaded || this.failed || !this.spriteUrl) return null;

        const safeTime = Math.max(0, Number(time) || 0);
        const index = Math.floor(safeTime / this.interval);
        const col = index % this.cols;
        const row = Math.floor(index / this.cols);

        return {
            spriteUrl: this.spriteUrl,
            x: col * this.thumbWidth,
            y: row * this.thumbHeight,
            width: this.thumbWidth,
            height: this.thumbHeight
        };
    }
}
