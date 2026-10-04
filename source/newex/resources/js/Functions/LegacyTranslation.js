// Shared catalogue lookup for legacy labels initialized outside a component method.
// Stored business values and user content must never be passed through this helper.
let cachedSource;
let cachedMessages = {};
export function legacyText(key, params = {}) {
    if (typeof window === 'undefined') return key;
    if (window.Vue && window.Vue.$i18n) return window.Vue.$i18n.t(key, params);
    if (cachedSource !== window.TRANSLATIONS) {
        cachedSource = window.TRANSLATIONS;
        try { cachedMessages = JSON.parse(cachedSource || '{}'); } catch (_) { cachedMessages = {}; }
    }
    const text = Object.prototype.hasOwnProperty.call(cachedMessages, key) ? cachedMessages[key] : key;
    return String(text).replace(/\{(\w+)\}/g, (match, name) => Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match);
}
