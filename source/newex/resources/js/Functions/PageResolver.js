export const resolvePage = name => import(/* webpackChunkName: "page-[request]" */ `../Pages/${name}.vue`).then(module => module.default);
export function warmNavigation() {
    const pageName = path => path === '/' ? 'Home/Home' : path === '/markets' ? 'Market/Markets' : path === '/stocks' ? 'Explore/Stocks' : path.startsWith('/market/lite/') ? 'MarketLite/Market' : path.startsWith('/market/') ? 'Market/Market' : path === '/wallets/accounts' ? 'Wallet/NewWallets' : null;
    const warm = path => {const name=pageName(path); if (name) resolvePage(name).catch(()=>{});};
    const intent = event => {const link=event.target.closest?.('.dp-bottom-nav a, .dp-ranking-identity, .dp-hub-price'); if (!link) return; const url=new URL(link.href,location.origin);if(url.origin===location.origin)warm(url.pathname);};
    document.addEventListener('pointerover',intent,{passive:true});
    document.addEventListener('touchstart',intent,{passive:true});
    document.addEventListener('focusin',intent);
    // Only public, static component code. No account/order response is prefetched or persisted.
    const idle = () => {if(document.hidden || navigator.connection?.saveData || /(^|-)2g/.test(navigator.connection?.effectiveType || ''))return;warm('/markets');warm('/stocks');};
    const schedule=()=>setTimeout(()=>('requestIdleCallback' in window ? requestIdleCallback(idle,{timeout:3000}) : idle()),3000);
    if(document.readyState==='complete')schedule();else window.addEventListener('load',schedule,{once:true});
}
