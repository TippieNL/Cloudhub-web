import { VTTParser } from './VTTParser.js';

/**
 * The subtitle tracks found beside a video, and the one that is showing.
 *
 * Cues are drawn into an overlay rather than handed to a <track> element. That
 * is what makes them survive fullscreen and Picture-in-Picture the way the rest
 * of the controls do, and it is the only way to style them consistently across
 * browsers -- native cue rendering ignores most of what is asked of it.
 *
 * The choice is remembered as a *language*, not as a file: somebody who turns
 * on Dutch subtitles for one film means it for the next one too, and the next
 * film's Dutch track is a different file with a different name.
 */
export class SubtitleManager {
    /** Cues drawn at once. Overlapping cues are a stack, not a screenful. */
    static MAX_VISIBLE = 4;

    constructor(video, displayEl, settings) {
        this.video = video;
        this.displayEl = displayEl;
        this.settings = settings;

        this.tracks = [];
        this.activeId = '';
        this.cues = [];
        this.rendered = null;
        this.currentUrl = '';
        this.loadToken = 0;
        this.changeHandlers = [];

        this.handler = () => this.updateCues();
        this.video.addEventListener('timeupdate', this.handler);
        this.video.addEventListener('seeked', this.handler);
    }

    /* ---- tracks ---------------------------------------------------------- */

    /**
     * Take the list the page was rendered with and turn on whatever the stored
     * preference asks for. Returns the track that ended up showing, if any.
     */
    setTracks(tracks) {
        this.tracks = (Array.isArray(tracks) ? tracks : [])
            .filter((track) => track && typeof track.url === 'string' && track.url !== '')
            .map((track, index) => ({
                id: String(track.id || `track-${index}`),
                url: track.url,
                label: String(track.label || track.name || `Subtitles ${index + 1}`),
                language: String(track.language || ''),
                forced: Boolean(track.forced)
            }));

        const wanted = this.preferredTrack();
        if (wanted) this.select(wanted.id, { remember: false });
        return wanted;
    }

    get activeTrack() {
        return this.tracks.find((track) => track.id === this.activeId) || null;
    }

    /**
     * The track the stored preference asks for.
     *
     * 'off' means off, a language code means that language, and 'first' is what
     * a track with no language in its name is remembered as -- there is nothing
     * else to recognise it by on the next video.
     */
    preferredTrack() {
        if (!this.tracks.length) return null;

        const preference = String(this.settings?.get('subtitleLang') ?? 'off');
        if (preference === 'off' || preference === '') return null;
        if (preference === 'first') return this.tracks[0];

        const base = preference.split('-')[0].toLowerCase();
        const matches = this.tracks.filter(
            (track) => track.language.toLowerCase().split('-')[0] === base
        );
        if (!matches.length) return null;

        // An exact match beats the base language, and a full track beats a
        // forced one: "forced" subtitles only translate the foreign lines.
        return matches.find((track) => track.language.toLowerCase() === preference.toLowerCase() && !track.forced)
            || matches.find((track) => !track.forced)
            || matches[0];
    }

    /** Show one track by id, or turn subtitles off with '' or 'off'. */
    select(id, { remember = true } = {}) {
        const track = this.tracks.find((candidate) => candidate.id === id) || null;

        this.activeId = track ? track.id : '';
        if (remember && this.settings) {
            this.settings.set('subtitleLang', track ? (track.language || 'first') : 'off');
        }

        this.loadSubtitleTrack(track ? track.url : '');
        this.emitChange();
        return track;
    }

    /** Off, or back on with the track that was last showing. */
    toggle() {
        if (this.activeId) {
            this.lastActiveId = this.activeId;
            return this.select('');
        }

        const track = this.tracks.find((candidate) => candidate.id === this.lastActiveId)
            || this.preferredTrack()
            || this.tracks[0]
            || null;
        return track ? this.select(track.id) : null;
    }

    /** Called whenever the showing track changes, so a menu can follow it. */
    onChange(handler) {
        if (typeof handler === 'function') this.changeHandlers.push(handler);
    }

    emitChange() {
        this.changeHandlers.forEach((handler) => {
            try {
                handler(this.activeTrack);
            } catch (error) {
                console.warn('[CloudHub Player] Subtitle listener failed:', error);
            }
        });
    }

    /* ---- cues ------------------------------------------------------------ */

    async loadSubtitleTrack(url) {
        const token = ++this.loadToken;
        this.currentUrl = url || '';

        if (!url || url === 'off') {
            this.cues = [];
            this.clear();
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
            this.rendered = null;
            this.updateCues();
        } catch (error) {
            if (token !== this.loadToken) return;
            this.cues = [];
            this.clear();
            console.warn('[CloudHub Player] Failed loading subtitles:', error);
        }
    }

    /** The first cue that has not ended yet, found without walking the file. */
    indexAt(time) {
        let low = 0;
        let high = this.cues.length;

        while (low < high) {
            const middle = (low + high) >> 1;
            if (this.cues[middle].end <= time) low = middle + 1; else high = middle;
        }
        return low;
    }

    updateCues() {
        if (!this.cues.length) {
            this.clear();
            return;
        }

        const currentTime = this.video.currentTime;
        const showing = [];

        for (let i = this.indexAt(currentTime); i < this.cues.length; i++) {
            const cue = this.cues[i];
            if (cue.start > currentTime) break;          // sorted by start
            if (cue.end > currentTime) showing.push(cue.text);
            if (showing.length >= SubtitleManager.MAX_VISIBLE) break;
        }

        // timeupdate fires several times a second and the cue almost never
        // changes between two of them. Rebuilding the DOM anyway made the text
        // flicker on some Android browsers, quite apart from the work.
        const signature = showing.join('\u0000');
        if (signature === this.rendered) return;
        this.rendered = signature;

        const fragment = document.createDocumentFragment();
        showing.forEach((text) => {
            const element = document.createElement('div');
            element.className = 'cfh-subtitle-cue';
            element.textContent = text;
            fragment.appendChild(element);
        });

        this.displayEl.replaceChildren(fragment);
    }

    clear() {
        if (this.rendered === '') return;
        this.rendered = '';
        this.displayEl.replaceChildren();
    }

    destroy() {
        this.loadToken += 1;
        this.changeHandlers = [];
        this.video.removeEventListener('timeupdate', this.handler);
        this.video.removeEventListener('seeked', this.handler);
        this.displayEl.replaceChildren();
    }
}
