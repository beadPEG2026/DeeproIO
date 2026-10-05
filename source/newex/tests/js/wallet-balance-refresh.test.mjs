import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import * as balance from '../../resources/js/Functions/WalletBalance.mjs';
import * as handoff from '../../resources/js/Functions/WalletHandoff.mjs';
import * as tradeDraft from '../../resources/js/Functions/TradeFormDraft.mjs';
import {keyboardVisible,keepInputVisible} from '../../resources/js/Functions/KeyboardViewport.mjs';
const require=createRequire(import.meta.url),{transformSync}=require('@babel/core');
const defer=()=>{let resolve,reject;const promise=new Promise((r,j)=>{resolve=r;reject=j});return {promise,resolve,reject}};
function load(path,stubs={}){
 let source=readFileSync(new URL('../../resources/js/'+path,import.meta.url),'utf8');if(source.includes('<script>'))source=source.split('<script>')[1].split('</script>')[0];
 const code=transformSync(source,{babelrc:false,configFile:false,plugins:[require.resolve('@babel/plugin-transform-modules-commonjs')]}).code;
 const exports={};vm.runInNewContext(code,{exports,Date,URLSearchParams,axios:stubs.axios,require(name){
  if(name.startsWith('{Template}'))return {__esModule:true,default:v=>v};
  if(name==='vue')return {__esModule:true,default:{set:(rows,index,row)=>rows[index]=row}};
  if(name==='@/Store/Mutations/User')return {SET_USER:'SET_USER'};
  if(name==='vuex')return {mapGetters:()=>({})};
  if(name==='@/Store/Mutations/Wallet')return Object.fromEntries(['WALLET_LIST','WALLET_UPDATE'].map(v=>[v,v]));
  if(name==='@/Functions/WalletBalance.mjs')return balance;
  if(name==='@/Functions/WalletHandoff.mjs')return handoff;
  if(name==='@/Functions/LegacyTranslation')return {legacyText:v=>'翻译:'+v};
  return {};
 }});return exports.default;
}
const memory=()=>{const rows=new Map();return {setItem:(k,v)=>rows.set(k,v),getItem:k=>rows.get(k),removeItem:k=>rows.delete(k)}};
test('wallet decimals preserve all ledger digits and MAX truncates without rounding above balance',()=>{
 assert.equal(balance.balanceDecimal('19.886727810000000000'),'19.88672781');
 assert.equal(balance.balanceDecimal('0.000000009',8),'0');
 assert.equal(balance.balanceDecimal('19.999999999',8),'19.99999999');
 assert.equal(balance.addBalance('9007199254740993.123456789','0.000000001'),'9007199254740993.12345679');
 assert.equal(balance.compareBalance('1.000000000000000001','1'),1);
 assert.equal(balance.balanceDecimal('invalid'),null);
 assert.equal(balance.percentageBalance('1.99999999',100,3),'1.999');
 assert.equal(balance.percentageBalance('19.88672781',100,2),'19.88');
 assert.equal(balance.percentageBalance('0.000000000000000009',50,18),'0.000000000000000004');
});
test('snapshot awaits HTTP, newest request wins, errors are explicit and zero remains valid',async()=>{
 const queue=[defer(),defer(),defer()];let n=0;
 const m=load('Store/Modules/wallets.js',{axios:{get:()=>queue[n++].promise}}),state={...m.state,items:[]};
 const commit=(key,payload)=>m.mutations[key](state,payload),ctx={state,commit};
 let done=false;const first=m.actions.fetchWallets(ctx,'/wallets').then(r=>{done=true;return r});assert.equal(done,false);assert.equal(state.balanceStatus,'loading');
 const second=m.actions.fetchWallets(ctx,'/wallets');queue[1].resolve({data:{data:[{symbol:'USDT',balance_in_wallet:'0'}]}});assert.equal(await second,true);
 queue[0].resolve({data:{data:[{symbol:'USDT',balance_in_wallet:'20'}]}});assert.equal(await first,false);assert.equal(state.items[0].balance_in_wallet,'0');
 const failed=m.actions.fetchWallets(ctx,'/wallets');queue[2].reject(new Error('offline'));assert.equal(await failed,false);assert.equal(state.balanceStatus,'error');assert.equal(state.items[0].balance_in_wallet,'0');
 assert.equal(balance.walletBalanceReady('error',100,110),false);assert.equal(balance.walletBalanceReady('ready',100,60100),false);
});
test('live wallet push cannot be replaced by an older HTTP snapshot; new symbols are inserted',async()=>{
 const d=defer(),m=load('Store/Modules/wallets.js',{axios:{get:()=>d.promise}}),state={...m.state,items:[]},commit=(k,p)=>m.mutations[k](state,p);
 const request=m.actions.fetchWallets({state,commit},'/wallets');commit('WALLET_UPDATE',{wallet:{symbol:'USDT',balance_in_wallet:'5'}});d.resolve({data:{data:[{symbol:'USDT',balance_in_wallet:'0'}]}});
 assert.equal(await request,false);assert.equal(state.items.length,1);assert.equal(state.items[0].balance_in_wallet,'5');assert.equal(state.balanceStatus,'stale');
});
test('transfer balance follows the latest currency/direction and failure does not become zero',async()=>{
 const requests=[defer(),defer(),defer()];let n=0;
 const c=load('Pages/Wallet/Transfer.vue',{axios:{get:()=>requests[n++].promise}});const x={...c.data(),$page:{props:{user:{id:1}}},route:v=>v,$t:v=>v};
 for(const [k,v] of Object.entries(c.methods))x[k]=v.bind(x);for(const k of ['currentUser','balanceType','shouldUseVirtualTransfer'])Object.defineProperty(x,k,{get:c.computed[k].bind(x)});
 x.form.currency_id=2;const a=x.loadBalance();x.form.from_account='trade';const b=x.loadBalance();
 requests[1].resolve({data:{success:true,balance:'19.886727810000000000',virtual_balance:'500'}});await b;assert.equal(x.balance,'19.88672781');assert.equal(x.form.account_type,'real');
 requests[0].resolve({data:{success:true,balance:'0',virtual_balance:'0'}});await a;assert.equal(x.balance,'19.88672781');
 const third=x.loadBalance();requests[2].reject(new Error('timeout'));await third;assert.equal(x.balance,null);assert.equal(x.balanceStatus,'error');
 x.form.currency_id=null;await x.loadBalance();assert.equal(x.balance,null);assert.equal(x.balanceStatus,'idle');
});
test('account query and transfer direction are independent of UI language',()=>{
 const c=load('Pages/Wallet/Transfer.vue');const x={...c.data()};for(const [k,v]of Object.entries(c.methods))x[k]=v.bind(x);
 x.form.from_account='trade';assert.equal(c.computed.balanceType.call(x),'trade');
 x.form.from_account='funding';x.form.to_account='funding';x.normalizeTransferAccounts();assert.equal(x.form.to_account,'trade');
 assert.equal(c.computed.balanceType.call(x),'account');
});

test('funding handoff is owner-bound, expires, never retains 2FA or auto-submits',()=>{
 const storage=memory();handoff.saveWithdrawalDraft(7,'USDT',{address:'draft',amount:'2',network:8,twofa:'123456'},storage,1000);
 const draft=handoff.takeWithdrawalDraft(7,'USDT',storage,2000);assert.equal(draft.network,8);assert.equal(draft.twofa,undefined);assert.equal(handoff.takeWithdrawalDraft(7,'USDT',storage,2001),null);
 handoff.saveWithdrawalDraft(7,'USDT',{},storage,1000);assert.equal(handoff.takeWithdrawalDraft(8,'USDT',storage,2000),null);
 handoff.saveWithdrawalDraft(7,'USDT',{},storage,1000);assert.equal(handoff.takeWithdrawalDraft(7,'USDT',storage,9999999),null);
 assert.equal(handoff.transferContext('?symbol=USDT&from=trade&return=withdraw',{2:{id:2,symbol:'USDT'}}).withdraw,true);
 assert.equal(handoff.transferContext('?symbol=BAD&return=withdraw',{2:{id:2,symbol:'USDT'}}).withdraw,false);
});
test('withdrawal uses funding only and preserves trading balance for guidance',()=>{
 const c=load('Pages/Wallet/Withdraw/WithdrawCrypto.vue');const x={wallet:{balance_in_wallet:'0',balance_in_trade:'19.88672781'},isVirtualAccount:false,isInternalWithdraw:false,walletBalanceReady:true};
 assert.equal(c.computed.availableBalance.call(x),'0');assert.equal(c.computed.tradeAvailableBalance.call(x),'19.88672781');assert.equal(c.computed.balanceReady.call(x),true);
 x.walletBalanceReady=false;assert.equal(c.computed.balanceReady.call(x),false);
});
test('step controls respect eight/eighteen digits and never go below zero',()=>{
 assert.equal(tradeDraft.stepDecimal('0.00000001',8,-1),'0.00000000');assert.equal(tradeDraft.stepDecimal('0',8,-1),'0.00000000');
 assert.equal(tradeDraft.stepDecimal('9007199254740993.000000000000000001',18,1),'9007199254740993.000000000000000002');
 assert.equal(tradeDraft.tradeReturnMarket('trade_return=https://evil.invalid'),null);assert.equal(tradeDraft.tradeReturnMarket('trade_return=USDT-USDC'),'USDT-USDC');
});
test('keyboard state distinguishes toolbar/pinch changes; focused input scrolls above keyboard',()=>{
 assert.equal(keyboardVisible({focused:true,width:390,baseline:844,height:490}),true);assert.equal(keyboardVisible({focused:true,width:390,baseline:844,height:790}),false);
 assert.equal(keyboardVisible({focused:false,width:390,baseline:844,height:490}),false);assert.equal(keyboardVisible({focused:true,width:390,baseline:844,height:490,scale:2}),false);
 const scroll=[];keepInputVisible({getBoundingClientRect:()=>({top:420,bottom:480})},{height:400,offsetTop:0},{scrollBy:v=>scroll.push(v)});assert.equal(scroll[0].top,112);
});

test('virtual account source enums remain raw in translated trading screens',()=>{
 for(const [path,method] of [['Pages/Market/Partials/OrderForm.vue','getVirtualBalanceSource'],['Pages/MarketLite/Partials/OrderForm.vue','getVirtualBalanceSource'],['Pages/Market/Partials/FuturesOrderForm.vue','getFuturesVirtualBalanceSource'],['Pages/Market/Partials/OptionsOrderForm.vue','getVirtualOptionsBalanceSource']]) {
  const c=load(path);assert.equal(c.methods[method].call({toNumber:Number,getVirtualTradeBalance:w=>Number(w.balance_in_virtual_trade)},{balance_in_virtual_trade:'2'}),'trade');
 }
});

test('a newer shared balance response is fresh even when another component clock is older',()=>{
 const c=load('Mixins/WalletRefresh.js');const x={walletClock:1,walletBalanceStatus:'ready',$store:{getters:{getWalletBalanceUpdatedAt:Date.now()}}};
 assert.equal(c.computed.walletBalanceReady.call(x),true);
});


test('overview requests coalesce, recent snapshots survive navigation, payment entry still refreshes',async()=>{
 const d=defer();let calls=0;
 const m=load('Store/Modules/wallets.js',{axios:{get:()=>{calls++;return d.promise;}}}),state={...m.state,items:[]},commit=(k,p)=>m.mutations[k](state,p),ctx={state,commit};
 const input={route:'/wallets',ownerId:147,reuseRecent:true};
 const a=m.actions.fetchWallets(ctx,input),b=m.actions.fetchWallets(ctx,input);assert.equal(a,b);assert.equal(calls,1);
 d.resolve({data:{data:[{symbol:'USDT',balance_in_wallet:'19.88672781'}]}});await a;
 await m.actions.fetchWallets(ctx,input);assert.equal(calls,1);
 const pay=m.actions.fetchWallets(ctx,{...input,reuseRecent:false});assert.equal(calls,2);assert.equal(state.balanceStatus,'loading');
 assert.equal(balance.walletBalanceReady(state.balanceStatus,state.balanceUpdatedAt),false);
 assert.equal(balance.walletBalanceVisible(state.balanceStatus,state.balanceUpdatedAt),true);await pay;
 state.balanceUpdatedAt=Date.now()-16000;await m.actions.fetchWallets(ctx,input);assert.equal(calls,3);
 assert.equal(balance.walletBalanceVisible('error',Date.now()),false);
 assert.equal(balance.walletBalanceVisible('loading',Date.now()-60001),false);
});
test('page snapshot is owner bound, accepts actual zero, and cannot overwrite a live request',async()=>{
 const d=defer(),m=load('Store/Modules/wallets.js',{axios:{get:()=>d.promise}}),state={...m.state,items:[]},commit=(k,p)=>m.mutations[k](state,p);
 commit('walletBalanceOwner',147);
 commit('walletBalanceSnapshot',{owner_id:148,wallets:[{symbol:'USDT',balance_in_wallet:'99'}]});assert.equal(state.items.length,0);
 commit('walletBalanceSnapshot',{owner_id:147,wallets:[{symbol:'USDT',balance_in_wallet:'0'}]});assert.equal(state.balanceStatus,'ready');assert.equal(state.items[0].balance_in_wallet,'0');
 const request=m.actions.fetchWallets({state,commit},'/wallets');
 commit('walletBalanceSnapshot',{owner_id:147,wallets:[{symbol:'USDT',balance_in_wallet:'99'}]});assert.equal(state.items[0].balance_in_wallet,'0');
 commit('SET_USER',{user:{id:148}});assert.equal(state.items.length,0);assert.equal(state.balanceUpdatedAt,0);
 d.resolve({data:{data:[{symbol:'USDT',balance_in_wallet:'5'}]}});assert.equal(await request,false);assert.equal(state.items.length,0);
 commit('SET_USER',{user:null});assert.equal(state.balanceOwner,null);
});
