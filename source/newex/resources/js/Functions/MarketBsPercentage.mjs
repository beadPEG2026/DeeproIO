// Move the decimal point as text, avoiding floating-point artifacts such as
// 0.29 * 100 === 28.999999999999996. Invalid input stays invalid for validation.
function shiftDecimal(value, places) {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const text = String(value).trim();
    const match = text.match(/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/);
    if (!match || !(match[2] || match[3])) return text;
    const exponent = Number(match[4] || 0);
    if (!Number.isFinite(Number(text)) || Math.abs(exponent) > 1000) return 'NaN';
    const digits = match[2] + (match[3] || '');
    const point = match[2].length + exponent + places;
    const expanded = point <= 0
        ? '0.' + '0'.repeat(-point) + digits
        : point >= digits.length
            ? digits + '0'.repeat(point - digits.length)
            : digits.slice(0, point) + '.' + digits.slice(point);
    const [whole, fraction = ''] = expanded.split('.');
    const normalized = (whole.replace(/^0+(?=\d)/, '') || '0')
        + (fraction.replace(/0+$/, '') ? '.' + fraction.replace(/0+$/, '') : '');
    return (match[1] === '-' && normalized !== '0' ? '-' : '') + normalized;
}

export const bsRatioToPercent = value => shiftDecimal(value, 2);
export const bsPercentToRatio = value => shiftDecimal(value, -2);
