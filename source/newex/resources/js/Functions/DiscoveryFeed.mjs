import {enabled, numeric, rankedMarkets} from './MarketRanking.mjs';

export function popularMarkets(markets, limit = 3) {
    return rankedMarkets(Array.isArray(markets) ? markets : [], {quote:'USDT', rank:'hot'})
        .filter(m => !enabled(m.stock_token) && enabled(m.trade_status) && numeric(m.last) > 0 && numeric(m.qVolume) > 0)
        .slice(0, limit);
}

export function newsIsStale(snapshot, now = Date.now()) {
    const fetched = Date.parse(snapshot?.fetchedAt || '');
    return snapshot?.state !== 'fresh' || !Number.isFinite(fetched) || now - fetched >= 900000;
}

export function safeNewsItems(items, locale = null) {
    const seen = new Set();
    return (Array.isArray(items) ? items : []).filter(item => {
        if (!item || typeof item.title !== 'string' || !item.title.trim() || !Number.isFinite(Date.parse(item.publishedAt))) return false;
        if (typeof item.url !== 'string' || !/^https:\/\/(?:cointelegraph\.com\/news|www\.panewslab\.com\/(?:zh|zh-hant|ja)\/articles)\/[a-zA-Z0-9-]+$/.test(item.url) || seen.has(item.url)) return false;
        if (locale && locale !== 'en' && item.locale !== locale) return false;
        seen.add(item.url); return true;
    }).slice(0,12);
}
