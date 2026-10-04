import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
test('first load failure surfaces retry while pagination failure preserves an already loaded chart',()=>{
 const posted=[],failures=[];
 class UDF {getBars(symbol,resolution,period,done,fail){fail('upstream unavailable')}}
 const context={window:{},Datafeeds:{UDFCompatibleDatafeed:UDF},parent:{postMessage:m=>posted.push(m)},location:{origin:'https://deepro.io'}};
 vm.runInNewContext(readFileSync(new URL('../../public/js/deepro-chart-feed.js',import.meta.url),'utf8'),context);
 const feed=context.window.createDeeproChartFeed('/tradingview-chart',{symbol:{listed_exchange:'HKEX'}});
 feed.getBars({},'1',{firstDataRequest:false},()=>{},e=>failures.push(e));assert.equal(posted.length,0);assert.equal(failures.length,1);
 feed.getBars({},'1',{firstDataRequest:true},()=>{},e=>failures.push(e));assert.equal(posted.length,1);assert.equal(posted[0].type,'deepro-chart-error');assert.equal(failures.length,2);
});
