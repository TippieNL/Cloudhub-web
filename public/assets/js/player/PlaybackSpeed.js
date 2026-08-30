export class PlaybackSpeed {
    constructor(video, labelBtn, settings) {
        this.video = video;
        this.labelBtn = labelBtn;
        this.settings = settings;
        this.allowedSpeeds = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
        this.currentSpeed = this.normalize(this.settings.get('speed'));
        this.listeners = [];

        this.setSpeed(this.currentSpeed, false);
    }

    normalize(value) {
        const numeric = Number(value);
        return this.allowedSpeeds.includes(numeric) ? numeric : 1;
    }

    setSpeed(speed, persist = true) {
        const next = this.normalize(speed);
        this.currentSpeed = next;
        this.video.playbackRate = next;
        this.labelBtn.textContent = `${next}×`;

        if (persist) {
            this.settings.set('speed', next);
        }

        this.labelBtn.setAttribute('aria-label', `Playback speed ${next} times`);
    }

    destroy() {
        this.listeners.forEach(([target, event, handler]) => {
            target.removeEventListener(event, handler);
        });
        this.listeners = [];
    }
}
