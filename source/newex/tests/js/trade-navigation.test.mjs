import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
import {tradeViewport,ticketScrollTop,ticketIsVisible,positionTradeTicket} from '../../resources/js/Functions/TradeNavigation.mjs';

const require=createRequire(import.meta.url),compiler=require('vue-template-compiler');
const read=path=>readFileSync(new URL('../../resources/js/'+path,import.meta.url),'utf8');
const marketSource=read('Pages/Market/Market.vue'),template=read('Themes/default/Web/Pages/Market/Market.template');
const navSource=read('Components/TradeCategoryNav.vue');
function options(source,extra={}) {
 let script=source.match(/<script>([\s\S]*?)<\/script>/)[1];
 const context={...Object.fromEntries([...script.matchAll(/^import (\w+) from/gm)].map(m=>[m[1],{}])),Template:value=>value,tradeViewport,ticketScrollTop,ticketIsVisible,positionTradeTicket,...extra};
 script=script.replace(/^import .*\n/gm,'').replace('export default','globalThis.component =');
 vm.runInNewContext(script,context);return context.component;
}
const rect=(top,height,width=300)=>({top,bottom:top+height,height,width});
function harness({blocked=false,scrollY=0,ticketTop=764,reduced=false,headerBottom=97}={}) {
 const calls=[],frames=[];
 const ticket={getBoundingClientRect:()=>rect(ticketTop,550),focus:value=>calls.push(['focus',value])};
 const doc={body:{classList:{contains:()=>false}},querySelectorAll:selector=>selector.includes('header-section')?[{getBoundingClientRect:()=>rect(0,48)},{getBoundingClientRect:()=>rect(48,headerBottom-48)}]:[{getBoundingClientRect:()=>rect(780,64)}]};
 const win={performance:{now:()=>0},setTimeout:()=>1,clearTimeout:()=>{},addEventListener:()=>{},requestAnimationFrame:fn=>{frames.push(fn);return frames.length},cancelAnimationFrame:id=>calls.push(['cancel',id]),innerWidth:390,innerHeight:844,scrollY,matchMedia:()=>({matches:reduced}),dispatchEvent:e=>calls.push(['event',e.type]),scrollTo:value=>calls.push(['scroll',value]),removeEventListener:name=>calls.push(['remove',name]),visualViewport:{height:844,removeEventListener:name=>calls.push(['visual-remove',name])}};
 const c=options(marketSource,{document:doc,window:win,Event:class {constructor(type){this.type=type}},requestAnimationFrame:fn=>{frames.push(fn);return frames.length},cancelAnimationFrame:id=>calls.push(['cancel',id])});
 const ctx={...c.data(),sessionBlocked:blocked,$nextTick:fn=>fn(),$refs:{tradeTicket:ticket,tradeForm:{setTab:side=>calls.push(['side',side]),openForm:true}},$worker:{$off:()=>{}},stopKlineChangeWatcher:()=>{}};
 for(const [key,fn] of Object.entries(c.methods))ctx[key]=fn.bind(ctx);
 return {c,ctx,calls,frames,win,doc,ticket};
}

test('shortcut visibility measures usable viewport and ignores the shortcut itself to avoid a feedback loop',()=>{
 const h=harness();assert.deepEqual(tradeViewport(h.doc,h.win),{top:97,bottom:780});
 assert.equal(ticketIsVisible(rect(764,550),{top:97,bottom:780}),false);
 assert.equal(ticketIsVisible(rect(105,550),{top:97,bottom:780}),true);
 assert.equal(ticketIsVisible(rect(-500,550),{top:97,bottom:780}),false);
 assert.equal(ticketIsVisible(rect(105,0,0),{top:97,bottom:780}),false);
 assert.equal(ticketIsVisible(rect(105,550),{top:97,bottom:220}),false);
 assert.equal(ticketScrollTop(rect(764,550),0,97),659);
 assert.equal(ticketScrollTop(rect(49,550),715,97),659);
 assert.equal(ticketScrollTop(rect(0,550),0,97),0);
});
test('default trade layout keeps shortcuts reachable until enough of the actual form is visible',()=>{
 const h=harness();assert.equal(h.ctx.mobileFirstTab,'trade');assert.equal(h.ctx.ticketInView,false);
 assert.doesNotMatch(template,/dp-ticket-active|v-if="sessionBlocked"/);
 h.ctx.queueTicketVisibility();h.ctx.queueTicketVisibility();assert.equal(h.frames.length,1);h.frames.shift()();assert.equal(h.ctx.ticketInView,false);
 h.ticket.getBoundingClientRect=()=>rect(105,550);h.ctx.queueTicketVisibility();h.frames.shift()();assert.equal(h.ctx.ticketInView,true);
 h.ticket.getBoundingClientRect=()=>rect(-600,550);h.ctx.queueTicketVisibility();h.frames.shift()();assert.equal(h.ctx.ticketInView,false);
});
test('both buy and sell enter the actual form below the current header without submitting an order',()=>{
 for(const side of ['buy','sell']){
  const h=harness();h.ctx.mobileFirstTab='orderbook';h.ctx.openTicket(side);
  assert.equal(h.ctx.mobileFirstTab,'trade');assert.equal(h.ctx.$refs.tradeForm.openForm,false);
  assert.deepEqual(h.calls.filter(c=>c[0]==='side'),[['side',side]]);
  assert.equal(h.calls.find(c=>c[0]==='focus')[1].preventScroll,true);
  const scroll=h.calls.find(c=>c[0]==='scroll')[1];assert.equal(scroll.top,659);assert.equal(scroll.behavior,'smooth');
 }
 const h=harness({headerBottom:121,reduced:true});h.ctx.openTicket('buy');
 const scroll=h.calls.find(c=>c[0]==='scroll')[1];assert.equal(scroll.top,635);assert.equal(scroll.behavior,'auto');
});
test('closed market shortcuts still navigate without placing an order',()=>{
 for(const side of ['buy','sell']){const h=harness({blocked:true});h.ctx.openTicket(side);assert.deepEqual(h.calls.filter(c=>c[0]==='side'),[['side',side]]);assert.equal(h.ctx.$refs.tradeForm.openForm,false)}
});
test('mobile trade tab navigates while preserving order side; other panels do not force a scroll',()=>{
 const h=harness();h.ctx.setFirstTab('trade');assert.equal(h.calls.filter(c=>c[0]==='scroll').length,1);assert.equal(h.calls.some(c=>c[0]==='side'),false);
 h.ctx.setFirstTab('orderbook');assert.equal(h.ctx.mobileFirstTab,'orderbook');assert.equal(h.calls.filter(c=>c[0]==='scroll').length,1);
 h.c.beforeDestroy.call(h.ctx);assert.ok(h.calls.some(c=>c[0]==='remove'&&c[1]==='scroll'));assert.ok(h.calls.some(c=>c[0]==='cancel'));
});
test('all five categories remain visible; only enabled real products navigate',()=>{
 const messages=[],visits=[];const c=options(navSource,{clearTimeout(){},setTimeout(fn){messages.push(fn);return 1}});
 const ctx={...c.data(),market:{name:'UMI-USDT',has_futures:0},$t:key=>'translated:'+key,route:(route,name)=>route+'/'+name,$inertia:{visit:url=>visits.push(url)}};
 for(const [key,fn]of Object.entries(c.methods))ctx[key]=fn.bind(ctx);
 assert.deepEqual(Array.from(c.computed.items.call(ctx),i=>i.key),['futures','spot','margin','onchain','bots']);
 assert.ok(c.computed.items.call(ctx).every(i=>i.label.startsWith('translated:')));
 for(const key of ['futures','margin','onchain','bots']){ctx.choose(key);assert.equal(ctx.message,'translated:This feature is not available yet')}
 assert.deepEqual(visits,[]);ctx.choose('spot');assert.deepEqual(visits,['market/UMI-USDT']);
 for(const enabled of [true,1,'1']){ctx.market.has_futures=enabled;ctx.choose('futures');assert.equal(visits.at(-1),'futures-market/UMI-USDT')}
 for(const disabled of [false,0,'0',null,undefined]){ctx.market.has_futures=disabled;const n=visits.length;ctx.choose('futures');assert.equal(visits.length,n)}
 messages.at(-1)();assert.equal(ctx.message,'');
});
test('actual templates compile, ticket has a focus target, and Lite retains the same workbench',()=>{
 assert.deepEqual(compiler.compile(template).errors,[]);
 assert.deepEqual(compiler.compile(compiler.parseComponent(navSource).template.content).errors,[]);
 assert.match(template,/ref="tradeTicket" tabindex="-1"/);
 assert.match(read('Pages/MarketLite/Market.vue'),/import Market from '@\/Pages\/Market\/Market.vue'/);
});


test('retired acquisition shortcut is removed while ordinary buy and sell deep links remain',()=>{
 const source=read('Components/MarketOverview.vue');
 assert.doesNotMatch(source,/usdtUrl|Get USDT|dp-market-usdt-entry/);
 assert.doesNotMatch(marketSource,/receivingUsdt|receive=USDT|usdtAcquisitionParams/);
 for(const side of ['buy','sell']) {
  const h=harness();h.win.location={search:'?side='+side};h.win.visualViewport.addEventListener=()=>{};
  const c=options(marketSource,{window:h.win,URLSearchParams,_:{isEmpty:()=>false},ResizeObserver:class {observe(){}},document:h.doc});
  Object.assign(h.ctx,{market:{data:{name:'USDT-USDC'}},futures:false,loadFavorites(){},startKlineChangeWatcher(){},$el:{querySelector:()=>null}});h.ctx.$worker.$on=()=>{};
  c.mounted.call(h.ctx);assert.equal(h.calls.find(c=>c[0]==='side')[1],side);
 }
});

function positioningHarness({documentTop=764, smoothInProgress=false, reduced=false}={}) {
 let time=0, sequence=0;
 const listeners=new Map(), frames=new Map(), timers=new Map(), calls=[];
 const win={innerHeight:844,scrollY:0,performance:{now:()=>time},matchMedia:()=>({matches:reduced}),
  requestAnimationFrame:fn=>{const id=++sequence;frames.set(id,fn);return id},cancelAnimationFrame:id=>frames.delete(id),
  setTimeout:(fn,ms)=>{const id=++sequence;timers.set(id,{at:time+ms,fn});return id},clearTimeout:id=>timers.delete(id),
  addEventListener:(name,fn)=>{if(!listeners.has(name))listeners.set(name,new Set());listeners.get(name).add(fn)},
  removeEventListener:(name,fn)=>listeners.get(name)?.delete(fn),
  scrollTo:value=>{calls.push({at:time,...value});if(value.behavior!=='smooth'||!smoothInProgress)win.scrollY=Math.round(value.top)}
 };
 const ticket={isConnected:true,getBoundingClientRect:()=>rect(documentTop-win.scrollY,630),focus:()=>{}};
 const doc={querySelectorAll:selector=>selector.includes('header-section')?[{getBoundingClientRect:()=>rect(48,49)}]:[]};
 const step=ms=>{time+=ms;for(const [id,timer] of [...timers])if(timer.at<=time){timers.delete(id);timer.fn()}const pending=[...frames.values()];frames.clear();pending.forEach(fn=>fn(time))};
 const emit=(name,target=ticket)=>[...(listeners.get(name)||[])].forEach(fn=>fn({type:name,target}));
 return {win,ticket,doc,calls,step,emit,moveLayout:value=>{documentTop=value},active:()=>[...listeners.values()].reduce((n,set)=>n+set.size,0)+frames.size+timers.size};
}

test('late layout shrink that placed the 320px USDT section above the header is corrected after the scroll settles',()=>{
 const h=positioningHarness({documentTop:777.203125});
 positionTradeTicket(h.ticket,h.doc,h.win);
 assert.equal(h.win.scrollY,672);
 h.moveLayout(764.203125);
 assert.equal(h.ticket.getBoundingClientRect().top,92.203125,'the former one-shot scroll now overlaps the 97px header');
 h.step(16);h.step(120);
 assert.equal(h.calls.length,2);assert.equal(h.calls[1].behavior,'instant');
 assert.equal(h.ticket.getBoundingClientRect().top,105.203125);
 assert.ok(h.ticket.getBoundingClientRect().top>=97 && h.ticket.getBoundingClientRect().top<=177);
 // Browser scroll anchoring preserves the same gap when chart controls arrive;
 // the stabilizer must not add a redundant correction.
 h.moveLayout(805.203125);h.win.scrollY+=41;h.step(120);h.step(120);
 assert.equal(h.calls.length,2);
});
test('correction waits for the initial native smooth scroll to settle instead of interrupting each frame',()=>{
 const h=positioningHarness({smoothInProgress:true});positionTradeTicket(h.ticket,h.doc,h.win);
 h.moveLayout(751);
 for(const y of [30,180,340,510,620,659]){h.win.scrollY=y;h.step(100);assert.equal(h.calls.length,1)}
 h.step(100);assert.equal(h.calls.length,1);
 h.step(20);assert.equal(h.calls.length,2);assert.equal(h.win.scrollY,646);assert.equal(h.ticket.getBoundingClientRect().top,105);
});
test('steady and reduced-motion positioning do not keep scrolling; correction lifetime and count are bounded',()=>{
 const steady=positioningHarness({reduced:true});positionTradeTicket(steady.ticket,steady.doc,steady.win);
 assert.equal(steady.calls[0].behavior,'auto');steady.step(120);steady.step(120);assert.equal(steady.calls.length,1);
 steady.step(1560);assert.equal(steady.active(),0);steady.moveLayout(820);steady.step(120);assert.equal(steady.calls.length,1);
 const h=positioningHarness();positionTradeTicket(h.ticket,h.doc,h.win);
 for(let n=1;n<=5;n++){h.moveLayout(764-n*13);h.step(120);h.step(120)}
 assert.equal(h.calls.length,4,'one initial move plus at most three corrections');assert.equal(h.active(),0);
});
test('wheel, touch, scrollbar pointer, keyboard, editing and form focus immediately relinquish positioning',()=>{
 for(const name of ['wheel','touchstart','pointerdown','keydown','input','focusin','blur']){
  const h=positioningHarness({smoothInProgress:true});positionTradeTicket(h.ticket,h.doc,h.win);
  h.win.scrollY=200;h.emit(name,{});
  assert.equal(h.active(),0,name+' removes all observation');
  assert.deepEqual(h.calls.at(-1),{at:0,top:200,behavior:'instant'},name+' stops only the pending animation at the current position');
  h.moveLayout(820);h.win.scrollY=260;h.step(120);h.step(1800);
  assert.equal(h.calls.length,2,name+' does not pull back after user action');assert.equal(h.win.scrollY,260);
 }
});
test('ticket focus remains allowed; replacement navigation, removal and teardown remove every old callback',()=>{
 const h=positioningHarness();const stop=positionTradeTicket(h.ticket,h.doc,h.win);h.emit('focusin');assert.ok(h.active()>0);
 stop();stop();assert.equal(h.active(),0);
 const replacement=positionTradeTicket(h.ticket,h.doc,h.win);h.moveLayout(751);h.step(120);
 assert.equal(h.calls.length,3,'only the replacement can correct');replacement();assert.equal(h.active(),0);
 const removed=positioningHarness();positionTradeTicket(removed.ticket,removed.doc,removed.win);removed.ticket.isConnected=false;removed.step(16);assert.equal(removed.active(),0);
 const c=harness(), stopped=[];c.ctx.stopTicketPositioning=()=>stopped.push('old');c.ctx.openTicket('buy');assert.deepEqual(stopped,['old']);
 let cancelled=0;c.ctx.stopTicketPositioning=()=>cancelled++;c.ctx.setFirstTab('orderbook');assert.equal(cancelled,1);c.c.beforeDestroy.call(c.ctx);assert.equal(cancelled,2);
});
