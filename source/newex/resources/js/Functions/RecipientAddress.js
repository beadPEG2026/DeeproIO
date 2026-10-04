// Import only the recipient; never import an amount or execute a payment URI.
const evmChain = {2:1,3:1,5:56,6:56,15:137,16:137,24:196,25:196};
export function recipientAddress(value, network) {
    let text = String(value || '').trim();
    const id = Number(network);
    if (!id) throw new Error('Please select network');
    if (!text || text.length > 1024 || /\s/.test(text)) throw new Error('Invalid wallet address');
    if ([24,25].includes(id) && /^xko[0-9a-f]{40}$/i.test(text)) text='0x'+text.slice(3);
    if (/^ethereum:/i.test(text)) {
        const match = text.match(/^ethereum:(?:pay-)?(0x[a-fA-F0-9]{40})(?:@(\d+))?(?:\?[^#]*)?$/);
        if (!match || !evmChain[id]) throw new Error('QR code does not match the selected network.');
        if (match[2] && Number(match[2]) !== evmChain[id]) throw new Error('QR code does not match the selected network.');
        text = match[1];
    } else if (/^(bitcoin|solana|tron):/i.test(text)) {
        const scheme=text.split(':')[0].toLowerCase();
        const allowed={bitcoin:[9,17],solana:[20,21],tron:[7,8]};
        if (!allowed[scheme].includes(id)) throw new Error('QR code does not match the selected network.');
        text = text.split(':')[1].split('?')[0];
    }
    if (evmChain[id] && !/^0x[a-fA-F0-9]{40}$/.test(text)) throw new Error('Invalid wallet address');
    if ([7,8].includes(id) && !/^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(text)) throw new Error('Invalid wallet address');
    if ([20,21].includes(id) && !/^[1-9A-HJ-NP-Za-km-z]{32,44}$/.test(text)) throw new Error('Invalid wallet address');
    if (text.length>255 || /[:/?#&\s]/.test(text)) throw new Error('Invalid wallet address');
    return text;
}
