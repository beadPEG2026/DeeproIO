const storageKey = 'deepro:recipient-scan';
const ttl = 10 * 60 * 1000;
const evmNetworks = [2, 3, 5, 6, 15, 16, 24, 25];
const chainNetworks = {1: [2, 3], 56: [5, 6], 137: [15, 16], 196: [24, 25]};
const base58 = /^[1-9A-HJ-NP-Za-km-z]{32,44}$/;
const bitcoin = /^(?:[13][1-9A-HJ-NP-Za-km-z]{25,34}|bc1[ac-hj-np-z02-9]{11,87}|BC1[AC-HJ-NP-Z02-9]{11,87})$/;

// Recognize recipient syntax only. No URL navigation, amount, Memo, contract call or signing.
// A network is never selected from the QR. Its hints only constrain the later user choice.
function parseRecipient(value) {
    const text = String(value || '').trim();
    if (!text || text.length > 1024 || /\s|[<>"'\\]/.test(text)) throw new Error('Invalid wallet address');
    let match = text.match(/^ethereum:(?:pay-)?(0x[a-fA-F0-9]{40})(?:@(\d+))?(?:\?[^#]*)?$/i);
    if (match) {
        const networks = match[2] ? chainNetworks[Number(match[2])] : evmNetworks;
        if (!networks) throw new Error('QR code does not match the selected network.');
        return {address: match[1], networks: [...networks]};
    }
    match = text.match(/^(bitcoin|tron|solana):([^?#]+)(?:\?[^#]*)?$/i);
    if (match) {
        const scheme = match[1].toLowerCase(), address = match[2];
        if (scheme === 'bitcoin' && bitcoin.test(address)) return {address, networks: [9, 17]};
        if (scheme === 'tron' && /^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(address)) return {address, networks: [7, 8]};
        if (scheme === 'solana' && base58.test(address)) return {address, networks: [20, 21]};
        throw new Error('Invalid wallet address');
    }
    if (/^0x[a-fA-F0-9]{40}$/.test(text)) return {address: text, networks: [...evmNetworks]};
    if (/^xko[a-fA-F0-9]{40}$/i.test(text)) return {address: '0x' + text.slice(3), networks: [24, 25]};
    if (/^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(text)) return {address: text, networks: [7, 8]};
    if (bitcoin.test(text)) return {address: text, networks: [9, 17]};
    if (/^(?:EQ|UQ|kQ|0Q)[A-Za-z0-9_-]{46}$/.test(text)) return {address: text, networks: [23]};
    if (/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/.test(text)) return {address: text, networks: [22]};
    if (base58.test(text)) return {address: text, networks: [20, 21]};
    throw new Error('Invalid wallet address');
}
export function scannedRecipient(value) {
    const result=parseRecipient(value);
    // Preserve explicit chain hints, but strip every payment/amount parameter.
    const text=String(value).trim();
    result.source=/^(ethereum|bitcoin|tron|solana):/i.test(text) ? text.split('?')[0] : text;
    return result;
}
export function storeScannedRecipient(recipient, storage = window.sessionStorage, crypto = window.crypto, now = Date.now()) {
    const checked = scannedRecipient(recipient.source || recipient.address);
    if (!Array.isArray(recipient.networks) || !recipient.networks.length || recipient.networks.some(id => !checked.networks.includes(id))) throw new Error('Invalid wallet address');
    const token = Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
    storage.setItem(storageKey, JSON.stringify({token, source:checked.source, address: checked.address, networks: recipient.networks, expires: now + ttl}));
    return token;
}
export function takeScannedRecipient(token, storage = window.sessionStorage, now = Date.now()) {
    if (!/^[a-f0-9]{32}$/.test(token || '')) return null;
    const raw = storage.getItem(storageKey);
    if (!raw) return null;
    let entry;
    try { entry = JSON.parse(raw); } catch (_) { storage.removeItem(storageKey); return null; }
    if (!entry || typeof entry !== 'object') { storage.removeItem(storageKey); return null; }
    if (entry.token !== token) return null;
    storage.removeItem(storageKey);
    if (!Number.isFinite(entry.expires) || entry.expires <= now || entry.expires > now + ttl) return null;
    const checked = scannedRecipient(entry.source || entry.address);
    if (!Array.isArray(entry.networks) || !entry.networks.length || entry.networks.some(id => !checked.networks.includes(id))) return null;
    return {source:checked.source, address: checked.address, networks: entry.networks};
}
