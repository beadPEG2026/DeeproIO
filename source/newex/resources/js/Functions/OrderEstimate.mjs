// Estimates only: never update the market store or the execution price.
export function resolveEstimateMarket(market, quote, now = Date.now()) {
    const row = {...market, referenceQuote: false, approximateUsd: false};
    if (Number.isFinite(Number(row.last)) && Number(row.last) > 0) return row;
    const at = Date.parse(quote?.receivedAt);
    const currency = quote?.currency || 'USD'; // Ondo tokenInfo.price is USD.
    const sameCurrency = currency === row.quote_currency;
    const usdEstimate = currency === 'USD' && row.quote_currency === 'USDT';
    if (quote?.symbol === row.base_currency && !quote.unavailable &&
        Number.isFinite(Number(quote.price)) && Number(quote.price) > 0 &&
        Number.isFinite(at) && now - at <= 120000 && at - now <= 5000 &&
        (sameCurrency || usdEstimate)) {
        row.last = String(quote.price);
        row.high = Number(quote.high) > 0 ? String(quote.high) : null;
        row.low = Number(quote.low) > 0 ? String(quote.low) : null;
        row.change = quote.change != null && Number.isFinite(Number(quote.change)) ? Number(quote.change).toFixed(2) : null;
        row.referenceQuote = true;
        row.approximateUsd = usdEstimate;
    } else {
        row.last = null;
        row.high = null;
        row.low = null;
        row.change = null;
    }
    return row;
}

export function estimateBuyQuantity(amount, price, feePercent, precision) {
    const [a, p, fee, digits] = [amount, price, feePercent, precision].map(Number);
    if (![a, p, fee, digits].every(Number.isFinite) || a <= 0 || p <= 0 ||
        fee < 0 || fee >= 100 || !Number.isInteger(digits) || digits < 0 || digits > 18) return '0';
    const power10 = digits => BigInt('1' + '0'.repeat(digits));
    const rational = value => {
        const [mantissa, exponent = '0'] = String(value).toLowerCase().split('e');
        const [whole, fraction = ''] = mantissa.split('.');
        const places = fraction.length - Number(exponent);
        const numerator = BigInt(whole + fraction);
        return places >= 0 ? [numerator, power10(places)] : [numerator * power10(-places), 1n];
    };
    // Integer decimal arithmetic avoids losing a unit to binary floating point.
    const [an, ad] = rational(a), [pn, pd] = rational(p), [fn, fd] = rational(fee);
    const scaled = an * (100n * fd - fn) * pd * power10(digits) / (ad * 100n * fd * pn);
    const text = scaled.toString().padStart(digits + 1, '0');
    return digits ? text.slice(0, -digits) + '.' + text.slice(-digits) : text;
}
