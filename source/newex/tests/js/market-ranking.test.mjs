import test from 'node:test';
import assert from 'node:assert/strict';
import {rankedMarkets, stableMarketOrder, readFavorites, numeric} from '../../resources/js/Functions/MarketRanking.mjs';
const row=(name,extra={})=>({name:name+'-USDT',base_currency:name,base_currency_name:name+' token',quote_currency:'USDT',status:true,trade_status:true,qVolume:100,listed:1000,change:1,...extra});
test('ranking excludes invisible and incomplete websocket rows, separates product and quote units',()=>{
 const data=[row('BTC',{qVolume:5}),row('ETH',{qVolume:20,has_futures:true}),row('HIDDEN',{status:false}),row('QUOTE',{quote_currency:'BTC',qVolume:1000}),row('INCOMPLETE',{base_currency:null})];
 assert.deepEqual(rankedMarkets(data).map(m=>m.name),['ETH-USDT','BTC-USDT']);
 assert.deepEqual(rankedMarkets(data,{product:'futures'}).map(m=>m.name),['ETH-USDT']);
 assert.equal(rankedMarkets(data,{product:'options'}).length,0);
});
test('gainers/losers filter direction without coercing missing values to zero',()=>{
 const data=[row('UP',{change:'2.3'}),row('DOWN',{change:'-4'}),row('DOWN2',{change:-1}),row('ZERO',{change:0}),row('EMPTY',{change:''}),row('NULL',{change:null}),row('BAD',{change:'NaN'}),row('INF',{change:Infinity})];
 assert.deepEqual(rankedMarkets(data,{rank:'gainers'}).map(m=>m.name),['UP-USDT']);
 assert.deepEqual(rankedMarkets(data,{rank:'losers'}).map(m=>m.name),['DOWN-USDT','DOWN2-USDT']);
 assert.equal(numeric(0),0);for(const value of [null,undefined,'',false,'abc',Infinity])assert.equal(numeric(value),null);
});
test('watchlist is real, recoverable from corrupted storage and combined with search/sector',()=>{
 for(const bad of [null,'{bad','null','{}','false'])assert.deepEqual(readFavorites(bad),[]);
 assert.deepEqual(readFavorites('["BTC-USDT",null,1,"BTC-USDT"]'),['BTC-USDT']);
 const data=[row('BTC'),row('GOOGLon',{stock_token:true,base_currency_name:'谷歌'})];
 assert.deepEqual(rankedMarkets(data,{rank:'favorites'}),[]);
 assert.equal(rankedMarkets(data,{rank:'favorites',favorites:['GOOGLon-USDT'],search:'谷歌',sector:'stock_token'}).length,1);
 assert.equal(rankedMarkets(data,{rank:'favorites',favorites:['BTC-USDT'],sector:'stock_token'}).length,0);
});
test('new list uses epoch time, rejects missing/future dates and has deterministic ties',()=>{
 const data=[row('OLD',{listed:100}),row('NEW',{listed:200}),row('FUTURE',{listed:400}),row('MISSING',{listed:null}),row('SAME',{listed:200})];
 assert.deepEqual(rankedMarkets(data,{rank:'new',now:300000}).map(m=>m.name),['NEW-USDT','SAME-USDT','OLD-USDT']);
});
test('live ticks do not reshuffle existing rows or mutate the store; refresh explicitly reranks',()=>{
 const initial=[row('BTC',{qVolume:300}),row('ETH',{qVolume:200})];const saved=structuredClone(initial);
 const order=rankedMarkets(initial).map(m=>m.name);
 assert.deepEqual(initial,saved);
 const tick=[row('BTC',{qVolume:1,last:90000}),row('ETH',{qVolume:900,last:3000})];
 assert.deepEqual(stableMarketOrder(rankedMarkets(tick),order).map(m=>m.name),['BTC-USDT','ETH-USDT']);
 assert.equal(stableMarketOrder(rankedMarkets(tick),order)[0].last,90000);
 assert.deepEqual(rankedMarkets(tick).map(m=>m.name),['ETH-USDT','BTC-USDT']);
});
test('missing turnover stays last and actual zero remains a sortable value',()=>{
 assert.deepEqual(rankedMarkets([row('MISSING',{qVolume:null}),row('ZERO',{qVolume:0}),row('ACTIVE',{qVolume:10})]).map(m=>m.name),['ACTIVE-USDT','ZERO-USDT','MISSING-USDT']);
});
test('USDT and USDC pair searches resolve to the existing bidirectional market',()=>{
 const data=[row('USDC'),row('BTC'),row('UMI')];
 for(const search of ['USDT/USDC','USDC/USDT','usdt-usdc','USDT ⇄ USDC']) {
  assert.deepEqual(rankedMarkets(data,{search}).map(m=>m.name),['USDC-USDT']);
 }
 assert.equal(rankedMarkets(data,{search:'USDT/USDT'}).length,0);
});
