import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {finiteMetric, depthSeries} from '../../resources/js/Functions/TradingDisplay.mjs';

test('missing and corrupt metrics never turn into zero, while real zero is retained', () => {
 for (const value of [null, undefined, '', ' ', false, [], {}, 'NaN', Infinity, '1e999']) assert.equal(finiteMetric(value), false);
 for (const value of [0, '0', -3.21, '83972.01']) assert.equal(finiteMetric(value), true);
});
test('depth uses only existing positive levels and accumulates outward from the spread', () => {
 const rows = [{price:99,quantity:2},{price:100,quantity:3},{price:99,quantity:4},{price:101,quantity:0},{price:null,quantity:4},{price:102,quantity:-2}];
 const copy=JSON.stringify(rows);
 assert.deepEqual(depthSeries(rows,'bids'), [{price:100,quantity:3,cumulative:3},{price:99,quantity:6,cumulative:9}]);
 assert.deepEqual(depthSeries(rows,'asks'), [{price:99,quantity:6,cumulative:6},{price:100,quantity:3,cumulative:9}]);
 assert.equal(JSON.stringify(rows),copy); assert.deepEqual(depthSeries(null,'asks'),[]);
});
const source=readFileSync(new URL('../../public/js/deepro-chart-controls.js',import.meta.url),'utf8');
async function bridge({volume=true, fail=false, startupEvent=false}={}) {
 const listeners={}, messages=[], created=[], removed=[], earlyMessages=[];let resolution='1', id=0;
 const parent={postMessage: v=>messages.push(v)};const entities=new Set();
 const chart={resolution:()=>resolution, setResolution:(v,cb)=>{resolution=v;if(cb) cb();}, onIntervalChanged:()=>({subscribe(){}}), createStudy:async(name,...args)=>{if(startupEvent && id===0){listeners['deepro:chart-volume']();earlyMessages.push(...messages);}if(fail && name==='Bollinger Bands') throw Error('unavailable');created.push({name,args});entities.add(++id);return id;},getAllStudies:()=>[...entities].map(id=>({id})),removeEntity:id=>{removed.push(id);entities.delete(id);}};
 const window={deeproChartHasVolume:volume,addEventListener:(k,v)=>listeners[k]=v};
 vm.runInNewContext(source,{window,parent,location:{origin:'https://deepro.test'},sessionStorage:{getItem:()=>null,setItem(){} }});
 window.attachDeeproChartControls({activeChart:()=>chart,getStudiesList:()=>['Moving Average','Bollinger Bands','Volume']},{config:{supported_resolutions:['1','15','60','1D']}});
 await new Promise(r=>setImmediate(r));
 return {messages,created,removed,earlyMessages,chart,send:(data,source=parent,origin='https://deepro.test')=>listeners.message({data,source,origin})};
}
test('chart limits commands to its parent, own origin, supported studies and intervals', async () => {
 const b=await bridge();assert.equal(b.created.length,4);
 const cmd=(action,value)=>({type:'deepro-chart-command',action,value});
 await b.send(cmd('resolution','60'),{},'https://deepro.test');assert.equal(b.chart.resolution(),'1');
 await b.send(cmd('resolution','60'),undefined,'https://evil.test');assert.equal(b.chart.resolution(),'1');
 await b.send(cmd('resolution','garbage'));assert.equal(b.chart.resolution(),'1');
 await b.send(cmd('resolution','60'));assert.equal(b.chart.resolution(),'60');
 await b.send(cmd('indicator','EMA'));assert.equal(b.created.length,4);
 await b.send(cmd('indicator','MA'));assert.equal(b.removed.length,3);
 await b.send(cmd('indicator','MA'));assert.equal(b.created.length,7);
});
test('unavailable volume is absent; repeated clicks cannot create duplicate indicator groups', async () => {
 const b=await bridge({volume:false});assert.equal(b.created.length,3);
 assert.equal(b.messages.at(-1).indicators.includes('VOL'),false);
 await b.send({type:'deepro-chart-command',action:'indicator',value:'MA'});
 await Promise.all([b.send({type:'deepro-chart-command',action:'indicator',value:'MA'}),b.send({type:'deepro-chart-command',action:'indicator',value:'MA'})]);
 assert.equal(b.created.length,6);
});
test('study failure is reported without marking it active', async () => {
 const b=await bridge({fail:true});await b.send({type:'deepro-chart-command',action:'indicator',value:'BOLL'});
 assert.equal(b.messages.at(-1).error,true);assert.equal(b.messages.at(-1).active.includes('BOLL'),false);
});
test('reaching the end of history does not remove volume from the current chart', () => {
 const window={dispatchEvent(){}};let bars=[{volume:10}];
 class Feed {getBars(symbol,resolution,period,done) {done(bars,{});}}
 vm.runInNewContext(readFileSync(new URL('../../public/js/deepro-chart-feed.js',import.meta.url),'utf8'),{window,Datafeeds:{UDFCompatibleDatafeed:Feed},Event:class {}});
 const feed=window.createDeeproChartFeed('test',{});
 feed.getBars({},'1',{firstDataRequest:true},()=>{},()=>{});assert.equal(window.deeproChartHasVolume,true);
 bars=[];feed.getBars({},'1',{firstDataRequest:false},()=>{},()=>{});assert.equal(window.deeproChartHasVolume,true);
 bars=[{close:10}];feed.getBars({},'60',{firstDataRequest:true},()=>{},()=>{});assert.equal(window.deeproChartHasVolume,false);
});

test('removing an owned study through the native chart cannot leave the quick toggle stuck', async () => {
 const b=await bridge();b.chart.getAllStudies().forEach(s=>b.chart.removeEntity(s.id));
 await b.send({type:'deepro-chart-command',action:'indicator',value:'MA'});
 assert.equal(b.chart.getAllStudies().length,3);assert.equal(b.messages.at(-1).active.includes('MA'),true);
});

test('first data arriving during study initialization cannot expose controls that discard the first click', async () => {
 const b=await bridge({startupEvent:true});
 assert.equal(b.earlyMessages.length,0);
 assert.equal(b.messages.length,1);
 assert.equal(b.messages[0].active.includes('MA'),true);
 await b.send({type:'deepro-chart-command',action:'resolution',value:'60'});
 assert.equal(b.chart.resolution(),'60');
});
