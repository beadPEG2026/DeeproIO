import {balanceDecimal} from './WalletBalance.mjs';
export function stepDecimal(value,precision,direction) {
 const digits=Math.max(0,Math.min(18,Number(precision)||0)),text=balanceDecimal(value,digits)||'0';
 const [whole,fraction='']=text.split('.');let units=BigInt(whole+fraction.padEnd(digits,'0'))+(direction>0?1n:-1n);if(units<0n)units=0n;
 const raw=units.toString().padStart(digits+1,'0');return digits?raw.slice(0,-digits)+'.'+raw.slice(-digits):raw;
}
const key='deepro:trade-funding-draft';
export function saveTradeDraft(user,market,state,storage=window.sessionStorage,now=Date.now()) {
 const forms={};for(const name of ['bid','ask']){forms[name]={};for(const field of ['price','quantity','quoteQuantity'])forms[name][field]=String(state[name][field]??'0');}
 storage.setItem(key,JSON.stringify({user:String(user),market,at:now,...forms,activeTab:state.activeTab,orderType:state.orderType,lastBuyInput:state.lastBuyInput,lastSellInput:state.lastSellInput}));
}
export function takeTradeDraft(user,market,storage=window.sessionStorage,now=Date.now()) {
 const raw=storage.getItem(key);if(!raw)return null;storage.removeItem(key);
 try {const d=JSON.parse(raw);if(d.user!==String(user)||d.market!==market||now-d.at<0||now-d.at>900000||!['buy','sell'].includes(d.activeTab)||!['limit','market'].includes(d.orderType))return null;
 for(const name of ['bid','ask'])for(const field of ['price','quantity','quoteQuantity'])if(balanceDecimal(d[name]?.[field])===null)return null;
 return d;
 }catch(_){return null;}
}
export function tradeReturnMarket(search){const value=new URLSearchParams(search).get('trade_return')||'';return /^[A-Za-z0-9]{1,24}-[A-Za-z0-9]{1,24}$/.test(value)?value:null;}
