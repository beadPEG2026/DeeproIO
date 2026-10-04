import Vue from 'vue';
import axios from 'axios';

export const state = Vue.observable({quotes: {}, fx: null, now: Date.now()});
let subscribers = 0, poll = null, clock = null, busy = false, fxBusy = false, updated = 0, fxUpdated = 0;
async function refreshFx() {
    if (fxBusy || Date.now() - fxUpdated < 25000) return;
    fxBusy = true; fxUpdated = Date.now();
    try { const {data} = await axios.get('/stocks/data/fx', {timeout: 15000}); if (data.fx) state.fx = data.fx; }
    catch (_) { /* Display only: retain its source timestamp and enforce age in displayMarket. */ }
    finally { fxBusy = false; state.now = Date.now(); }
}
async function refresh() {
    state.now = Date.now();
    if (document.hidden) return;
    refreshFx();
    if (busy || Date.now() - updated < 20000) return;
    busy = true;
    try {
        const {data} = await axios.get('/stocks/data/quotes', {timeout: 26000});
        state.quotes = Object.fromEntries((data.data || []).map(q => [q.symbol, q]));
        updated = Date.now();
    } catch (_) { /* Fresh cached quotes remain usable until their own expiry. */ }
    finally { state.now = Date.now(); busy = false; }
}
export function subscribeStockQuotes() {
    subscribers++; refresh();
    if (!poll) poll = setInterval(refresh, 30000);
    if (!clock) clock = setInterval(() => { state.now = Date.now(); }, 1000);
}
export function unsubscribeStockQuotes() {
    subscribers = Math.max(0, subscribers - 1);
    if (!subscribers) { clearInterval(poll); clearInterval(clock); poll = clock = null; }
}
