export class VTTParser {
    static parse(vttText) {
        const lines = vttText.replace(/\r\n|\r/g, '\n').split('\n');
        const cues = [];
        let i = 0;

        while (i < lines.length && !lines[i].includes('-->')) {
            i++;
        }

        while (i < lines.length) {
            const line = lines[i].trim();
            if (line.includes('-->')) {
                const times = line.split('-->');
                const start = VTTParser.parseTimestamp(times[0].trim());
                const end = VTTParser.parseTimestamp(times[1].trim().split(' ')[0]);

                let text = '';
                i++;
                while (i < lines.length && lines[i].trim() !== '') {
                    text += (text ? '\n' : '') + lines[i].trim();
                    i++;
                }

                if (!isNaN(start) && !isNaN(end)) {
                    cues.push({ start, end, text });
                }
            }
            i++;
        }
        return cues;
    }

    static parseTimestamp(timeStr) {
        const parts = timeStr.split(':');
        let seconds = 0;
        if (parts.length === 3) {
            seconds += parseFloat(parts[0]) * 3600;
            seconds += parseFloat(parts[1]) * 60;
            seconds += parseFloat(parts[2].replace(',', '.'));
        } else if (parts.length === 2) {
            seconds += parseFloat(parts[0]) * 60;
            seconds += parseFloat(parts[1].replace(',', '.'));
        }
        return seconds;
    }
}
