import assert from 'node:assert/strict';
import test from 'node:test';
import {StockMarket, registerStockAssets, stockAsset} from '../../services/stock-data/stocks.mjs';
import {bstockByContract} from '../../services/stock-data/bstocks.mjs';

const contract='0xf2ec508422174ee564de98187db9359d318afb6b';
const asset=bstockByContract(contract);
const market={symbol:'DJTBUSDT',baseAsset:'DJTB',quoteAsset:'USDT',status:'TRADING',isSpotTradingAllowed:true,filters:[]};
function provider(overrides={}) {
    const calls=[];
    const api=new StockMarket(async url=>{
        const u=new URL(url);calls.push(u);
        assert.equal(u.origin,'https://api.binance.com');
        assert.equal(u.searchParams.get('symbol'),'DJTBUSDT');
        const method=u.pathname.split('/').pop();
        if (method in overrides) return overrides[method];
        return {
            exchangeInfo:{symbols:[market]},
            depth:{lastUpdateId:123,bids:[['9','2']],asks:[['9.1','3']]},
            '24hr':{symbol:'DJTBUSDT',lastPrice:'9.06',highPrice:'9.4',lowPrice:'9',priceChangePercent:'-3.4'},
            klines:[[1700000000000,'9','9.2','8.9','9.1','2',1700000299999]],
            aggTrades:[{a:42,p:'9.06',q:'1.8',T:1700000000000,m:false}],
        }[method];
    });
    return {api,calls};
}
test('DJTB exact contract maps to bStocks Spot; no Ondo, Alpha or synthetic trades',async()=>{
    const {api,calls}=provider();
    const {data}=await api.inspect({contract:contract.toUpperCase().replace('0X','0x')});
    assert.equal(data.asset.symbol,'DJTB');assert.equal(data.asset.issuer,'BTech Holdings Limited');
    assert.equal(data.asset.decimals,18);assert.equal(data.asset.tokenId,undefined);
    assert.equal(data.depth.source,'binance-spot');assert.equal(data.depth.executable,false);
    assert.equal(data.depth.event_time_kind,'snapshot_received');
    assert.equal(data.quote.price,'9.06');assert.equal(data.quote.underlyingPrice,null);
    const trades=await api.trades({symbol:'DJTB'});
    assert.equal(trades.source_symbol,'DJTBUSDT');assert.equal(trades.data[0].timestamp,1700000000000);
    assert.equal(trades.data[0].quantity,'1.8');
    const candles=await api.candles({symbol:'DJTB',interval:'5m',to:1700000300});
    assert.equal(candles.currency,'USDT');assert.equal(candles.data[0].close,9.1);
    assert.equal(calls.at(-1).searchParams.get('endTime'),'1700000300000');
    assert.equal(stockAsset('DJTB').contract,contract);
});
test('unreviewed identities and altered chain units cannot register as DJTB',()=>{
    for (const change of [{contract:'0x'+'1'.repeat(40)},{symbol:'DJT'},{decimals:6},{exchangeSymbol:'DJTUSDT'},
        {listingTemplate:undefined},{marketSource:'binance-alpha'},{chainId:1}]) {
        assert.throws(()=>registerStockAssets([{...asset,...change}]));
    }
});
test('wrong pair or suspended exchange market cannot supply executable reference depth',async()=>{
    registerStockAssets([asset]);
    for (const change of [{baseAsset:'OTHER'},{quoteAsset:'USD'},{status:'BREAK'},{isSpotTradingAllowed:false}]) {
        const {api}=provider({exchangeInfo:{symbols:[{...market,...change}]}});
        await assert.rejects(api.depth({symbol:'DJTB'}));
    }
});
test('invalid snapshots, crossed depth and malformed real trades fail closed',async()=>{
    for (const change of [{lastUpdateId:-1},{lastUpdateId:2**54},{bids:[['10','1']]},{asks:[['9.1','0']]}]) {
        const {api}=provider({depth:{lastUpdateId:1,bids:[['9','1']],asks:[['9.1','2']],...change}});
        await assert.rejects(api.depth({symbol:'DJTB'}));
    }
    await assert.rejects(provider({aggTrades:[{a:1,p:'9',q:'0',T:1700000000000}]}).api.trades({symbol:'DJTB'}));
});
