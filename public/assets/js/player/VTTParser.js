/**
 * WebVTT into plain cues.
 *
 * The player draws subtitles itself rather than handing the file to a <track>
 * element, so what comes out of here is text, not markup: tags are removed and
 * the entities behind them decoded, because the cue is rendered with
 * textContent and `&amp;` would otherwise be read out as four characters.
 */
export class VTTParser {
    /** Past this a file is not a subtitle track, and the menu is not the place to find out. */
    static MAX_CUES = 20000;

    static parse(vttText) {
        const lines = String(vttText ?? '').replace(/\r\n|\r/g, '\n').split('\n');
        const cues = [];
        let i = 0;

        while (i < lines.length && !lines[i].includes('-->')) {
            i++;
        }

        while (i < lines.length && cues.length < VTTParser.MAX_CUES) {
            const line = lines[i];

            if (line.includes('-->')) {
                const times = line.split('-->');
                const start = VTTParser.parseTimestamp(times[0].trim());
                // Anything after the end timestamp is cue settings, not time.
                const end = VTTParser.parseTimestamp(times[1].trim().split(/\s+/)[0]);

                const text = [];
                i++;
                while (i < lines.length && lines[i].trim() !== '') {
                    text.push(VTTParser.cueText(lines[i].trim()));
                    i++;
                }

                // A cue that ends before it starts, or has no text, would only
                // ever flicker; drop it here rather than at every timeupdate.
                if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
                    const body = text.join('\n').trim();
                    if (body) cues.push({ start, end, text: body });
                }
            }
            i++;
        }

        // The cue lookup during playback walks forward from a binary search,
        // which needs the file in order. Most are; some are not.
        cues.sort((a, b) => a.start - b.start || a.end - b.end);
        return cues;
    }

    static parseTimestamp(timeStr) {
        const parts = String(timeStr ?? '').split(':');
        if (parts.length < 2 || parts.length > 3) return NaN;

        const seconds = parts.map((part) => parseFloat(part.replace(',', '.')));
        if (seconds.some((value) => !Number.isFinite(value) || value < 0)) return NaN;

        return parts.length === 3
            ? seconds[0] * 3600 + seconds[1] * 60 + seconds[2]
            : seconds[0] * 60 + seconds[1];
    }

    /**
     * One line of cue text as it will be shown.
     *
     * `<i>`, `<c.yellow>`, `<v Roger>` and the timestamp tags of karaoke cues
     * all carry formatting this player does not draw, so they come out rather
     * than being shown as angle brackets.
     */
    static cueText(raw) {
        return VTTParser.decodeEntities(raw.replace(/<[^>]*>/g, ''));
    }

    static decodeEntities(text) {
        if (!text.includes('&')) return text;

        const named = {
            amp: '&', lt: '<', gt: '>', quot: '"', apos: "'",
            nbsp: ' ', lrm: '‎', rlm: '‏'
        };

        return text.replace(/&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi, (match, entity) => {
            const lower = entity.toLowerCase();
            if (lower in named) return named[lower];

            if (lower.startsWith('#')) {
                const code = lower.startsWith('#x')
                    ? parseInt(lower.slice(2), 16)
                    : parseInt(lower.slice(1), 10);
                // Surrogates and out-of-range code points throw in
                // fromCodePoint; a subtitle is not worth an exception.
                if (Number.isFinite(code) && code > 0 && code <= 0x10ffff
                    && !(code >= 0xd800 && code <= 0xdfff)) {
                    return String.fromCodePoint(code);
                }
            }

            return match;
        });
    }
}
