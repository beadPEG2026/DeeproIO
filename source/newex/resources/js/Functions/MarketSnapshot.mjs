export const quoteFields = ['last', 'low', 'high', 'volume', 'qVolume', 'change', 'trading_session', 'price_stale'];
export function snapshotTime(value) {
    if (typeof value === 'number' || (typeof value === 'string' && /^\d+(\.\d+)?$/.test(value))) {
        const n = Number(value);
        return Number.isFinite(n) && n > 0 ? (n < 1e11 ? n * 1000 : n) : 0;
    }
    // A timezone-less database date is ambiguous; never assume the user's timezone
    // or invent UTC. Current server snapshots include their explicit UTC offset.
    const n = typeof value === 'string' && /T.*(?:Z|[+-]\d{2}:\d{2})$/i.test(value) ? Date.parse(value) : NaN;
    return Number.isFinite(n) && n > 0 ? n : 0;
}
export function marketSnapshotTime(market) {
    return snapshotTime(market.updated_at || market.changed_at || market.server_time);
}
export function isOlderSnapshot(market, currentTime = 0) {
    const time = marketSnapshotTime(market);
    return currentTime > 0 && (!time || time < currentTime);
}
export function pickQuote(market) {
    const quote = {};
    quoteFields.forEach(key => { if (Object.prototype.hasOwnProperty.call(market, key)) quote[key] = market[key]; });
    return quote;
}
