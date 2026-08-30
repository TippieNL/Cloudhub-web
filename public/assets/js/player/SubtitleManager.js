import { VTTParser } from './VTTParser.js';

export class SubtitleManager {
    constructor(video, displayEl, settings) {
        this.video = video;
        this.displayEl = displayEl;
        this.settings = settings;
        this.cues = [];
        this.currentUrl = '';
        this.loadToken = 0;
        this.handler = () => this.updateCues();

        this.video.addEventListener('timeupdate', this.handler);
        this.video.addEventListener('seeked', this.handler);
    }

    async loadSubtitleTrack(url) {
        const token = ++this.loadToken;
        this.currentUrl = url || '';

        if (!url || url === 'off') {
            this.cues = [];
            this.displayEl.replaceChildren();
            return;
        }

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(`Subtitle request failed (${response.status})`);
            }

            const text = await response.text();

            if (token !== this.loadToken || this.currentUrl !== url) return;

            this.cues = VTTParser.parse(text);
            this.updateCues();
        } catch (error) {
            if (token !== this.loadToken) return;
            this.cues = [];
            this.displayEl.replaceChildren();
            console.warn('[CloudHub Player] Failed loading subtitles:', error);
        }
    }

    updateCues() {
        if (!this.cues.length) {
            this.displayEl.replaceChildren();
            return;
        }

        const currentTime = this.video.currentTime;
        const matching = this.cues.filter((cue) =>
            currentTime >= cue.start && currentTime < cue.end
        );

        const fragment = document.createDocumentFragment();

        matching.forEach((cue) => {
            const element = document.createElement('div');
            element.className = 'cfh-subtitle-cue';
            element.textContent = cue.text;
            fragment.appendChild(element);
        });

        this.displayEl.replaceChildren(fragment);
    }

    destroy() {
        this.loadToken += 1;
        this.video.removeEventListener('timeupdate', this.handler);
        this.video.removeEventListener('seeked', this.handler);
        this.displayEl.replaceChildren();
    }
}
