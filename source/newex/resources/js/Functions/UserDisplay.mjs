// Format decimal strings without rounding small amounts to zero or losing precision.
export function displayDecimal(value, empty = '—') {
    if (value === null || value === undefined || value === '') return empty;
    const text = String(value).trim();
    const match = text.match(/^([+-]?)(\d+)(?:\.(\d*))?(?:e([+-]?\d+))?$/i);
    if (!match) return empty;
    const exponent = Number(match[4] || 0);
    if (!Number.isInteger(exponent) || Math.abs(exponent) > 100) return empty;
    let digits = match[2] + (match[3] || '');
    const point = match[2].length + exponent;
    let decimal = point <= 0 ? '0.' + '0'.repeat(-point) + digits
        : point >= digits.length ? digits + '0'.repeat(point - digits.length)
        : digits.slice(0, point) + '.' + digits.slice(point);
    decimal = decimal.replace(/^0+(?=\d)/, '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    return (match[1] === '-' && decimal !== '0' ? '-' : '') + decimal;
}

// API timestamps must carry an offset. Accept the legacy SQL separator as well.
export function parseTimestamp(value) {
    if (value instanceof Date) return Number.isFinite(value.getTime()) ? value : null;
    if (typeof value === 'number' || (typeof value === 'string' && /^\d+(\.\d+)?$/.test(value))) {
        const n = Number(value);
        const date = new Date(n < 1e11 ? n * 1000 : n);
        return n > 0 && Number.isFinite(date.getTime()) ? date : null;
    }
    if (typeof value !== 'string') return null;
    const text = value.trim().replace(/^(\d{4}-\d{2}-\d{2})\s+/, '$1T').replace(/\s+([+-]\d{2}:?\d{2}|Z)$/i, '$1');
    if (!/T.*(?:Z|[+-]\d{2}:?\d{2})$/i.test(text)) return null;
    const date = new Date(text);
    return Number.isFinite(date.getTime()) ? date : null;
}

export function serverTimestamp(value, sourceTimeZone = 'UTC') {
    const absolute = parseTimestamp(value);
    if (absolute) return absolute;
    const match = typeof value === 'string' && value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);
    if (!match) return null;
    const wall = Date.UTC(...match.slice(1).map((n,i) => Number(n) - (i === 1 ? 1 : 0)));
    try {
        const formatter = new Intl.DateTimeFormat('en-GB', {timeZone: sourceTimeZone, year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
        const offset = time => {
            const parts = Object.fromEntries(formatter.formatToParts(new Date(time)).map(p => [p.type,p.value]));
            return Date.UTC(+parts.year,+parts.month-1,+parts.day,+parts.hour,+parts.minute,+parts.second) - time;
        };
        return new Date(wall - offset(wall - offset(wall)));
    } catch (_) { return null; }
}

export function localTradeTime(value, sourceTimeZone) {
    const date = serverTimestamp(value, sourceTimeZone);
    return date ? date.toLocaleTimeString('en-GB', {hour12: false}) : '—';
}

export function localDateTime(value, sourceTimeZone) {
    const date = serverTimestamp(value, sourceTimeZone);
    return date ? date.toLocaleString('sv-SE', {hour12: false}) : '—';
}

export function stockQuoteStatus(quote, tradeEnabled = true) {
    if (!tradeEnabled) return 'Token trading paused';
    if (quote.unavailable || !Number.isFinite(Number(quote.price)) || Number(quote.price) <= 0) return 'No data';
    if (quote.stale) return 'Quote delayed';
    return ({trading: 'Token market quoting', premarket: 'Pre-market', regular: 'Market open', postmarket: 'After-hours', closed: 'Market closed', suspended: 'Token trading paused', halt: 'Token trading paused'})[String(quote.marketStatus || '').toLowerCase()] || 'Quote available';
}

export function optionsLimitsReady(min, max) {
    if ([min,max].some(v => v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) || Number(v) < 0)) return false;
    return Number(max) === 0 || Number(max) >= Number(min);
}

export function annualizedRate(reward, days) {
    const r = Number(reward), d = Number(days);
    return Number.isFinite(r) && Number.isFinite(d) && d > 0 ? (r * 365 / d).toFixed(2) : '—';
}

// Explicit periods survive Laravel collection serialization. Legacy arrays must be
// paired with allowed_days; their indexes are never valid staking durations.
export function stakingPeriods(staking) {
    const csv = value => Array.isArray(value) ? value : String(value ?? '').split(',').map(v => v.trim());
    let rows = staking?.periods;
    if (!Array.isArray(rows)) {
        const ranges = staking?.ranges;
        const days = csv(staking?.allowed_days);
        if (Array.isArray(ranges)) rows = days.map((d,i) => ({days:d,period_rate:ranges[i]}));
        else if (ranges && typeof ranges === 'object') rows = Object.entries(ranges).map(([d,r]) => ({days:d,period_rate:r}));
        else rows = days.map((d,i) => ({days:d,period_rate:csv(staking?.rewards_percentage)[i]}));
    }
    return rows.filter(p => Number.isInteger(Number(p.days)) && Number(p.days)>0 && p.period_rate!==null && p.period_rate!==undefined && String(p.period_rate).trim()!=='' && Number.isFinite(Number(p.period_rate)) && Number(p.period_rate)>=0)
        .map(p => ({days:String(p.days),reward:displayDecimal(p.period_rate),annualized:annualizedRate(p.period_rate,p.days)})).sort((a,b)=>Number(a.days)-Number(b.days));
}
export function firstStakingPeriod(staking) { return stakingPeriods(staking)[0] || null; }

export function publishedDownloadUrl(value) {
    try {
        const url = new URL(String(value || '').trim());
        if (url.protocol !== 'https:' || url.username || url.password) return '';
        if (/^(?:www\.)?(?:google\.com|example\.(?:com|org|net)|localhost)$/i.test(url.hostname)) return '';
        return url.href;
    } catch (_) { return ''; }
}

// Match funded decimal truncation without binary floating-point rounding.
export function fundedRewardEstimate(amount, percent) {
    const parse = value => {
        const text = String(value ?? '');
        if (!/^\d+(\.\d*)?$/.test(text) || text.length > 80) return null;
        const [whole, fraction = ''] = text.split('.');
        return [BigInt(whole + fraction), BigInt(fraction.length)];
    };
    const a = parse(amount), p = parse(percent);
    if (!a || !p) return '0.00000000';
    const units = a[0] * p[0] * BigInt(100000000) / (BigInt(100) * BigInt(10) ** (a[1] + p[1]));
    const digits = units.toString().padStart(9, '0');
    return digits.slice(0, -8) + '.' + digits.slice(-8);
}
