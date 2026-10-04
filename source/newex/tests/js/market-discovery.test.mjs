import test from 'node:test';
import assert from 'node:assert/strict';
import {loadWatchlist,saveWatchlist,assetRows,filterDiscovery,sortRows,breadth,sectorSummaries,sparkPaths} from '../../resources/js/Functions/MarketDiscovery.mjs';
const storage=()=>{const m=new Map();return {getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,v)}};
test('legacy favorites migrate once and deleted stock does not reappear',()=>{const s=storage();s.setItem('marketFavorites','["BTC-USDT"]');s.setItem('deepro.stockFavorites','["AAPLon","BTC-USDT"]');assert.deepEqual(loadWatchlist(s),['BTC-USDT','AAPLon-USDT']);s.setItem('marketFavorites','["BTC-USDT"]');assert.deepEqual(loadWatchlist(s),['BTC-USDT']);saveWatchlist(s,['SPYon-USDT'],['SPYon']);assert.deepEqual(loadWatchlist(s),['SPYon-USDT']);});
test('malformed favorites recover without adding default coins',()=>{const s=storage();s.setItem('marketFavorites','oops');assert.deepEqual(loadWatchlist(s),[])});
const rows=[{name:'BTC-USDT',base_currency:'BTC',change:1,stock_token:false},{name:'SOL-USDT',base_currency:'SOL',change:-2,stock_token:'0'},{name:'AAPLon-USDT',base_currency:'AAPLon',change:null,stock_token:1},{name:'HIDE-USDT',base_currency:'HIDE',stock_token:true}];
test('catalog filters hidden stock and leaves boolean/string crypto flags',()=>{const r=assetRows(rows,[{symbol:'AAPLon',name:'苹果',ticker:'AAPL',assetType:'stock'}]);assert.equal(r.length,3);assert.equal(filterDiscovery(r).length,2);assert.equal(filterDiscovery(r,{category:'stocks',search:'苹果'}).length,1);assert.equal(filterDiscovery(r,{category:'stocks',stockType:'etf'}).length,0)});
test('missing values stay last for either sort direction',()=>{for(const d of [1,-1])assert.equal(sortRows(rows.slice(0,3),'change',d).at(-1).name,'AAPLon-USDT')});
test('breadth deduplicates assets, distinguishes zero from missing',()=>{assert.deepEqual(breadth([...rows,{base_currency:'BTC',change:9},{base_currency:'USDC',change:0}]),{up:1,down:1,flat:1,unknown:2})});
test('sectors use reviewed members and require two available quotes',()=>{const r=sectorSummaries(rows);assert.equal(r.length,1);assert.equal(r[0].change,-0.5);assert.equal(sectorSummaries(rows,[{name:'custom',symbols:['AAPLon','HIDE']}]).length,0)});
test('sparklines hide missing history and split real time gaps',()=>{assert.deepEqual(sparkPaths([{time:0,value:1}]),[]);assert.equal(sparkPaths([{time:0,value:1},{time:3600,value:2},{time:18000,value:3},{time:21600,value:4}]).length,2);assert.equal(sparkPaths([{time:0,value:1},{time:3600,value:1}])[0],'2.0,18.0 90.0,18.0')});
test('stock regions separate HK products from US tokenized stocks and keep both in all',()=>{const markets=[{name:'HK08379-USDT',base_currency:'HK08379',stock_token:true},{name:'AAPLon-USDT',base_currency:'AAPLon',stock_token:true}];const assets=[{symbol:'HK08379',region:'HK',assetType:'stock'},{symbol:'AAPLon',assetType:'stock'}];const enriched=assetRows(markets,assets);assert.equal(filterDiscovery(enriched,{category:'stocks',stockRegion:'HK'})[0].base_currency,'HK08379');assert.equal(filterDiscovery(enriched,{category:'stocks',stockRegion:'US'})[0].base_currency,'AAPLon');assert.equal(filterDiscovery(enriched,{category:'stocks',stockRegion:'all'}).length,2)});

test('daily HK trend joins real trading days across weekends without invented hourly candles',()=>{
 const points=[{time:0,value:10},{time:86400,value:12},{time:4*86400,value:11}];
 assert.equal(sparkPaths(points,true)[0],'2.0,32.0 46.0,4.0 90.0,18.0');assert.deepEqual(sparkPaths(points),[]);
});
