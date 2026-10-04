import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {sessionView} from '../../resources/js/Functions/MarketSession.mjs';
import {pickQuote} from '../../resources/js/Functions/MarketSnapshot.mjs';

const at=Date.parse('2026-09-29T11:59:50+08:00');
const market={price_reference_product:true,trading_session:{state:'open',can_trade:true,
    server_time:new Date(at).toISOString(),closes_at:'2026-09-29T12:00:00+08:00',next_open_at:'2026-09-29T13:00:00+08:00'}};
test('market closes on the boundary even before the next server poll',()=>{
    assert.equal(sessionView(market,at+9999).blocked,false);
    assert.deepEqual(sessionView(market,at+10000),{blocked:true,label:'Market closed'});
});
test('failed/stale polling cannot keep an open ticket enabled',()=>{
    const m={...market,trading_session:{...market.trading_session,closes_at:'2026-09-29T16:00:00+08:00'}};
    assert.equal(sessionView(m,at+30001).blocked,true);
    assert.equal(sessionView(m,NaN).blocked,true);
    assert.equal(sessionView({price_reference_product:true},at).blocked,true);
});
test('a new session only reopens after server confirmation; suspension stays paused',()=>{
    const m={...market,trading_session:{...market.trading_session,state:'lunch',can_trade:false}};
    assert.equal(sessionView(m,at+20000).label,'Lunch break');
    assert.deepEqual(sessionView(m,Date.parse(m.trading_session.next_open_at)),{blocked:true,label:'Updating market status'});
    m.trading_session.state='suspended';assert.equal(sessionView(m,at).label,'Trading paused');
});
test('ordinary cryptocurrency markets remain unaffected and session metadata survives merging',()=>{
    assert.equal(sessionView({price_reference_product:false},at).blocked,false);
    assert.deepEqual(pickQuote({...market,last:'1'}),{last:'1',trading_session:market.trading_session});
});
test('HK daily datafeed polls once a minute while other markets retain existing frequency',()=>{
    let interval;class Feed{constructor(url,milliseconds){interval=milliseconds;}}
    const window={};vm.runInNewContext(readFileSync(new URL('../../public/js/deepro-chart-feed.js',import.meta.url),'utf8'),{window,Datafeeds:{UDFCompatibleDatafeed:Feed}});
    window.createDeeproChartFeed('/history',{symbol:{listed_exchange:'HKEX'}});assert.equal(interval,60000);
    window.createDeeproChartFeed('/history',{symbol:{listed_exchange:'Deepro'}});assert.equal(interval,2000);
});
