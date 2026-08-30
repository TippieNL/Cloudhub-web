export class PlayerSettings {
    constructor() {
        this.PREFIX = 'cfh_player_';
        this.defaults = {
            volume: 1.0,
            muted: false,
            speed: 1.0,
            quality: 'auto',
            subtitleLang: 'off'
        };
    }

    get(key) {
        try {
            const val = localStorage.getItem(this.PREFIX + key);
            return val ? JSON.parse(val) : this.defaults[key];
        } catch (e) {
            return this.defaults[key];
        }
    }

    set(key, val) {
        try {
            localStorage.setItem(this.PREFIX + key, JSON.stringify(val));
        } catch (e) {
            console.warn('LocalStorage save failed:', e);
        }
    }
}
