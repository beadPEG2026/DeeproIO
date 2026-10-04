const tabs = new Set(['home','join','mine','income','team','transfer','withdraw','progress','points','records','history','burn']);
export function umiReturnPath(url) {
    const parsed = new URL(url, 'https://deepro.invalid');
    if (parsed.searchParams.get('from') !== 'umi') return null;
    if (!/^\/market\/(UMI-USDT|HK08379-USDT)$/.test(parsed.pathname) && !parsed.pathname.startsWith('/wallets/')) return null;
    const tab = parsed.searchParams.get('umi_tab');
    return '/umi-ecosystem/portfolio?tab=' + (tabs.has(tab) ? tab : 'mine');
}
