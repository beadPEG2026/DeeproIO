import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import * as display from '../../resources/js/Functions/UserDisplay.mjs';
import {marketSnapshotTime, isOlderSnapshot, pickQuote} from '../../resources/js/Functions/MarketSnapshot.mjs';

test('fee and token decimals retain nonzero sub-cent values and 18-digit precision', () => {
    for (const [value,expected] of [['0.00005000','0.00005'],['0.000000000000000001','0.000000000000000001'],['1000000000000000000.100000000000000001','1000000000000000000.100000000000000001'],['2.000000000000000000','2'],['1e-8','0.00000001'],['001.200','1.2'],['-0.000','0'],['5e2','500']]) assert.equal(display.displayDecimal(value),expected);
    for (const value of [null,undefined,'invalid',Infinity,'1e900']) assert.equal(display.displayDecimal(value),'—');
});
test('90-day 5.1 percent is a period return, shortest option drives listing APR', () => {
    assert.equal(display.annualizedRate('5.1',90),'20.68');
    assert.deepEqual(display.firstStakingPeriod({ranges:{90:5.1,30:1.81}}),{days:'30',reward:'1.81',annualized:'22.02'});
    assert.equal(display.firstStakingPeriod({ranges:{}}),null);
    assert.equal(display.annualizedRate(1,0),'—');
});
test('legacy deposit offset, UTC, browser zone and naive server zone refer to same instant', () => {
    const values=['2026-09-26 10:00:00 +08:00','2026-09-26T04:00:00+02:00','2026-09-26T02:00:00Z'];
    for (const value of values) assert.equal(display.parseTimestamp(value).toISOString(),'2026-09-26T02:00:00.000Z');
    assert.equal(display.serverTimestamp('2026-09-26 04:00:00','Europe/Berlin').toISOString(),'2026-09-26T02:00:00.000Z');
    assert.equal(display.serverTimestamp('2026-01-26 03:00:00','Europe/Berlin').toISOString(),'2026-01-26T02:00:00.000Z');
    assert.equal(display.localTradeTime(values[0]),display.localTradeTime(values[1]));
    for (const invalid of ['',null,'not a date','2026-09-26 10:00:00']) assert.equal(display.parseTimestamp(invalid),null);
});
test('download links exclude placeholders, executable schemes and embedded credentials', () => {
    for (const v of ['https://www.google.com','https://example.com/app.apk','http://download.example.org/app.apk','javascript:alert(1)','https://u:p@download.deepro.io/app.apk','']) assert.equal(display.publishedDownloadUrl(v),'');
    assert.equal(display.publishedDownloadUrl('https://apps.apple.com/app/id123'),'https://apps.apple.com/app/id123');
});
test('stock token market TRADING is recognized and stale/paused/unavailable take priority', () => {
    const q={price:100,marketStatus:'TRADING'};
    assert.equal(display.stockQuoteStatus(q),'Token market quoting');
    assert.equal(display.stockQuoteStatus({...q,stale:true}),'Quote delayed');
    assert.equal(display.stockQuoteStatus(q,false),'Token trading paused');
    assert.equal(display.stockQuoteStatus({...q,unavailable:true}),'No data');
});
test('missing option limits block ordering, explicit zero remains unlimited', () => {
    for (const values of [[null,null],[null,100],[1,''],[10,5],[-1,10],[1,NaN]]) assert.equal(display.optionsLimitsReady(...values),false);
    for (const values of [[0,0],['0.1','10'],[20,0]]) assert.equal(display.optionsLimitsReady(...values),true);
});
test('200 incoming trades never overwrite rolling ticker high, low, volume or change', () => {
    const text=readFileSync(new URL('../../resources/js/Store/Modules/markets.js',import.meta.url),'utf8').replace(/^import .*;?\s*$/gm,'').replace('export default {','globalThis.store = {');
    const context={Vue:{set:(o,k,v)=>o[k]=v},marketSnapshotTime,isOlderSnapshot,pickQuote,window:{},VueWorker:{$emit(){}}};
    for (const k of ['MARKET_LIST','MARKET_TRADE_LIST','MARKET_UPDATE','MARKET_UPDATE_STATS','MARKET_TRADE_STORE','OPTIONS_TRADE_LIST']) context[k]=k;
    vm.runInNewContext(text,context);const {state,mutations,actions}=context.store;
    const commit=(k,v)=>mutations[k](state,v);
    const quote={name:'BTC-USDT',last:'80000',high:'86000',low:'78000',volume:'22000',change:'1.45',updated_at:1750000000000};
    commit('MARKET_UPDATE',{market:quote});
    for(let i=1;i<=200;i++) actions.updateMarketTrade({state,commit},{market:quote,realtime:true,trade:{price:String(80000+i),timestamp:1750000000000+i*1000}});
    assert.equal(state.items[0].last,'80200');
    for (const k of ['high','low','volume','change']) assert.equal(state.items[0][k],quote[k]);
    commit('MARKET_UPDATE_STATS',{market:{...quote,high:'87000',low:'77000',volume:'23000',updated_at:1750000201000}});
    assert.equal(state.items[0].high,'87000');
});
test('staking collection arrays keep actual duration, never array indices',()=>{
 const legacy={allowed_days:'30,60,90',rewards_percentage:'1.81,3.61,5.1',ranges:['1.81','3.61','5.1']};
 assert.deepEqual(display.firstStakingPeriod(legacy),{days:'30',reward:'1.81',annualized:'22.02'});
 assert.deepEqual(display.stakingPeriods(legacy).map(p=>p.annualized),['22.02','21.96','20.68']);
 assert.equal(display.firstStakingPeriod({ranges:['1.81','3.61','5.1']}),null);
 assert.equal(display.firstStakingPeriod({periods:[{days:30,period_rate:1.81,apr:'1317.65'}],ranges:[9,9]}).annualized,'22.02');
 assert.equal(display.firstStakingPeriod({periods:[{days:30,period_rate:0}]}).annualized,'0.00');
 for(const rates of [[.037,.054,.072],[2.11,4.03,5.97]])assert.equal(display.firstStakingPeriod({...legacy,ranges:rates}).annualized,display.annualizedRate(rates[0],30));
 assert.equal(display.firstStakingPeriod({allowed_days:'7,14,30',ranges:['.153','.30','.86']}).annualized,'7.98');
 assert.equal(display.firstStakingPeriod({allowed_days:'30,90,180,365',ranges:['.2','.8','1.3','2.4']}).annualized,'2.43');
});
