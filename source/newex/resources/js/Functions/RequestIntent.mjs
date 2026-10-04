// Keep the original submitted payload while its outcome is unknown. Scope includes the
// current account and flow; session storage survives reloads without crossing tabs.
const memory = new Map();
const prefix = 'deepro-request-intent:';
function canonical(value) {
    if (Array.isArray(value)) return value.map(canonical);
    if (value && typeof value === 'object') return Object.keys(value).sort().reduce((out, key) => {
        if (value[key] !== undefined) out[key] = canonical(value[key]);
        return out;
    }, {});
    return value;
}
function uuid() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(n => n.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}
export function requestIntent(scope, intent, payload) {
    const fingerprint = JSON.stringify(canonical(intent));
    let current = memory.get(scope);
    if (!current) { try { current = JSON.parse(sessionStorage.getItem(prefix + scope)); } catch (_) {} }
    if (!current || current.fingerprint !== fingerprint || !/^[\da-f-]{36}$/i.test(current.key || '')) {
        current = {key:uuid(), fingerprint, payload:JSON.parse(JSON.stringify(payload))};
    }
    memory.set(scope, current);
    try { sessionStorage.setItem(prefix + scope, JSON.stringify(current)); } catch (_) {}
    return {key:current.key, payload:JSON.parse(JSON.stringify(current.payload))};
}
export function completeIntent(scope, key) {
    const current = memory.get(scope);
    if (!current || current.key !== key) return;
    memory.delete(scope);
    try { sessionStorage.removeItem(prefix + scope); } catch (_) {}
}
export function spotIntent(payload) {
    // A market buy spends the entered quote amount. Live price-derived base size
    // is not a user edit and must not generate another order on timeout retry.
    const intent = {...payload};
    delete intent.client_order_id;
    if (intent.type === 'market') {
        delete intent.price; delete intent.total;
        if (intent.side === 'buy' && intent.quoteQuantity != null) delete intent.quantity;
        else delete intent.quoteQuantity;
    }
    return intent;
}
