import test from 'node:test';
import {spotOrderPayload} from '../../resources/js/Functions/SpotOrderPayload.mjs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {orderRequestFailure} from '../../resources/js/Functions/OrderRequestFailure.mjs';
import {marketSnapshotTime, snapshotTime, isOlderSnapshot, pickQuote} from '../../resources/js/Functions/MarketSnapshot.mjs';
import {requestIntent, completeIntent, spotIntent} from '../../resources/js/Functions/RequestIntent.mjs';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
function scriptComponent(path, extra = {}) {
    let source = read(path).match(/<script>([\s\S]*?)<\/script>/)[1];
    source = source.replace(/^import .*;?\s*$/gm, '').replace('export default Template(', 'globalThis.component = Template(').replace('export default {','globalThis.component = {');
    const context = {spotOrderPayload,requestIntent, completeIntent, spotIntent, Template: x => x, MarketSession: {}, OrderEstimate: {}, OrderFeedback: {}, MarketMixin: {}, AppLayout: {}, TextInput: {}, SelectInput: {}, VueSlider: {}, mapGetters: () => ({}), ...extra};
    vm.runInNewContext(source, context);
    return context.component;
}
function store() {
    let source = read('Store/Modules/markets.js').replace(/^import .*;?\s*$/gm, '').replace('export default {', 'globalThis.module = {');
    const emitted = [];
    const context = {Vue: {set: (obj,key,val) => obj[key]=val}, marketSnapshotTime,isOlderSnapshot,pickQuote, window: {}, VueWorker: {$emit: (...args) => emitted.push(args)}};
    for (const key of ['MARKET_LIST','MARKET_TRADE_LIST','MARKET_UPDATE','MARKET_UPDATE_STATS','MARKET_TRADE_STORE','OPTIONS_TRADE_LIST']) context[key]=key;
    vm.runInNewContext(source, context);
    const {state, mutations}=context.module;
    return {state, emitted, commit: (key, value) => mutations[key](state, value)};
}
const market = (last, updated_at, name='UMI-USDT') => ({name,last,updated_at});

test('late websocket and delayed REST never roll back a newer quote', () => {
    const s=store();
    s.commit('MARKET_LIST',{markets:[market('1','2026-09-25T01:00:00Z')]});
    s.commit('MARKET_UPDATE_STATS',{market:market('3','2026-09-25T01:00:02Z')});
    s.commit('MARKET_UPDATE_STATS',{market:market('2','2026-09-25T01:00:01Z')});
    s.commit('MARKET_LIST',{markets:[market('1','2026-09-25T01:00:00Z')]});
    assert.equal(s.state.items[0].last,'3'); assert.equal(s.emitted.length,1);
    s.commit('MARKET_LIST',{markets:[market('4','2026-09-25T01:00:03Z')]});
    assert.equal(s.state.items[0].last,'4');
    s.commit('MARKET_UPDATE_STATS',{market:market('0',null)});
    assert.equal(s.state.items[0].last,'4');
});
test('snapshot order is per market and null prices and partial stats are retained faithfully', () => {
    const s=store();
    s.commit('MARKET_UPDATE_STATS',{market:{...market('3',1750000000000),high:'4',low:'2'}});
    s.commit('MARKET_UPDATE_STATS',{market:market('80000',1749999999000,'BTC-USDT')});
    s.commit('MARKET_UPDATE_STATS',{market:market(null,1750000001000)});
    assert.equal(s.state.items[0].last,null); assert.equal(s.state.items[0].high,'4');
    assert.equal(s.state.items[1].last,'80000');
    assert.equal(snapshotTime(1750000000),1750000000000);
    assert.equal(snapshotTime('2026-09-25 00:00:00'),0);
    assert.equal(snapshotTime('2026-09-25T02:00:00+02:00'),Date.parse('2026-09-25T00:00:00Z'));
    for (const input of [undefined,null,'invalid',-1,Infinity]) assert.equal(snapshotTime(input),0);
});
test('network, proxy timeout and 5xx preserve uncertainty even when backend has an errors field', () => {
    for (const status of [0,200,408,500,502,503]) {
        const result=orderRequestFailure({response:{status,data:{errors:{quantity:['failure']},message:'secret'}}});
        assert.equal(result.uncertain,true); assert.equal(JSON.stringify(result).includes('secret'),false);
    }
    assert.equal(orderRequestFailure(new Error('Network Error')).uncertain,true);
    for (const status of [401,403,419,422,429]) assert.equal(orderRequestFailure({response:{status,data:{}}}).uncertain,false);
    const r=orderRequestFailure({response:{status:422,data:{errors:{quantity:'Too small', price:['Invalid price']}}}});
    assert.deepEqual(r.fields,[{field:'quantity',message:'Too small'},{field:'price',message:'Invalid price'}]);
});
test('desktop and lite forms retain fields and handle both sides after a rejected request', async () => {
    for (const page of ['Market','MarketLite']) {
        const c=scriptComponent(`Pages/${page}/Partials/OrderForm.vue`,{legacyText:x=>x, axios:{post: async (_url,_payload,options) => {assert.equal(options.timeout,20000); throw {response:{status:503}};}}});
        for (const side of ['Buy','Sell']) {
            const ctx={...c.data(),market:{name:'UMI-USDT'},$page:{props:{user:{}}},canSubmitOrder:()=>true,
                syncBuyQuantityFromQuote(){},syncBuyQuoteFromQuantity(){},syncSellQuantityFromQuote(){},syncSellQuoteFromQuantity(){},
                getOrderAccountType:()=> 'real',getVirtualBalanceSource:()=>null,quoteWallet:{},baseWallet:{},route:x=>x,
                showOrderFailure(error, actualSide, lite){this.failure={error,actualSide,lite};},
                clearForm(){throw Error('Must preserve uncertain input');}};
            ctx.bid={price:'1.23',quantity:'2',quoteQuantity:'2.46'};ctx.ask={...ctx.bid};
            c.methods[`place${side}Order`].call(ctx);
            await new Promise(resolve=>setImmediate(resolve));
            assert.equal(ctx.failure.actualSide,side.toLowerCase());
            assert.equal(ctx[`placing${side}Order`],false); assert.equal(ctx.bid.quantity,'2');
        }
    }
});
test('orderbook destroys only its own listeners and ignores pressure from other markets', () => {
    const listeners=new Map(), off=[];
    const c=scriptComponent('Pages/Market/Partials/OrderBook.vue',{setInterval:()=>1,clearInterval(){},document:{addEventListener(){},removeEventListener(){}}});
    const ctx={...c.data(),market:{name:'UMI-USDT'},fetchOrderbook(){},initializeGrouping(){},closeGroupingDropdown(){},marketChangeWatcher(){},
        $worker:{$on:(event,fn)=>listeners.set(event,fn),$off:(event,fn)=>off.push([event,fn])}};
    c.mounted.call(ctx);
    listeners.get('marketTradePressure')({market:'BTC-USDT',buy:20,sell:80});
    assert.equal(ctx.buyPressure,null); assert.equal(ctx.sellPressure,null);
    listeners.get('marketTradePressure')({market:'UMI-USDT',buy:40,sell:60});
    assert.equal(ctx.buyPressure,40); assert.equal(ctx.sellPressure,60);
    listeners.get('marketTradePressure')({market:'BTC-USDT',buy:20,sell:80});
    assert.equal(ctx.buyPressure,40); assert.equal(ctx.sellPressure,60);
    c.beforeDestroy.call(ctx);
    for (const [event,fn] of off) assert.equal(fn,listeners.get(event));
    assert.equal(off.length,2);
});
test('kline polling is serial, backs off, pauses hidden pages and rejects responses after destruction', async () => {
    let resolve, reject, calls=0, hidden=false; const timers=new Map();let seq=0;const dispatched=[];
    const document={get hidden(){return hidden;},addEventListener(){},removeEventListener(){}};
    const c=scriptComponent('Mixins/Market/MarketMixin.vue',{document,
        axios:{get:()=>{calls++;return new Promise((a,b)=>{resolve=a;reject=b;});}},
        setTimeout:(fn,ms)=>{timers.set(++seq,{fn,ms});return seq;},clearTimeout:id=>timers.delete(id)});
    const ctx={...c.data(), market:{data:{id:15}},$page:{props:{}},$store:{dispatch:(...args)=>dispatched.push(args)}};
    for(const [name,fn] of Object.entries(c.methods))ctx[name]=fn.bind(ctx);
    const tick=async()=>{const [id,t]=timers.entries().next().value;timers.delete(id);return t.fn();};
    ctx.startKlineChangeWatcher();const first=tick();assert.equal(calls,1);assert.equal(timers.size,0);
    reject(new Error('offline'));await first;assert.equal([...timers.values()][0].ms,6000);
    hidden=true;await tick();assert.equal(calls,1);hidden=false;
    const second=tick();ctx.stopKlineChangeWatcher();resolve({data:{market:market('1',1750000000000)}});await second;
    assert.equal(dispatched.length,0);assert.equal(timers.size,0);
});
