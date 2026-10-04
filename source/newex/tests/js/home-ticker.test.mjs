import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
import {createHomeTickerLoader, initialHomeTickerState, homeQuoteIsStale} from '../../resources/js/Functions/HomeTicker.mjs';
import {marketSnapshotTime, isOlderSnapshot, pickQuote} from '../../resources/js/Functions/MarketSnapshot.mjs';

const require=createRequire(import.meta.url), compiler=require('vue-template-compiler');
const source=readFileSync(new URL('../../resources/js/Components/HomeDashboard.vue',import.meta.url),'utf8');
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}};
const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve()};
function timers(){let time=Date.parse('2026-10-03T08:00:00Z'),id=0;const queue=new Map();return{clock:()=>time,setTimer(fn,delay){queue.set(++id,{fn,at:time+delay});return id},clearTimer:id=>queue.delete(id),size:()=>queue.size,async advance(ms){time+=ms;for(const [key,item] of [...queue])if(item.at<=time){queue.delete(key);item.fn()}await flush()}}}
function fixture(request,extra={}){const timer=timers(),states=[];let current=initialHomeTickerState();const loader=createHomeTickerLoader({request,clock:timer.clock,setTimer:timer.setTimer,clearTimer:timer.clearTimer,onState:s=>{current=s;states.push(s)},...extra});return{loader,timer,states,get state(){return current}}}
function marketStore(clock){
 const requests=[];const transportContext={URL,location:{origin:'https://preview.invalid'},Date:{now:clock},axios:{get:(url,options)=>{const d=deferred();requests.push({url,options,...d});return d.promise}}};
 const transport=readFileSync(new URL('../../resources/js/Functions/PublicMarketRequests.js',import.meta.url),'utf8').replace(/^import .*;\s*$/gm,'').replace('export function publicMarketRequest','function publicMarketRequest');
 vm.runInNewContext(transport+'\nglobalThis.request=publicMarketRequest;',transportContext);
 const mutationNames=['MARKET_LIST','MARKET_TRADE_LIST','MARKET_UPDATE','MARKET_UPDATE_STATS','MARKET_TRADE_STORE','OPTIONS_TRADE_LIST'];
 const context={publicMarketRequest:transportContext.request,Vue:{set:(obj,k,value)=>obj[k]=value},marketSnapshotTime,isOlderSnapshot,pickQuote,...Object.fromEntries(mutationNames.map(n=>[n,n]))};
 const script=readFileSync(new URL('../../resources/js/Store/Modules/markets.js',import.meta.url),'utf8').replace(/^import .*\s*$/gm,'').replace('export default {','globalThis.module = {');vm.runInNewContext(script,context);
 const store=context.module,commit=(name,payload)=>store.mutations[name](store.state,payload);
 return{requests,store,dispatch:()=>store.actions.fetchMarkets({state:store.state,commit},'/api/v1/markets/ticker'),transport:transportContext.request};
}

test('actual shared transport timeout recovers once and actual market store keeps UMI and quote values',async()=>{
 const timer=timers(),s=marketStore(timer.clock),states=[];const loader=createHomeTickerLoader({request:s.dispatch,onState:x=>states.push(x),clock:timer.clock,setTimer:timer.setTimer,clearTimer:timer.clearTimer});
 const first=loader.load('initial');await flush();assert.equal(s.requests.length,1);assert.equal(s.requests[0].options.timeout,15000);assert.equal(s.requests[0].options.cancelToken,undefined);
 // Match the Axios failure produced when its client timeout aborts the XHR.
 s.requests[0].reject(Object.assign(new Error('timeout of 15000ms exceeded'),{code:'ECONNABORTED'}));assert.equal(await first,false);
 assert.equal(states.at(-1).error,true);assert.equal(states.at(-1).retrying,true);assert.equal(timer.size(),1);
 await timer.advance(1499);assert.equal(s.requests.length,1);await timer.advance(1);assert.equal(s.requests.length,2);
 const rows=[{name:'BTC-USDT',last:'67000',updated_at:new Date(timer.clock()).toISOString()},{name:'UMI-USDT',last:'0.5321',updated_at:new Date(timer.clock()).toISOString()}];
 s.requests[1].resolve({data:{data:rows}});await flush();assert.equal(states.at(-1).error,false);assert.equal(states.at(-1).loading,false);assert.equal(timer.size(),0);assert.equal(s.store.state.tickerLoadFailed,false);
 assert.equal(s.store.state.items[1].name,'UMI-USDT');assert.equal(s.store.state.items[1].last,'0.5321');assert.equal(s.store.state.items[0].last,'67000');
});

test('two failed attempts stop without polling; bursts of lifecycle events do not replenish retries',async()=>{
 let calls=0;const f=fixture(async()=>{calls++;return false});await f.loader.load('initial');await f.timer.advance(1500);
 assert.equal(calls,2);assert.equal(f.timer.size(),0);assert.equal(f.state.retrying,false);
 for(let i=0;i<30;i++)await f.loader.load('event');assert.equal(calls,3);assert.equal(f.timer.size(),0);
 await f.timer.advance(30000);const burst=Array.from({length:20},()=>f.loader.load('event'));await Promise.all(burst);assert.equal(calls,4);assert.equal(f.timer.size(),0);
 await f.timer.advance(86400000);assert.equal(calls,4);assert.equal(f.state.error,true);
});

test('manual recovery replaces a scheduled automatic attempt and never overlaps an active request',async()=>{
 const d=deferred();let calls=0;const f=fixture(()=>{calls++;return calls===1?false:d.promise});await f.loader.load('initial');
 const manual=f.loader.load('manual');const other=f.loader.load('event');assert.equal(manual,other);await flush();assert.equal(calls,2);assert.equal(f.timer.size(),0);
 d.resolve(true);assert.equal(await manual,true);await f.timer.advance(1500);assert.equal(calls,2);assert.equal(f.state.error,false);
});

test('hidden/offline home cannot start or continue retries, and online event can recover later',async()=>{
 let visible=false,calls=0;const f=fixture(async()=>{calls++;return false},{canLoad:()=>visible});
 await f.loader.load('initial');assert.equal(calls,0);assert.equal(f.state.error,true);visible=true;await f.loader.load('manual');assert.equal(calls,1);
 visible=false;await f.timer.advance(1500);assert.equal(calls,1);assert.equal(f.state.retrying,false);assert.equal(f.timer.size(),0);
 visible=true;await f.timer.advance(30000);await f.loader.load('event');assert.equal(calls,2);assert.equal(f.timer.size(),0);
});

test('first online/visible recovery is not suppressed by the earlier initial timeout cooldown',async()=>{
 let online=true,calls=0;const f=fixture(async()=>{calls++;return calls>1},{canLoad:()=>online});await f.loader.load('initial');
 online=false;await f.timer.advance(1500);assert.equal(calls,1);online=true;assert.equal(await f.loader.load('event'),true);assert.equal(calls,2);assert.equal(f.state.error,false);
});

test('destroy cancels the retry timer and ignores an in-flight result without cancelling shared consumers',async()=>{
 const d=deferred(),f=fixture(()=>d.promise);const p=f.loader.load('initial');await flush();f.loader.dispose();const count=f.states.length;d.resolve(true);await p;assert.equal(f.states.length,count);assert.equal(f.timer.size(),0);
 const failed=fixture(async()=>false);await failed.loader.load('initial');assert.equal(failed.timer.size(),1);failed.loader.dispose();assert.equal(failed.timer.size(),0);await failed.timer.advance(10000);assert.equal(failed.states.at(-1).retrying,true); // no state writes after destruction
});

test('failed refresh preserves the last actual store snapshot and a later manual retry updates it',async()=>{
 const timer=timers(),s=marketStore(timer.clock),states=[];const loader=createHomeTickerLoader({request:s.dispatch,onState:x=>states.push(x),clock:timer.clock,setTimer:timer.setTimer,clearTimer:timer.clearTimer});
 let p=loader.load('initial');await flush();s.requests[0].resolve({data:{data:[{name:'UMI-USDT',last:'0.5321',updated_at:new Date(timer.clock()).toISOString()}]}});await p;
 const previous=s.store.state.items[0];await timer.advance(3000);p=loader.load('manual');await flush();s.requests[1].reject(new Error('offline'));await p;
 assert.equal(s.store.state.items[0],previous);assert.equal(s.store.state.items[0].last,'0.5321');assert.equal(states.at(-1).error,true);
 p=loader.load('manual');await flush();s.requests[2].resolve({data:{data:[{name:'UMI-USDT',last:'0.5330',updated_at:new Date(timer.clock()).toISOString()}]}});await p;
 assert.equal(s.store.state.items[0].last,'0.5330');assert.equal(states.at(-1).error,false);assert.equal(timer.size(),0);
});

test('home plus another ticker consumer share one request and timeout clears the shared in-flight slot',async()=>{
 const timer=timers(),s=marketStore(timer.clock);const home=s.dispatch(),other=s.transport('https://preview.invalid/api/v1/markets/ticker');assert.equal(s.requests.length,1);
 const failure=assert.rejects(other);s.requests[0].reject(new Error('timeout'));assert.equal(await home,false);await failure;
 const recovered=s.dispatch();assert.equal(s.requests.length,2);s.requests[1].resolve({data:{data:[]}});assert.equal(await recovered,true);
});

test('freshness uses actual quote time, not request completion, including missing/future/explicit stale cases',()=>{
 const now=Date.parse('2026-10-03T08:00:00Z'),quote={last:'2',updated_at:new Date(now-1000).toISOString()};assert.equal(homeQuoteIsStale(quote,now),false);
 for(const extra of [{last:null},{last:'0'},{updated_at:null},{updated_at:'2026-10-03 08:00:00'},{updated_at:new Date(now-90000).toISOString()},{updated_at:new Date(now+5001).toISOString()},{price_stale:true}])assert.equal(homeQuoteIsStale({...quote,...extra},now),true);
 assert.equal(homeQuoteIsStale({...quote,updated_at:new Date(now-90000).toISOString(),lastSuccessAt:now},now),true);
});

test('real home lifecycle wires bounded ticker recovery separately from its existing 30-second balance timer',async()=>{
 const events=new Map(),intervals=[],removed=[];let calls=0,disposed=false;
 const context={ActionIcon:{},MarketDisplay:{},DisplayPreferences:{},enabled:()=>false,sparkPaths:()=>[],homeQuoteIsStale,initialHomeTickerState,createHomeTickerLoader:options=>({load:reason=>{calls++;return options.request(reason)},dispose:()=>disposed=true}),axios:{CancelToken:{source:()=>({cancel(){}})}},document:{hidden:false,addEventListener:(n,f)=>events.set('document:'+n,f),removeEventListener:(n,f)=>removed.push([n,f])},window:{navigator:{onLine:true},matchMedia:()=>({matches:false}),addEventListener:(n,f)=>events.set('window:'+n,f),removeEventListener:(n,f)=>removed.push([n,f])},setInterval:fn=>{intervals.push(fn);return intervals.length},clearInterval(){},clearTimeout(){}};
 vm.runInNewContext(source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\s*$/gm,'').replace('export default {','globalThis.component={'),context);
 const c=context.component,ctx={...c.data(),markets:[],marketsStale:false,displayBanners:[],route:()=>'/ticker',$store:{dispatch:async()=>true}};for(const [name,fn]of Object.entries(c.methods))ctx[name]=fn.bind(ctx);ctx.loadBalance=()=>{};ctx.loadSparks=()=>{};
 c.mounted.call(ctx);await flush();assert.equal(calls,1);assert.equal(intervals.length,2);intervals.forEach(fn=>fn());assert.equal(calls,1);
 ctx.refreshTicker();assert.equal(calls,2);ctx.marketsStale=true;ctx.refreshVisible();assert.equal(calls,3);ctx.ticker.error=true;ctx.refreshVisible();assert.equal(calls,3);context.document.hidden=true;ctx.refreshTicker();ctx.refreshVisible();assert.equal(calls,3);
 c.beforeDestroy.call(ctx);assert.equal(disposed,true);assert.equal(removed.some(([n,f])=>n==='online'&&f===ctx.refreshTicker),true);assert.equal(removed.some(([n,f])=>n==='visibilitychange'&&f===ctx.refreshTicker),true);
 assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors,[]);
 assert.equal(c.computed.marketsStale.call({markets:[{updated_at:new Date().toISOString()}],ticker:{error:true},now:Date.now()}),true);
});


test('fresh websocket quotes are compared against the current clock between 30-second timer ticks',()=>{
 const now=Date.now(), context={ActionIcon:{},MarketDisplay:{},DisplayPreferences:{},homeQuoteIsStale};
 vm.runInNewContext(source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\s*$/gm,'').replace('export default {','globalThis.component={'),context);
 const ctx={markets:[{last:'100',updated_at:new Date(now-1000).toISOString(),price_stale:false}],ticker:{error:false},now:now-20000};
 assert.equal(context.component.computed.marketsStale.call(ctx),false);
 ctx.markets[0].updated_at=new Date(now-100000).toISOString();assert.equal(context.component.computed.marketsStale.call(ctx),true);
});

test('newer explicit freshness recovers the actual store and older stale messages cannot overwrite it',()=>{
 const now=Date.now(),s=marketStore(()=>now),update=market=>s.store.mutations.MARKET_UPDATE(s.store.state,{market});
 update({name:'UMI-USDT',last:'1.2',updated_at:new Date(now-20000).toISOString(),price_stale:true});
 update({name:'UMI-USDT',last:'1.21',updated_at:new Date(now-1000).toISOString(),price_stale:false});
 update({name:'UMI-USDT',last:'1.1',updated_at:new Date(now-30000).toISOString(),price_stale:true});
 assert.equal(s.store.state.items[0].price_stale,false);assert.equal(s.store.state.items[0].last,'1.21');
});
