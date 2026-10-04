import test from 'node:test';
import assert from 'node:assert/strict';
import {displayMarket, usdtEquivalent, formatUsdt} from '../../resources/js/Functions/MarketDisplay.mjs';
import {resolveEstimateMarket} from '../../resources/js/Functions/OrderEstimate.mjs';
const now=Date.parse('2026-10-01T02:00:00Z');
const market={name:'HK00388-USDT',base_currency:'HK00388',quote_currency:'USDT',price_reference_product:true,last:null,volume:'0'};
const quote={symbol:'HK00388',price:null,currency:'USDT',unavailable:true,underlyingPrice:'387.800',underlyingHigh:'388.600',underlyingLow:'384.000',underlyingChange:'0.26',underlyingCurrency:'HKD',sourceTime:'2026-09-30T08:08:33Z',receivedAt:'2026-09-30T08:08:34Z',marketStatus:'closed'};
test('holiday display retains the actual HKD close and timestamp without mutating execution state',()=>{
 const out=displayMarket(market,{HK00388:quote},now);
 assert.equal(out.last,'387.800');assert.equal(out.displayCurrency,'HKD');assert.equal(out.quoteClosed,true);assert.equal(out.quoteSourceTime,quote.sourceTime);assert.equal(out.change,'0.26');assert.equal(market.last,null);
 assert.equal(resolveEstimateMarket(market,quote,now).last,null);
});
test('wrong symbol/currency, future or excessively old close is never shown',()=>{
 for(const delta of [{symbol:'HK00700'},{underlyingCurrency:'USD'},{underlyingPrice:'NaN'},{sourceTime:'2026-10-02T00:00:00Z'},{sourceTime:'2026-09-01T00:00:00Z'}])assert.equal(displayMarket(market,{HK00388:{...quote,...delta}},now).last,null);
});
test('non HK markets keep their existing price and unit',()=>{
 const out=displayMarket({...market,price_reference_product:false,last:'49.7'},{HK00388:quote},now);assert.equal(out.last,'49.7');assert.equal(out.displayCurrency,undefined);
});

test('USDT approximation is separate from HKD and cannot alter order estimates',()=>{
 const fx={base:'HKD',quote:'USDT',hkdPerUsdt:'7.8',eventTime:now/1000};
 const out=displayMarket(market,{HK00388:quote},now,fx);
 assert.equal(out.last,'387.800');assert.equal(out.approximateUsdt,387.8/7.8);assert.equal(formatUsdt(out.approximateUsdt),'49.72');
 assert.equal(formatUsdt(usdtEquivalent('0.102',fx,now)),'0.013077');
 assert.equal(resolveEstimateMarket(market,quote,now).last,null);
 for(const delta of [{base:'USD'},{quote:'USD'},{hkdPerUsdt:0},{hkdPerUsdt:'Infinity'},{eventTime:now/1000+6},{eventTime:now/1000-604801}])assert.equal(usdtEquivalent('10',{...fx,...delta},now),null);
});
