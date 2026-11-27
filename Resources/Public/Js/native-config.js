// Validation of the data the server hands to the browser (data-vo-native). Pure functions, no DOM.

const ALLOWED_HOST = 'videooptimizer.eu';
const ACCENT = /^(?:#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([0-9.,%\s/-]+\))$/i;

/**
 * Only https URLs on videooptimizer.eu (or a sub-domain) are loaded into an iframe, <video> or
 * poster. Returns the normalized URL or '' when not allowed.
 */
export function safeUrl(raw) {
    let url;
    try {
        url = new URL(raw);
    } catch {
        return '';
    }
    const host = url.hostname.toLowerCase();
    if (url.protocol !== 'https:' || (host !== ALLOWED_HOST && !host.endsWith('.' + ALLOWED_HOST))) {
        return '';
    }
    return url.href;
}

/** Native player settings from a data-vo-native attribute; null when unusable. */
export function parseConfig(raw) {
    let data;
    try {
        data = JSON.parse(raw || '');
    } catch {
        return null;
    }
    if (!data || typeof data !== 'object') {
        return null;
    }
    const cfg = {
        hls: data.hls ? safeUrl(data.hls) : '',
        mp4: data.mp4 ? safeUrl(data.mp4) : '',
        poster: data.poster ? safeUrl(data.poster) : '',
        controls: data.controls !== false,
        autoplay: data.autoplay === true,
        muted: data.muted === true,
        loop: data.loop === true,
        preload: ['auto', 'metadata', 'none'].includes(data.preload) ? data.preload : 'metadata',
        accent: typeof data.accent === 'string' && ACCENT.test(data.accent) ? data.accent : '',
        start: Number.isFinite(data.start) && data.start > 0 ? data.start : 0,
        cover: data.cover === true,
        title: typeof data.title === 'string' ? data.title : '',
        unavailable: typeof data.unavailable === 'string' ? data.unavailable : '',
    };
    return cfg.hls || cfg.mp4 ? cfg : null;
}
