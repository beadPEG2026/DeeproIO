export const resolvePage = name => import(/* webpackChunkName: "page-[request]" */ `../Pages/${name}.vue`).then(module => module.default);
export function warmNavigation() {
    const pageName = path => path === '/' ? 'Home/Home' : path === '/markets' ? 'Market/Markets' : path === '/stocks' ? 'Explore/Stocks' : path.startsWith('/market/lite/') ? 'MarketLite/Market' : path.startsWith('/market/') ? 'Market/Market' : path === '/wallets/accounts' ? 'Wallet/NewWallets' : path === '/wallets' ? 'Wallet/Wallets' : null;
    const pending = new Map();
    const constrained = () => navigator.connection?.saveData || /(^|-)2g/.test(navigator.connection?.effectiveType || '');
    const warm = path => {
        const name = pageName(path);
        if (!name || constrained()) return Promise.resolve();
        if (!pending.has(name)) pending.set(name, resolvePage(name).catch(() => { pending.delete(name); }));
        return pending.get(name);
    };
    const intent = event => {
        const link = event.target.closest?.('.dp-bottom-nav a, .dp-ranking-identity, .dp-hub-price');
        if (!link) return;
        const url = new URL(link.href, location.origin);
        if (url.origin === location.origin) warm(url.pathname);
    };
    document.addEventListener('pointerover', intent, {passive:true});
    document.addEventListener('pointerdown', intent, {passive:true});
    document.addEventListener('touchstart', intent, {passive:true});
    document.addEventListener('focusin', intent);
    // Warm only static page code, sequentially. Never prefetch account/order responses.
    const idle = async () => {
        const links = [...document.querySelectorAll('.dp-bottom-nav a')];
        for (const link of links) {
            if (document.hidden || constrained()) return;
            const url = new URL(link.href, location.origin);
            if (url.origin === location.origin && url.pathname !== location.pathname) await warm(url.pathname);
        }
    };
    setTimeout(() => ('requestIdleCallback' in window ? requestIdleCallback(idle,{timeout:3000}) : idle()),1500);
}
