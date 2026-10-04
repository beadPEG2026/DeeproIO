import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
import {popularMarkets,newsIsStale,safeNewsItems} from '../../resources/js/Functions/DiscoveryFeed.mjs';
import {numeric,matchesMarketSearch} from '../../resources/js/Functions/MarketRanking.mjs';

const require=createRequire(import.meta.url), compiler=require('vue-template-compiler');
const source=readFileSync(new URL('../../resources/js/Components/DiscoveryPanel.vue',import.meta.url),'utf8');
function component(extra={}) {
 const script=source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\s*$/gm,'').replace('export default {','globalThis.component = {');
 const context={clearTimeout(){},setTimeout:()=>1,ActionIcon:{},numeric,popularMarkets,newsIsStale,safeNewsItems,MARKET_LIST:'list',Date,Intl,...extra};
 vm.runInNewContext(script,context);return context.component;
}
const market=(name,volume,extra={})=>({name,base_currency:name.split('-')[0],quote_currency:'USDT',status:true,trade_status:true,last:'2',qVolume:volume,...extra});

test('popular cards use actual enabled crypto USDT turnover, retain platform UMI price and omit empty values',()=>{
 const rows=[market('UMI-USDT','70',{last:'0.5321'}),market('BTC-USDT','100'),market('USDC-USDT','80'),market('HK08379-USDT','1000',{stock_token:true}),market('PAUSED-USDT','500',{trade_status:0}),market('EMPTY-USDT','1000',{last:null}),market('NO-VOLUME',''),market('HIDDEN-USDT','700',{status:0}),market('ETH-USDC','900',{quote_currency:'USDC'})];
 assert.deepEqual(popularMarkets(rows).map(m=>m.name),['BTC-USDT','USDC-USDT','UMI-USDT']);
 assert.equal(popularMarkets(rows)[2].last,'0.5321');assert.equal(rows[0].name,'UMI-USDT');assert.deepEqual(popularMarkets(undefined),[]);
 assert.equal(matchesMarketSearch(rows[2],'USDT/USDC'),true);assert.equal(matchesMarketSearch(rows[2],'USDC/USDT'),true);
});
test('source cache is stale after fifteen minutes and missing timestamps are never treated as current',()=>{
 const fetchedAt='2026-10-03T00:00:00Z',at=Date.parse(fetchedAt);
 assert.equal(newsIsStale({state:'fresh',fetchedAt},at+899999),false);assert.equal(newsIsStale({state:'fresh',fetchedAt},at+900000),true);
 for(const state of ['stale','unavailable'])assert.equal(newsIsStale({state,fetchedAt},at),true);
 assert.equal(newsIsStale({state:'fresh',fetchedAt:null},at),true);
});
test('renderable headlines require safe canonical URLs and real source dates and deduplicate',()=>{
 const good={title:'Real title',url:'https://cointelegraph.com/news/real-title',publishedAt:'2026-10-02T20:00:00Z'};
 assert.deepEqual(safeNewsItems([good,good,...['javascript:alert(1)','https://evil.test/news/a','https://cointelegraph.com@evil.test/news/a','https://cointelegraph.com/news/a?script'].map(url=>({...good,url})),{...good,publishedAt:null},{...good,title:''}]),[good]);
});
test('component retains twelve working services and four explicit unavailable services',()=>{
 const errors=compiler.compile(compiler.parseComponent(source).template.content).errors;assert.deepEqual(errors,[]);
 const c=component(),ctx={$page:{props:{defaultTradePair:'ETH-USDT'}},$i18n:{locale:'zh-CN'},$t:key=>JSON.parse(readFileSync(new URL('../../resources/lang/zh-cn.json',import.meta.url),'utf8'))[key]||key,route:(name,args)=>name+JSON.stringify(args||'')};ctx.t=c.methods.t.bind(ctx);
 const items=c.computed.services.call(ctx);assert.equal(items.length,16);assert.equal(new Set(items.filter(i=>i.path).map(i=>i.path)).size,12);
 assert.deepEqual(Array.from(items.slice(0,12),item=>item.label),['现货交易','股票','行情','UMI','理财','充值 USDT','提现 USDT','划转','返佣中心','公告','帮助中心','客服中心']);
 assert.equal(items[0].path,'market{"market":"ETH-USDT"}');
 assert.equal(items[1].path,'stocks""');assert.equal(items[5].path,'wallets.deposit.crypto{"symbol":"USDT"}');assert.equal(items[6].path,'wallets.withdraw.crypto{"symbol":"USDT"}');
 assert.equal(items[8].path,'reports.referral-transactions""');assert.equal(items.some(item=>item.path?.includes('futures')),false);
 ctx.$page.props.defaultTradePair=null;assert.equal(c.computed.services.call(ctx)[0].path,'market{"market":"BTC-USDT"}');
 assert.match(source,/grid-template-columns:repeat\(4,minmax\(0,1fr\)\)/);assert.match(source,/@click="\$emit\('close'\)"/);
 assert.match(source,/var\(--dp-up,/);assert.match(source,/var\(--dp-down,/);
});
test('failed cache GET retains headline and source time; successful cold GET does not fabricate a timestamp',async()=>{
 let fail=true;const c=component({axios:{CancelToken:{source:()=>({token:'token',cancel(){}})},isCancel:()=>false,get:async()=>{if(fail)throw new Error('offline');return{data:{items:[],fetchedAt:null,state:'unavailable'}}}}});
 const ctx={...c.data(),$i18n:{locale:'en'},news:{items:[{title:'Saved'}],fetchedAt:'2026-10-02T20:00:00Z',state:'fresh'}};
 await c.methods.loadNews.call(ctx);assert.equal(ctx.newsError,true);assert.equal(ctx.news.items[0].title,'Saved');assert.equal(ctx.news.fetchedAt,'2026-10-02T20:00:00Z');assert.equal(ctx.newsLoading,false);
 fail=false;await c.methods.loadNews.call(ctx);assert.equal(ctx.newsError,false);assert.equal(ctx.news.fetchedAt,null);assert.equal(ctx.news.state,'unavailable');
});
test('destroyed discovery cannot replace market state when a shared pending request resolves',async()=>{
 let resolve;const request=new Promise(r=>resolve=r),commits=[];
 const c=component({publicMarketRequest:()=>request});const ctx={...c.data(),route:()=>'/ticker',$store:{commit:(...a)=>commits.push(a)}};
 const pending=c.methods.loadMarkets.call(ctx);ctx.alive=false;resolve({data:{data:[market('BTC-USDT','100')]}});await pending;assert.deepEqual(commits,[]);
});
test('market cleanup retains upper statistics, direct watchlist controls, stablecoin search and sorting',()=>{
 const s=readFileSync(new URL('../../resources/js/Components/MarketOverview.vue',import.meta.url),'utf8');
 assert.equal(compiler.compile(compiler.parseComponent(s).template.content).errors.length,0);
 assert.match(s,/<global-market-cards v-if="category==='crypto' && presentation.firstScreen!==false"/);
 assert.match(s,/class="dp-ranking-star"[^>]*@click.stop="toggleFavorite\(market.name\)"/);
 assert.match(s,/sortBy\(field\)/);assert.match(s,/Data updates in real time · See Spot \/ Futures \/ Stocks for details/);
 for(const removed of ['dp-market-statistics','dp-market-method','dp-stablecoin-entry',"$t('Refresh ranking')"])assert.equal(s.includes(removed),false);
});

test('unavailable discover services announce status and never navigate or close the panel',()=>{
 const c=component(),events=[],ctx={...c.data(),$t:key=>key,$emit:key=>events.push(key)};
 c.methods.activateService.call(ctx,{key:'pay'});assert.equal(ctx.serviceMessage,'This feature is not available yet');assert.deepEqual(events,[]);
 c.methods.activateService.call(ctx,{path:'/wallets/transfer'});assert.deepEqual(events,['close']);
});

test('old locale responses cannot replace translated headlines or end a newer request',async()=>{
 const pending=[];const c=component({axios:{CancelToken:{source:()=>({token:'t',cancel(){}})},isCancel:()=>false,get:()=>new Promise(resolve=>pending.push(resolve))}});
 const ctx={...c.data(),$i18n:{locale:'en'}};const first=c.methods.loadNews.call(ctx);
 ctx.$i18n.locale='ja';ctx.newsLoading=false;const second=c.methods.loadNews.call(ctx);
 pending[0]({data:{items:[{title:'English'}],state:'fresh'}});await first;assert.equal(ctx.newsLoading,true);assert.equal(ctx.news.items.length,0);
 pending[1]({data:{items:[{title:'日本語'}],state:'fresh'}});await second;assert.equal(ctx.news.items[0].title,'日本語');assert.equal(ctx.newsLoading,false);
});
