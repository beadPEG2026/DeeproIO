import {numeric, enabled, readFavorites, matchesMarketSearch} from './MarketRanking.mjs';
export function mergedFavorites(primary, legacy) {
 return [...new Set([...readFavorites(primary), ...readFavorites(legacy).map(s=>s.includes('-')?s:s+'-USDT')])];
}
export function loadWatchlist(storage) {
 if(storage.getItem('deepro.watchlist.v2'))return readFavorites(storage.getItem('marketFavorites'));
 const values=mergedFavorites(storage.getItem('marketFavorites'),storage.getItem('deepro.stockFavorites'));
 storage.setItem('marketFavorites',JSON.stringify(values));storage.setItem('deepro.watchlist.v2','1');return values;
}
export function saveWatchlist(storage, favorites, stockSymbols=[]) {
 storage.setItem('marketFavorites',JSON.stringify(favorites));
 storage.setItem('deepro.watchlist.v2','1');
 storage.setItem('deepro.stockFavorites',JSON.stringify(favorites.filter(s=>stockSymbols.includes(s.split('-')[0])).map(s=>s.split('-')[0])));
}
export function assetRows(markets, assets=[]) {
 const catalog=new Map(assets.map(a=>[a.symbol,a]));
 return markets.filter(m=>!enabled(m.stock_token)||catalog.has(m.base_currency)).map(m=>({...m,asset:catalog.get(m.base_currency)||null}));
}
export function filterDiscovery(rows,{category='crypto',stockType='all',stockRegion='all',search=''}={}) {
 const q=search.trim().toLowerCase();
 return rows.filter(m=>(category==='crypto'?!enabled(m.stock_token):category==='stocks'?enabled(m.stock_token):true)
  && (stockType==='all'||category!=='stocks'||m.asset?.assetType===stockType)
  && (category!=='stocks'||!['US','HK'].includes(stockRegion)||(m.asset?.region||'US')===stockRegion)
  && matchesMarketSearch(m,q));
}
export function sortRows(rows,field,direction=-1) {
 return [...rows].sort((a,b)=>{if(field==='name')return a.name.localeCompare(b.name)*direction;const x=numeric(a[field]),y=numeric(b[field]);return (x===null&&y===null?0:x===null?1:y===null?-1:(x-y)*direction)||a.name.localeCompare(b.name)});
}
export function breadth(rows) {
 const b={up:0,down:0,flat:0,unknown:0}; const seen=new Set();
 for(const m of rows){if(seen.has(m.base_currency))continue;seen.add(m.base_currency); const n=numeric(m.change);b[n===null?'unknown':n>0?'up':n<0?'down':'flat']++;}return b;
}
// Narrow, reviewed presentation groups. Never infer sector indices from legacy multi-tag flags.
export const GROUPS=[{name:'Layer 1',symbols:['BTC','ETH','SOL','BNB','ADA','TRX','DOGE']},{name:'PoW',symbols:['BTC','DOGE']},{name:'Meme',symbols:['DOGE']}];
export function sectorSummaries(rows,groups=GROUPS) {return groups.map(g=>{const members=rows.filter(m=>g.symbols.includes(m.base_currency)&&numeric(m.change)!==null);return {...g,members,change:members.length?members.reduce((s,m)=>s+numeric(m.change),0)/members.length:null};}).filter(g=>g.members.length>=2);}
export function sparkPaths(points=[], tradingDays=false) {
 const valid=points.filter(p=>numeric(p.time)!==null&&numeric(p.value)>0).sort((a,b)=>a.time-b.time);
 if(valid.length<2)return [];
 const min=Math.min(...valid.map(p=>p.value)),max=Math.max(...valid.map(p=>p.value));const start=valid[0].time,end=valid.at(-1).time;
 if(end<=start)return [];
 const segments=[[]];let previous=start;
 for(const [index,p] of valid.entries()){if(!tradingDays && p.time-previous>5400)segments.push([]);segments.at(-1).push(`${((tradingDays?index/(valid.length-1):(p.time-start)/(end-start))*88+2).toFixed(1)},${(max===min?18:32-(p.value-min)/(max-min)*28).toFixed(1)}`);previous=p.time;}
 return segments.filter(s=>s.length>=2).map(s=>s.join(' '));
}
