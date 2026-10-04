/** A changed payload is a new intent; an uncertain network retry keeps its key. */
export function prepareIntent(pending, action, fields, makeKey) {
    const signature = JSON.stringify([action, fields]);
    return pending && pending.signature === signature ? pending : {signature, key: makeKey()};
}

export function retainIntentAfterError(error) {
    const status = error?.response?.status;
    return !status || status >= 500;
}

export function validShareAmount(value, maximum) {
    if (!/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,8})?$/.test(String(value))) return false;
    const scaled = v => { const [whole, fraction = ''] = String(v).split('.');
        return BigInt(whole || '0') * 100000000n + BigInt((fraction + '00000000').slice(0,8)); };
    try { return scaled(value) > 0n && scaled(value) <= scaled(maximum); } catch (_) { return false; }
}
