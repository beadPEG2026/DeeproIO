export function sanitizePreferences(value = {}) {
    return {currency: ['USDT','USD','CNY','JPY','HKD','EUR'].includes(value.currency) ? value.currency : (value.currency==='€'?'EUR':'USDT'),
        colors: value.colors === 'red-up' ? 'red-up' : 'green-up'};
}
export function displayCurrency(options, symbol) {
    return options.find(c => c.symbol === symbol) || {symbol, rate:null};
}
export function displayValue(amount, currency, locale = 'en-US') {
    if (!currency || currency.rate === null || !Number.isFinite(Number(currency.rate)) || Number(currency.rate)<=0) return '—';
    if (amount === null || amount === undefined || amount === '' || !Number.isFinite(Number(amount))) return '—';
    return (Number(amount) * Number(currency.rate)).toLocaleString(locale, {minimumFractionDigits:2, maximumFractionDigits:2});
}
export function pinHongKongMarket(rows, region) {
    if (region !== 'HK') return rows;
    return [...rows.filter(m => m.name === 'HK08379-USDT'), ...rows.filter(m => m.name !== 'HK08379-USDT')];
}

export function currencyLabel(code, locale='en') {
 const names=/^zh/i.test(locale)?(/tw|hk/i.test(locale)?{USDT:'泰達幣',USD:'美元',CNY:'人民幣',JPY:'日圓',HKD:'港幣',EUR:'歐元'}:{USDT:'泰达币',USD:'美元',CNY:'人民币',JPY:'日元',HKD:'港币',EUR:'欧元'}):{USDT:'Tether',USD:'US Dollar',CNY:'Chinese Yuan',JPY:'Japanese Yen',HKD:'Hong Kong Dollar',EUR:'Euro'};
 return code+' · '+(names[code]||code);
}
