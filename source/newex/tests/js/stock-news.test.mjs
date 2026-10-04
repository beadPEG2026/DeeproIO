import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
function fixture(now){
 const requests=[],listeners=new Map();let interval,cleared=false;
 let clock=now;
 const Clock=now===undefined?Date:class extends Date{static now(){return clock}};
 const axios={isCancel:error=>!!error?.__CANCEL__,CancelToken:{source:()=>({token:{},cancel:()=>{}})},get:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))};
 const document={hidden:false,addEventListener:(k,fn)=>listeners.set(k,fn),removeEventListener:k=>listeners.delete(k)};
 const window={addEventListener:(k,fn)=>listeners.set(k,fn),removeEventListener:k=>listeners.delete(k)};
 let source=readFileSync(new URL('../../resources/js/Components/StockNews.vue',import.meta.url),'utf8').match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import axios from 'axios';/,'').replace('export default {','globalThis.component={');
 const context={axios,document,window,Date:Clock,setInterval:(fn,ms)=>{interval={fn,ms};return 1},clearInterval:()=>{cleared=true}};
 vm.runInNewContext(source,context);const c=context.component;const state={...c.data(),marketRegion:'HK',$i18n:{locale:'zh-CN'}};
 for(const[k,fn]of Object.entries(c.methods))state[k]=fn.bind(state);
 return {c,state,requests,document,listeners,setNow(value){clock=value},get interval(){return interval},get cleared(){return cleared}};
}
test('news refreshes every minute and on return without overlapping or blanking articles',async()=>{
 const f=fixture();f.c.mounted.call(f.state);assert.equal(f.interval.ms,60000);assert.equal(f.requests.length,1);
 f.requests[0].resolve({data:{items:[{title:'A'}],updatedAt:'2026-10-01T00:00:00Z'}});await new Promise(setImmediate);
 f.state.lastStarted=0;f.interval.fn();f.state.lastStarted=0;f.listeners.get('focus')();assert.equal(f.requests.length,2);assert.equal(f.state.items[0].title,'A');
 f.requests[1].reject(new Error('offline'));await new Promise(setImmediate);assert.equal(f.state.items[0].title,'A');
 f.document.hidden=true;f.state.lastStarted=0;f.interval.fn();assert.equal(f.requests.length,2);
 f.document.hidden=false;f.listeners.get('visibilitychange')();assert.equal(f.requests.length,3);
 f.c.beforeDestroy.call(f.state);assert.equal(f.cleared,true);assert.equal(f.listeners.size,0);
 f.requests[2].resolve({data:{items:[{title:'late'}]}});await new Promise(setImmediate);assert.equal(f.state.items[0].title,'A');
});
test('slow previous region cannot overwrite newly selected region',async()=>{
 const f=fixture();f.c.mounted.call(f.state);f.state.choose('US');assert.equal(f.requests.length,2);
 f.requests[1].resolve({data:{items:[{title:'US'}]}});await new Promise(setImmediate);
 f.requests[0].resolve({data:{items:[{title:'HK'}]}});await new Promise(setImmediate);assert.equal(f.state.items[0].title,'US');f.c.beforeDestroy.call(f.state);
});
test('a normal five-minute source refresh cycle includes a one-minute grace period',()=>{
 const fetchedAt=Date.parse('2026-10-03T00:00:00Z'),f=fixture(fetchedAt);
 f.state.updatedAt=new Date(fetchedAt).toISOString();
 for(const elapsed of [0,180000,180001,240000,299999,300000,359999,360000]){
  f.state.now=fetchedAt+elapsed;
  assert.equal(f.c.computed.stale.call(f.state),false,`healthy cache age ${elapsed}`);
 }
 f.state.now=fetchedAt+360001;
 assert.equal(f.c.computed.stale.call(f.state),true);
});
test('rereading the same cache does not renew its age; a real refresh clears the warning',async()=>{
 const fetchedAt=Date.parse('2026-10-03T00:00:00Z'),f=fixture(fetchedAt+420000);
 const load=f.state.load();
 assert.equal(f.requests[0].url,'/markets/data/news');
 f.requests[0].resolve({data:{items:[{title:'Cached'}],updatedAt:new Date(fetchedAt).toISOString()}});await load;
 assert.equal(f.c.computed.stale.call(f.state),true);
 const retry=f.state.load();
 f.requests[1].resolve({data:{items:[{title:'Cached'}],updatedAt:new Date(fetchedAt).toISOString()}});await retry;
 assert.equal(f.c.computed.stale.call(f.state),true);
 f.setNow(fetchedAt+480000);
 const refreshed=f.state.load();
 f.requests[2].resolve({data:{items:[{title:'Updated'}],updatedAt:new Date(fetchedAt+470000).toISOString()}});await refreshed;
 assert.equal(f.c.computed.stale.call(f.state),false);
 assert.equal(f.state.items[0].title,'Updated');
});
test('HTTP and network failures are reported immediately while recent articles remain visible',async()=>{
 for(const error of [Object.assign(new Error('HTTP 503'),{response:{status:503}}),new Error('offline')]){
  const fetchedAt=Date.parse('2026-10-03T00:00:00Z'),f=fixture(fetchedAt+60000);
  f.state.updatedAt=new Date(fetchedAt).toISOString();f.state.items=[{title:'Cached'}];
  const load=f.state.load();f.requests[0].reject(error);await load;
  assert.equal(f.state.error,true);
  assert.equal(f.c.computed.stale.call(f.state),false);
  assert.equal(f.state.items[0].title,'Cached');
  assert.equal(f.state.loading,false);
 }
});
