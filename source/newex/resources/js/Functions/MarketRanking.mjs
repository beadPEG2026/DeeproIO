// Display-only ranking. Never change market, order or balance state.
export const RANKS = ['favorites', 'hot', 'gainers', 'losers', 'new'];
export const SECTORS = [
    ['stock_token', 'Stock tokens'], ['is_meme', 'Meme'], ['is_layer_one', 'Layer 1'],
    ['is_layer_two', 'Layer 2'], ['is_innovation', 'Innovation'], ['is_ai', 'AI'],
    ['is_defi', 'DeFi'], ['is_gamefi', 'GameFi'], ['is_pow', 'PoW'],
    ['is_fan_tokens', 'Fan Tokens'], ['is_nft', 'NFT'],
];
export function numeric(value) {
    if (value === null || value === undefined || typeof value === 'boolean' || String(value).trim() === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}
export function enabled(value) { return value === true || value === 1 || value === '1'; }
export function matchesMarketSearch(market, search) {
    const query = search.trim().toLowerCase();
    if (!query) return true;
    // A two-asset query finds the same order book in either written direction.
    const pair = query.split(/[\s/→⇄-]+/).filter(Boolean);
    const symbols = [market.base_currency, market.quote_currency].map(s => String(s || '').toLowerCase());
    if (pair.length === 2 && pair[0] !== pair[1] && pair.every(s => symbols.includes(s))) return true;
    return [market.name, market.base_currency_name, market.base_currency, market.asset?.name, market.asset?.ticker, market.asset?.symbol].filter(Boolean).join(' ').toLowerCase().includes(query);
}
export function visibleMarkets(markets = [], product = 'spot') {
    return markets.filter(m => m && m.name && m.base_currency && m.quote_currency && enabled(m.status)
        && (product !== 'futures' || enabled(m.has_futures))
        && (product !== 'options' || enabled(m.has_options)));
}
export function readFavorites(raw) {
    try { const list = JSON.parse(raw); return Array.isArray(list) ? [...new Set(list.filter(n => typeof n === 'string'))] : []; }
    catch (_) { return []; }
}
export function rankedMarkets(markets, {rank = 'hot', product = 'spot', quote = 'USDT', sector = '', search = '', favorites = [], now = Date.now()} = {}) {
    const query = search.trim().toLowerCase();
    const rows = visibleMarkets(markets, product).filter(m => {
        if (quote && m.quote_currency !== quote) return false;
        if (sector && (!SECTORS.some(([key]) => key === sector) || !enabled(m[sector]))) return false;
        if (!matchesMarketSearch(m, query)) return false;
        const change = numeric(m.change), listed = numeric(m.listed);
        if (rank === 'favorites' && !favorites.includes(m.name)) return false;
        if (rank === 'gainers' && !(change > 0)) return false;
        if (rank === 'losers' && !(change < 0)) return false;
        if (rank === 'new' && !(listed > 0 && listed <= now / 1000)) return false;
        return true;
    });
    const field = rank === 'new' ? 'listed' : ['gainers', 'losers'].includes(rank) ? 'change' : 'qVolume';
    return rows.sort((a, b) => {
        const x = numeric(a[field]), y = numeric(b[field]);
        if (x === null && y !== null) return 1;
        if (y === null && x !== null) return -1;
        return (x !== null && y !== null ? (rank === 'losers' ? x - y : y - x) : 0) || a.name.localeCompare(b.name);
    });
}
export function stableMarketOrder(rows, order = []) {
    const indices = new Map(order.map((name, i) => [name, i]));
    return [...rows].sort((a, b) => (indices.get(a.name) ?? Infinity) - (indices.get(b.name) ?? Infinity) || a.name.localeCompare(b.name));
}
