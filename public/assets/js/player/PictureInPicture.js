export class PictureInPicture {
    constructor(video, btnPip) {
        this.video = video;
        this.btnPip = btnPip;
        this.listeners = [];

        if (!document.pictureInPictureEnabled || typeof video.requestPictureInPicture !== 'function') {
            this.btnPip.hidden = true;
            return;
        }

        this.on(this.btnPip, 'click', () => this.toggle());
        this.on(video, 'enterpictureinpicture', () => this.updateState(true));
        this.on(video, 'leavepictureinpicture', () => this.updateState(false));
        this.updateState(Boolean(document.pictureInPictureElement === video));
    }

    on(target, event, handler) {
        target.addEventListener(event, handler);
        this.listeners.push([target, event, handler]);
    }

    async toggle() {
        try {
            if (document.pictureInPictureElement === this.video) {
                await document.exitPictureInPicture();
                return;
            }

            if (document.pictureInPictureElement) {
                await document.exitPictureInPicture();
            }

            await this.video.requestPictureInPicture();
        } catch (error) {
            console.warn('[CloudHub Player] Picture-in-Picture unavailable:', error);
            this.updateState(false);
        }
    }

    updateState(active) {
        this.btnPip.setAttribute('aria-pressed', String(active));
        this.btnPip.title = active ? 'Exit Picture in Picture' : 'Picture in Picture';
    }

    destroy() {
        this.listeners.forEach(([target, event, handler]) => {
            target.removeEventListener(event, handler);
        });
        this.listeners = [];
    }
}
