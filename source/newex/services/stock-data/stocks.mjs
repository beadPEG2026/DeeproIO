import { readFileSync } from "node:fs";
import {bstockByContract, isBstock, verifyBstock, spotRead, spotMarket, spotQuote} from './bstocks.mjs';
let catalog = JSON.parse(
    readFileSync(
        new URL("../../resources/data/stock-tokens.json", import.meta.url),
        "utf8"
    )
).assets;
const intervals = new Set(["1m", "5m", "15m", "1h", "4h", "1d"]);
export function registerStockAssets(assets) {
    for (const a of assets) {
        if (!a || !/^[A-Za-z0-9]{2,24}$/.test(a.symbol) || String(a.chainId) !== '56' || !/^0x[0-9a-fA-F]{40}$/.test(a.contract)) throw Error('invalid_stock_identity');
        if (isBstock(a) || a.marketSource === 'binance-spot' || bstockByContract(a.contract) || a.symbol === 'DJTB') verifyBstock(a);
        const old = catalog.find(x=>x.symbol===a.symbol);
        if (old && old.contract.toLowerCase()!==a.contract.toLowerCase()) throw Error('stock_identity_conflict');
        if (!old) catalog.push(a);
    }
}
export function stockAsset(symbol) {
    const asset = catalog.find((a) => a.symbol === symbol);
    if (!asset) throw new Error("unknown_stock");
    return asset;
}
export function normalizeStockQuote(asset, data, receivedAt) {
    if (data.symbol !== asset.symbol || data.ticker !== asset.ticker)
        throw new Error("stock_identity_mismatch");
    const t = data.tokenInfo;
    if (
        !t ||
        !Number.isFinite(Number(t.price)) ||
        Number(t.price) <= 0 ||
        !Number.isFinite(Number(t.sharesMultiplier)) ||
        Number(t.sharesMultiplier) <= 0
    )
        throw new Error("invalid_stock_quote");
    const numberOrNull = (v) =>
        v !== null && v !== undefined && v !== "" && Number.isFinite(Number(v))
            ? String(v)
            : null;
    // Upstream volume24h represents US equity turnover. It is never a token trading volume.
    return {
        symbol: asset.symbol,
        price: String(t.price),
        change: numberOrNull(t.priceChangePct24h),
        high: numberOrNull(t.priceHigh24h),
        low: numberOrNull(t.priceLow24h),
        sharesMultiplier: String(t.sharesMultiplier),
        underlyingPrice: numberOrNull(data.stockInfo?.price),
        marketStatus: data.statusInfo?.marketStatus || "unknown",
        openState: data.statusInfo?.openState === true,
        reasonCode: data.statusInfo?.reasonCode || null,
        source: "Binance Wallet · Ondo",
        receivedAt,
    };
}
export function normalizeStockCandles(data, now = Date.now()) {
    if (!Array.isArray(data?.klineInfos))
        throw new Error("invalid_stock_candles");
    const rows = data.klineInfos
        .map((r) => {
            if (!Array.isArray(r) || r.length < 7)
                throw new Error("invalid_stock_candle");
            const [time, open, high, low, close] = r.map(Number),
                end = Number(r[6]);
            if (
                ![time, open, high, low, close, end].every(Number.isFinite) ||
                !Number.isSafeInteger(time) ||
                time <= 0 ||
                time > now + 60000 ||
                end < time ||
                low <= 0 ||
                high < Math.max(open, close) ||
                low > Math.min(open, close)
            )
                throw new Error("invalid_stock_candle");
            return { time, open, high, low, close, closeTime: end };
        })
        .sort((a, b) => a.time - b.time);
    if (new Set(rows.map((r) => r.time)).size !== rows.length)
        throw new Error("duplicate_stock_candles");
    return rows;
}
export function normalizeStockTrades(rows, now = Date.now()) {
    if (!Array.isArray(rows)) throw new Error('invalid_stock_trades');
    const trades = new Map();
    for (const row of rows) {
        if (!Number.isSafeInteger(row?.a) || row.a < 0 ||
            !Number.isSafeInteger(row.T) || row.T <= 0 || row.T > now + 60000 ||
            ['p', 'q'].some(key => typeof row[key] !== 'string' ||
                !/^\d+(?:\.\d+)?$/.test(row[key]) || !Number.isFinite(Number(row[key])) || Number(row[key]) <= 0)) {
            throw new Error('invalid_stock_trade');
        }
        const trade = {id: String(row.a), price: row.p, quantity: row.q, timestamp: row.T,
            created_at: new Date(row.T).toISOString(),
            side: typeof row.m === 'boolean' ? (row.m ? 'sell' : 'buy') : null};
        if (trades.has(trade.id) && JSON.stringify(trades.get(trade.id)) !== JSON.stringify(trade)) {
            throw new Error('conflicting_stock_trade');
        }
        trades.set(trade.id, trade);
    }
    return [...trades.values()].sort((a, b) => b.timestamp - a.timestamp || Number(b.id) - Number(a.id));
}
export class StockMarket {
    constructor(json) {
        this.json = json;
        this.cache = new Map();
        this.pending = new Map();
    }
    async cached(key, seconds, callback) {
        const old = this.cache.get(key);
        if (old && Date.now() - old.at < seconds * 1000) return old.value;
        if (this.pending.has(key)) return this.pending.get(key);
        const promise = Promise.resolve()
            .then(callback)
            .then((value) => {
                this.cache.set(key, { at: Date.now(), value });
                return value;
            })
            .finally(() => this.pending.delete(key));
        this.pending.set(key, promise);
        return promise;
    }
    async call(version, path, asset, extra = {}) {
        const query = new URLSearchParams({
            chainId: "56",
            contractAddress: asset.contract,
            ...extra,
        });
        let result;
        const url = `https://www.binance.com/bapi/defi/${version}/public/wallet-direct/buw/wallet/${path}/ai?${query}`;
        try {
            result = await this.json(url, undefined, 5000);
        } catch {
            result = await this.json(url, undefined, 5000);
        }
        if (result?.code !== "000000" || !result?.success || !result.data)
            throw new Error("stock_provider_unavailable");
        return result.data;
    }
    async quote(symbol) {
        const a = stockAsset(symbol);
        if (isBstock(a)) return this.cached('quote:' + symbol, 20, () => spotQuote(this, a));
        return this.cached("quote:" + symbol, 20, async () =>
            normalizeStockQuote(
                a,
                await this.call("v2", "market/token/rwa/dynamic", a),
                new Date().toISOString()
            )
        );
    }
    async inspect({contract}) {
        if (!/^0x[0-9a-fA-F]{40}$/.test(contract || '')) throw Error('invalid_contract');
        const reviewed = bstockByContract(contract);
        if (reviewed) {
            const a = {...reviewed, verifiedAt: new Date().toISOString()};
            registerStockAssets([a]);
            const [mapping, depth, quote, candles] = await Promise.all([
                spotMarket(this, a), this.depth({symbol:a.symbol}), this.quote(a.symbol), this.candles({symbol:a.symbol, interval:'5m', limit:10})
            ]);
            if (!depth.bids.length || !depth.asks.length || !candles.data.length) throw Error('stock_market_data_incomplete');
            return {data:{asset:a, mapping, depth, quote, checks:{identity:true, units:true, quotes:true, candles:true, depth:true}}};
        }
        const tokens=await this.cached('alpha:tokens',60,()=>this.alphaRead('https://www.binance.com/bapi/defi/v1/public/wallet-direct/buw/wallet/cex/alpha/all/token/list'));
        const found=(tokens?.data||[]).filter(t=>String(t.chainId)==='56' && String(t.contractAddress).toLowerCase()===contract.toLowerCase());
        if (found.length!==1) throw Error('alpha_identity_not_unique');
        const t=found[0];
        if (t.stockState!==true || !t.rwaInfo?.metaInfo?.ticker || !t.symbol.endsWith('on') || !t.name.includes('(Ondo)') || !Number.isInteger(t.decimals) || t.decimals<0 || t.decimals>18) throw Error('unsupported_stock_template');
        const a={id:t.symbol,symbol:t.symbol,ticker:t.rwaInfo.metaInfo.ticker,name:t.name,issuer:'Ondo Global Markets',chain:'BSC',chainId:56,contract:contract.toLowerCase(),decimals:t.decimals,assetType:t.rwaInfo.metaInfo.assetCode?.startsWith('ETF_')?'etf':'stock',currency:'USD',region:'US',color:'#718378',mark:t.symbol.slice(0,2),tokenId:t.tokenId,issuerUrl:'https://ondo.finance/assets/'+t.symbol.toLowerCase(),explorerUrl:'https://bscscan.com/token/'+contract.toLowerCase(),verifiedAt:new Date().toISOString()};
        registerStockAssets([a]);
        const [mapping,depth,quote,candles]=await Promise.all([this.alphaMarket(a.symbol),this.depth({symbol:a.symbol}),this.quote(a.symbol),this.candles({symbol:a.symbol,interval:'5m',limit:10})]);
        if(!depth.bids.length || !depth.asks.length || !candles.data.length) throw Error('stock_market_data_incomplete');
        return {data:{asset:a,mapping,depth,quote,checks:{identity:true,units:true,quotes:true,candles:true,depth:true}}};
    }
    async alphaRead(url) {
        try { return await this.json(url, undefined, 5000); }
        catch { return await this.json(url, undefined, 5000); }
    }
    async alphaMarket(symbol) {
        const asset = stockAsset(symbol);
        const [tokens, exchange] = await Promise.all([
            this.cached('alpha:tokens', 60, () => this.alphaRead('https://www.binance.com/bapi/defi/v1/public/wallet-direct/buw/wallet/cex/alpha/all/token/list')),
            this.cached('alpha:exchange', 60, () => this.alphaRead('https://www.binance.com/bapi/defi/v1/public/alpha-trade/get-exchange-info')),
        ]);
        if(tokens?.code !== '000000' || exchange?.code !== '000000' || !Array.isArray(tokens.data) || !Array.isArray(exchange.data?.symbols)) throw new Error('alpha_catalog_unavailable');
        const matches = tokens.data.filter(t => String(t.chainId) === String(asset.chainId) && String(t.contractAddress).toLowerCase() === asset.contract.toLowerCase());
        if(matches.length !== 1 || matches[0].symbol !== symbol || !/^ALPHA_[0-9]+$/.test(matches[0].alphaId)) throw new Error('alpha_identity_mismatch');
        const token = matches[0];
        if(token.stockState !== true || token.rwaInfo?.metaInfo?.ticker !== asset.ticker) throw new Error('not_verified_stock');
        if(Number(token.denomination)!==1 || Number(token.mulPoint)!==1 || Number(token.decimals)!==asset.decimals) throw new Error('unsupported_alpha_units');
        if(token.offline || token.fullyDelisted || token.offsell) throw new Error('alpha_market_closed');
        const market = exchange.data.symbols.find(m => m.symbol === token.alphaId+'USDT' && m.baseAsset === token.alphaId && m.quoteAsset === 'USDT');
        if(!market || market.status !== 'TRADING') throw new Error('alpha_market_closed');
        return {symbol:market.symbol, contract:asset.contract, chainId:asset.chainId, denomination:1, mulPoint:1, filters:market.filters, quantityPrecision:market.quantityPrecision, pricePrecision:market.pricePrecision};
    }
    async depth({symbol}) {
        const asset = stockAsset(symbol), spot = isBstock(asset);
        return this.cached('alpha:depth:'+symbol, 3, async () => {
            const market = spot ? await spotMarket(this, asset) : await this.alphaMarket(symbol);
            const response = spot ? await spotRead(this, 'depth', {symbol:market.symbol, limit:'20'})
                : await this.alphaRead('https://www.binance.com/bapi/defi/v1/public/alpha-trade/fullDepth?'+new URLSearchParams({symbol:market.symbol,limit:'20'}));
            const d = spot ? response : response?.data;
            if(!d || (!spot && (response?.code !== '000000' || d.symbol !== market.symbol))) throw new Error('alpha_depth_mismatch');
            if (spot && (!Number.isSafeInteger(d.lastUpdateId) || d.lastUpdateId < 0)) throw Error('invalid_bstock_snapshot');
            // Spot REST supplies no event time: this is explicitly the retrieval time of a book snapshot.
            const eventTime = spot ? Date.now() : d.E;
            // Event time is the last book change, not necessarily this REST fetch.
            // Slow books remain labelled snapshots; never present them as fresh quotes.
            const eventAge=Date.now()-eventTime;
            if(!Number.isSafeInteger(eventTime) || eventAge < -5000 || eventAge > 15*60000) throw new Error('alpha_depth_stale');
            for(const side of ['bids','asks']) {
                if(!Array.isArray(d[side]) || d[side].length>20) throw new Error('invalid_alpha_depth');
                let previous = side === 'bids' ? Infinity : 0;
                for(const row of d[side]) {
                    if(!Array.isArray(row) || row.length !== 2 || row.some(v => typeof v !== 'string' || !/^[0-9]+(?:\.[0-9]+)?$/.test(v) || !Number.isFinite(Number(v)) || Number(v)<=0)) throw new Error('invalid_alpha_depth');
                    const price = Number(row[0]);
                    if(side === 'bids' ? price>=previous : price<=previous) throw new Error('invalid_alpha_depth');
                    previous=price;
                }
            }
            if(d.bids.length && d.asks.length && Number(d.bids[0][0])>=Number(d.asks[0][0])) throw new Error('crossed_alpha_depth');
            // Only unscaled tokens are accepted. This is public depth, not an external fill acknowledgement.
            return {source:spot?'binance-spot':'binance-alpha',source_symbol:market.symbol,symbol,chain_id:market.chainId,contract:market.contract,quote_currency:'USDT',quantity_unit:'token',denomination:market.denomination,mul_point:market.mulPoint,filters:market.filters,executable:false,execution_mode:'platform_internal',received_at:new Date().toISOString(),event_time:eventTime,event_time_kind:spot?'snapshot_received':'book_event',source_stale:eventAge>60000,last_update_id:d.lastUpdateId,bids:d.bids,asks:d.asks};
        });
    }
    async trades({symbol, limit = 30}) {
        const asset = stockAsset(symbol), spot = isBstock(asset);
        if (!Number.isInteger(limit) || limit < 1 || limit > 100) throw new Error('invalid_stock_trade_limit');
        const key = 'alpha:trades:' + symbol + ':' + limit;
        try {
            return await this.cached(key, 3, async () => {
                const market = spot ? await spotMarket(this, asset) : await this.alphaMarket(symbol);
                const response = spot ? await spotRead(this, 'aggTrades', {symbol:market.symbol, limit:String(limit)})
                    : await this.alphaRead('https://www.binance.com/bapi/defi/v1/public/alpha-trade/agg-trades?' +
                    new URLSearchParams({symbol: market.symbol, limit: String(limit)}));
                if (!spot && response?.code !== '000000') throw new Error('stock_trades_unavailable');
                return {source: spot?'binance-spot':'binance-alpha', source_symbol: market.symbol, symbol,
                    chain_id: market.chainId, contract: market.contract, quantity_unit: 'token',
                    quote_currency: 'USDT', received_at: new Date().toISOString(), stale: false,
                    data: normalizeStockTrades(spot?response:response.data).slice(0, limit)};
            });
        } catch (error) {
            // Preserve real timestamps when serving the last successful snapshot during an outage.
            const old = this.cache.get(key);
            if (old && Date.now() - old.at < 86400000) return {...old.value, stale: true};
            throw error;
        }
    }
    async quotes() {
        const data = [];
        // Bounded fan-out to avoid bursts against the provider.
        for (let i = 0; i < catalog.length; i += 5) {
            data.push(
                ...(await Promise.all(
                    catalog.slice(i, i + 5).map(async (a) => {
                        try {
                            return await this.quote(a.symbol);
                        } catch {
                            return { symbol: a.symbol, unavailable: true };
                        }
                    })
                ))
            );
        }
        return {
            source: "Binance",
            data,
            receivedAt: new Date().toISOString(),
        };
    }
    async candles({ symbol, interval = "1h", limit = 120, to = null }) {
        const a = stockAsset(symbol);
        if (
            (to !== null && (!Number.isSafeInteger(to) || to < 0)) ||
            !intervals.has(interval) ||
            !Number.isSafeInteger(limit) ||
            limit < 1 ||
            limit > 300
        )
            throw new Error("invalid_stock_interval");
        if (isBstock(a)) return this.cached(`candles:${symbol}:${interval}:${limit}:${to}`, 15, async () => {
            const m = await spotMarket(this, a);
            const rows = await spotRead(this, 'klines', {symbol:m.symbol, interval, limit:String(limit), ...(to === null ? {} : {endTime:String(to*1000)})});
            return {symbol, interval, currency:'USDT', source:'Binance Spot · bStocks', receivedAt:new Date().toISOString(), data:normalizeStockCandles({klineInfos:rows})};
        });
        return this.cached(
            `candles:${symbol}:${interval}:${limit}:${to}`,
            15,
            async () => ({
                symbol,
                interval,
                currency: "USD",
                source: "Binance Wallet · Ondo",
                receivedAt: new Date().toISOString(),
                data: normalizeStockCandles(
                    await this.call("v1", "dex/market/token/kline", a, {
                        interval,
                        limit: String(limit),
                        ...(to === null ? {} : { endTime: String(to * 1000) }),
                    })
                ),
            })
        );
    }
}
