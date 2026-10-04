import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {StockMarket, normalizeStockTrades} from '../../services/stock-data/stocks.mjs';
const row = (a = 1) => ({a, p: '341.52000000', q: '0.21920000', T: 1700000000000 + a, m: false});

test('real trade IDs, units, times, direction, sorting and deduplication are preserved', () => {
    const result = normalizeStockTrades([row(1), row(2), row(1)]);
    assert.deepEqual(result.map(x => x.id), ['2', '1']);
    assert.equal(result[0].quantity, '0.21920000');
    assert.equal(result[0].price, '341.52000000');
    assert.equal(result[0].side, 'buy');
    assert.equal(result[0].timestamp, row(2).T);
    assert.equal(normalizeStockTrades([{...row(), m: true}])[0].side, 'sell');
    assert.equal(normalizeStockTrades([{...row(), m: undefined}])[0].side, null);
});
test('reject invalid values, future events, conflicting duplicate IDs and non-lists', () => {
    for (const bad of [{a: -1}, {a: 2 ** 54}, {p: 'NaN'}, {p: '-1'}, {q: '0'}, {q: '1e10'}, {T: Date.now() + 120000}]) {
        assert.throws(() => normalizeStockTrades([{...row(), ...bad}]));
    }
    assert.throws(() => normalizeStockTrades([row(), {...row(), q: '2'}]));
    assert.throws(() => normalizeStockTrades({}));
});
test('stock adapter caches and coalesces requests; failure retains original timestamps', async () => {
    let calls = 0, fail = false;
    const api = new StockMarket(async url => {
        assert.match(url, /agg-trades\?symbol=ALPHA_740USDT&limit=30$/);
        calls++;
        if (fail) throw new Error('provider_down');
        return {code: '000000', data: [row()]};
    });
    api.alphaMarket = async () => ({symbol: 'ALPHA_740USDT', chainId: 56, contract: 'verified-contract'});
    const [a, b] = await Promise.all([api.trades({symbol: 'GOOGLon'}), api.trades({symbol: 'GOOGLon'})]);
    assert.deepEqual(a, b); assert.equal(calls, 1);
    assert.equal(a.source, 'binance-alpha'); assert.equal(a.quantity_unit, 'token');
    fail = true;
    api.cache.get('alpha:trades:GOOGLon:30').at -= 5000;
    const stale = await api.trades({symbol: 'GOOGLon'});
    assert.equal(stale.stale, true); assert.deepEqual(stale.data, a.data); assert.equal(stale.received_at, a.received_at);
    await assert.rejects(api.trades({symbol: 'NOT_REGISTERED'}));
    await assert.rejects(api.trades({symbol: 'GOOGLon', limit: 1000}));
});
test('stock adapter requires identity verification before obtaining trades', async () => {
    let called = false;
    const api = new StockMarket(async () => {called = true; return {};});
    api.alphaMarket = async () => {throw new Error('alpha_identity_mismatch');};
    await assert.rejects(api.trades({symbol: 'GOOGLon'}), /identity/);
    assert.equal(called, false);
});

function mount(get) {
    const src = readFileSync(new URL('../../resources/js/Mixins/StockTradePolling.mjs', import.meta.url), 'utf8')
        .replace("import axios from 'axios';", '').replace('export default', 'globalThis.mixin =');
    const ctx = {axios: {get}, setInterval: () => 1, clearInterval() {}, document: {hidden: false}};
    vm.runInNewContext(src, ctx);
    const mixin = ctx.mixin;
    const instance = {...mixin.data(), market: {name: 'GOOGLon-USDT', stock_token: true}, limit: 30, route: x => x};
    for (const [key, fn] of Object.entries(mixin.methods)) instance[key] = fn.bind(instance);
    return {mixin, instance};
}
const normalized = id => ({id: 'alpha:' + id, timestamp: 1700000000000 + id, price: '341', quantity: '1'});
test('UI keeps one chronological list, replaces snapshots, and retains history on outages', async () => {
    let data = {success: true, trades: [normalized(1), normalized(2), normalized(1)], stale: false};
    const {instance} = mount(async () => ({data}));
    await instance.refreshStockTrades();
    assert.equal(JSON.stringify(instance.stockTrades.map(t => t.id)), '["alpha:2","alpha:1"]');
    await instance.refreshStockTrades(); assert.equal(instance.stockTrades.length, 2);
    data = {success: true, trades: [], stale: true};
    await instance.refreshStockTrades(); assert.equal(instance.stockTrades.length, 2); assert.equal(instance.stockTradesStale, true);
    data = {success: true, trades: [normalized(3)], stale: false};
    await instance.refreshStockTrades(); assert.equal(instance.stockTrades.length, 1); assert.equal(instance.stockTrades[0].id, 'alpha:3');
});
test('market switch ignores the previous request; component teardown ignores late replies', async () => {
    const pending = [];
    const {instance, mixin} = mount(() => new Promise(resolve => pending.push(resolve)));
    const first = instance.refreshStockTrades();
    await instance.refreshStockTrades(); assert.equal(pending.length, 1);
    instance.market = {name: 'AAPLon-USDT', stock_token: true};
    mixin.watch['market.name'].call(instance);
    pending[0]({data: {success: true, trades: [normalized(1)]}});
    await first; assert.equal(instance.stockTrades.length, 0);
    mixin.beforeDestroy.call(instance);
    pending[1]({data: {success: true, trades: [normalized(2)]}});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(instance.stockTrades.length, 0);
});
