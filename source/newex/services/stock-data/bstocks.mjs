import {readFileSync} from 'node:fs';

// Exchange tickers alone do not identify a chain asset. Only reviewed issuer mappings are eligible.
const registry = JSON.parse(readFileSync(new URL('../../resources/data/bstocks.json', import.meta.url), 'utf8')).assets;
export const bstockByContract = contract => registry.find(a => a.contract === contract.toLowerCase());
export function isBstock(asset) {
    return asset.listingTemplate === 'binance-bstocks-v1';
}
export function verifyBstock(asset) {
    const verified = bstockByContract(asset.contract);
    if (!verified || ['symbol','ticker','chainId','decimals','listingTemplate','marketSource','exchangeSymbol']
        .some(k => asset[k] !== verified[k])) throw Error('bstock_identity_mismatch');
    return verified;
}
export async function spotRead(api, path, params) {
    return api.alphaRead('https://api.binance.com/api/v3/' + path + '?' + new URLSearchParams(params));
}
export async function spotMarket(api, asset) {
    verifyBstock(asset);
    return api.cached('spot:mapping:' + asset.symbol, 60, async () => {
        const response = await spotRead(api, 'exchangeInfo', {symbol: asset.exchangeSymbol});
        const found = response.symbols?.filter(m => m.symbol === asset.exchangeSymbol);
        if (found?.length !== 1 || found[0].baseAsset !== asset.symbol || found[0].quoteAsset !== 'USDT') throw Error('bstock_market_mismatch');
        const m = found[0];
        if (m.status !== 'TRADING' || m.isSpotTradingAllowed !== true) throw Error('bstock_market_closed');
        return {symbol: m.symbol, contract: asset.contract, chainId: asset.chainId, denomination: 1, mulPoint: 1,
            filters: m.filters, quantityPrecision: m.baseAssetPrecision, pricePrecision: m.quoteAssetPrecision};
    });
}
export async function spotQuote(api, asset) {
    const m = await spotMarket(api, asset);
    const t = await spotRead(api, 'ticker/24hr', {symbol: m.symbol});
    if (t.symbol !== m.symbol || ['lastPrice','highPrice','lowPrice'].some(k => !Number.isFinite(Number(t[k])) || Number(t[k]) <= 0)
        || !Number.isFinite(Number(t.priceChangePercent))) throw Error('invalid_bstock_quote');
    return {symbol: asset.symbol, price: t.lastPrice, change: t.priceChangePercent, high: t.highPrice, low: t.lowPrice,
        sharesMultiplier: '1', underlyingPrice: null, marketStatus: 'TRADING', openState: true, reasonCode: null,
        currency: 'USDT', source: 'Binance Spot · bStocks', receivedAt: new Date().toISOString()};
}
