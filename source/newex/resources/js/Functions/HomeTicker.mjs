import {snapshotTime} from './MarketSnapshot.mjs';

export const initialHomeTickerState = () => ({loading:false, error:false, retrying:false, lastSuccessAt:null});

export function homeQuoteIsStale(market, now = Date.now()) {
    const time = snapshotTime(market.updated_at);
    const price = Number(market.last);
    return !Number.isFinite(price) || price <= 0 || market.price_stale === true || !time || time > now + 5000 || now - time >= 90000;
}

// The shared transport owns deduplication. This controller owns only the home
// lifecycle and a bounded recovery attempt; it never polls or clears market data.
export function createHomeTickerLoader({request, onState, canLoad = () => true, clock = Date.now, setTimer = setTimeout, clearTimer = clearTimeout}) {
    let state = initialHomeTickerState(), disposed = false, pending = null, retryTimer = null;
    let lastEventStarted = -Infinity, retriesLeft = 0;
    const publish = patch => { if (!disposed) { state = {...state, ...patch}; onState({...state}); } };
    const cancelRetry = () => { if (retryTimer !== null) clearTimer(retryTimer); retryTimer = null; };
    function load(reason = 'manual') {
        if (disposed) return Promise.resolve(false);
        if (pending) return pending;
        if (!canLoad()) {
            if (reason === 'initial' || reason === 'manual') publish({error:true});
            return Promise.resolve(false);
        }
        // Visibility/online events may arrive together; they do not create a
        // repeated retry loop or replenish the automatic recovery budget.
        if (reason === 'event' && clock() - lastEventStarted < 30000) return Promise.resolve(false);
        cancelRetry();
        if (reason === 'initial' || reason === 'manual') retriesLeft = 1;
        if (reason === 'event') lastEventStarted = clock();
        publish({loading:true, retrying:false});
        pending = Promise.resolve().then(request).then(ok => {
            if (!ok) throw new Error('ticker_unavailable');
            publish({loading:false, error:false, lastSuccessAt:clock()});
            return true;
        }).catch(() => {
            publish({loading:false, error:true});
            if (!disposed && retriesLeft > 0 && canLoad()) {
                retriesLeft--;
                publish({retrying:true});
                retryTimer = setTimer(() => {
                    retryTimer = null;
                    publish({retrying:false});
                    if (!disposed && canLoad()) load('retry');
                }, 1500);
            }
            return false;
        }).finally(() => { pending = null; });
        return pending;
    }
    return {load, dispose() { disposed = true; cancelRetry(); }};
}
