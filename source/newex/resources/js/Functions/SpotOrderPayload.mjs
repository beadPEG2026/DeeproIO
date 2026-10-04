export function spotOrderPayload(order) {
    const payload = {...order};
    if (payload.type !== 'stop_limit') {
        delete payload.trigger_price;
        delete payload.trigger_condition;
    }
    if (!(payload.type === 'market' && payload.side === 'buy')) delete payload.quoteQuantity;
    return payload;
}
