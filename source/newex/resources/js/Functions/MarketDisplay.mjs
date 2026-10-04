/** Display-only quote selection. Never mutates market/order state. */
export function displayMarket(market, quotes = {}, now = Date.now(), fx = null) {
    const row = {...market};
    row.referenceQuote = false;
    const native = quotes[row.base_currency];
    const sourceAt = Date.parse(native?.sourceTime);
    if (row.price_reference_product && native?.symbol === row.base_currency && native?.underlyingCurrency === 'HKD' &&
        Number(native.underlyingPrice) > 0 && Number.isFinite(Number(native.underlyingPrice)) &&
        Number.isFinite(sourceAt) && now >= sourceAt - 5000 && now - sourceAt <= 7 * 86400000) {
        // HKD is for presentation only; order estimates continue to use USDT.
        return {...row, last:String(native.underlyingPrice), high:native.underlyingHigh || null,
            low:native.underlyingLow || null, change:native.underlyingChange ?? null,
            referenceQuote:true, displayCurrency:'HKD', quoteSourceTime:native.sourceTime,
            quoteClosed:native.marketStatus === 'closed', quote_precision:3, approximateUsdt:usdtEquivalent(native.underlyingPrice,fx,now), fxSourceTime:fx?.eventTime};
    }
    if (Number(row.last) > 0) return row;
    const quote = quotes[row.base_currency];
    const time = quote ? Date.parse(quote.receivedAt) : NaN;
    if (quote && !quote.unavailable && Number.isFinite(Number(quote.price)) && Number(quote.price) > 0 && Number.isFinite(time) && now - time <= 120000 && now >= time - 5000) {
        row.last = Number(quote.price).toLocaleString('en-US', {useGrouping:false,maximumFractionDigits:6});
        row.change = Number.isFinite(Number(quote.change)) ? Number(quote.change).toFixed(2) : null;
        row.high = Number(quote.high)>0 ? Number(quote.high).toFixed(4) : null;
        row.low = Number(quote.low)>0 ? Number(quote.low).toFixed(4) : null;
        row.referenceQuote = true;
        row.quoteReceivedAt = quote.receivedAt;
    } else { row.last = null; row.change = null; row.high = null; row.low = null; }
    return row;
}

export function usdtEquivalent(hkd, fx, now = Date.now()) {
    const rate = Number(fx?.hkdPerUsdt), at = Number(fx?.eventTime) * 1000, price = Number(hkd);
    if (fx?.base !== 'HKD' || fx?.quote !== 'USDT' || !Number.isFinite(rate) || rate <= 0 ||
        !Number.isFinite(price) || price <= 0 || !Number.isFinite(at) || at > now + 5000 || now - at > 604800000) return null;
    return price / rate;
}
export function formatUsdt(value) {
    if (!Number.isFinite(value) || value <= 0) return '';
    return value.toLocaleString('en-US', {maximumFractionDigits: value < 1 ? 6 : 2});
}
