export class VolumeManager {
    constructor(video, sliderEl, btnMute, settings) {
        this.video = video;
        this.sliderEl = sliderEl;
        this.btnMute = btnMute;
        this.settings = settings;
        this.listeners = [];
        this.lastNonZeroVolume = 1;
        this.init();
    }

    on(target, event, handler) {
        target.addEventListener(event, handler);
        this.listeners.push([target, event, handler]);
    }

    init() {
        const savedVol = this.normalize(this.settings.get('volume'), 1);
        const savedMuted = Boolean(this.settings.get('muted'));

        this.video.volume = savedVol;
        this.video.muted = savedMuted;
        this.lastNonZeroVolume = savedVol > 0 ? savedVol : 1;
        this.syncSlider();
        this.updateIcon();

        this.on(this.sliderEl, 'input', (event) => {
            const volume = this.normalize(Number.parseFloat(event.target.value), 1);
            this.setVolume(volume);
            if (volume > 0 && this.video.muted) {
                this.video.muted = false;
                this.settings.set('muted', false);
            }
        });

        this.on(this.btnMute, 'click', () => this.toggleMute());
        this.on(this.video, 'volumechange', () => {
            this.syncSlider();
            this.updateIcon();
        });
    }

    normalize(value, fallback = 1) {
        return Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : fallback;
    }

    setVolume(value) {
        const volume = this.normalize(value, 1);
        this.video.volume = volume;

        if (volume > 0) {
            this.lastNonZeroVolume = volume;
        }

        this.settings.set('volume', volume);
        this.syncSlider();
        this.updateIcon();
    }

    toggleMute() {
        if (this.video.muted || this.video.volume === 0) {
            const volume = this.lastNonZeroVolume || 1;
            this.video.volume = volume;
            this.video.muted = false;
            this.settings.set('volume', volume);
            this.settings.set('muted', false);
        } else {
            this.video.muted = true;
            this.settings.set('muted', true);
        }

        this.updateIcon();
    }

    syncSlider() {
        this.sliderEl.value = String(this.video.volume);
        this.sliderEl.setAttribute('aria-valuenow', String(Math.round(this.video.volume * 100)));
    }

    updateIcon() {
        const isMuted = this.video.muted || this.video.volume === 0;
        const high = this.btnMute.querySelector('.cfh-icon-vol-high');
        const mute = this.btnMute.querySelector('.cfh-icon-vol-mute');

        if (high) high.hidden = isMuted;
        if (mute) mute.hidden = !isMuted;

        this.btnMute.setAttribute('aria-label', isMuted ? 'Unmute' : 'Mute');
        this.btnMute.setAttribute('aria-pressed', String(isMuted));
    }

    destroy() {
        this.listeners.forEach(([target, event, handler]) => {
            target.removeEventListener(event, handler);
        });
        this.listeners = [];
    }
}
