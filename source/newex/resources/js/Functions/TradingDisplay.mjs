// Display-only helpers. Never used to price, match or settle an order.
export function finiteMetric(value) {
    return (typeof value === 'number' || typeof value === 'string') && String(value).trim() !== '' && Number.isFinite(Number(value));
}
export function depthSeries(rows, side) {
    const levels = new Map();
    for (const row of Array.isArray(rows) ? rows : []) {
        if (!finiteMetric(row.price) || !finiteMetric(row.quantity)) continue;
        const price = Number(row.price), quantity = Number(row.quantity);
        if (price <= 0 || quantity <= 0) continue;
        levels.set(price, (levels.get(price) || 0) + quantity);
    }
    let cumulative = 0;
    return [...levels].sort((a,b) => side === 'bids' ? b[0]-a[0] : a[0]-b[0]).slice(0, 100).map(([price, quantity]) => ({price, quantity, cumulative: cumulative += quantity}));
}
