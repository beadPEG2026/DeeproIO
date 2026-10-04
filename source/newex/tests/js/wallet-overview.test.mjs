import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
const require=createRequire(import.meta.url),{transformSync}=require('@babel/core');
const source=readFileSync(new URL('../../resources/js/Pages/Wallet/NewWallets.vue',import.meta.url),'utf8');
const code=transformSync(source.slice(source.indexOf('<script>')+8,source.lastIndexOf('</script>')),{babelrc:false,configFile:false,plugins:[require.resolve('@babel/plugin-transform-modules-commonjs')]}).code;
function overview(wallets=[],markets=[]) {
 const exports={},sandbox={exports,require:name=>name.startsWith('{Template}')?{__esModule:true,default:options=>options}:{},clearTimeout,setTimeout};vm.runInNewContext(code,sandbox);
 const options=exports.default,state=options.data();Object.assign(state,{$store:{getters:{getWallets:wallets,getMarkets:markets}},$t:key=>key,$inertia:{visit(){}},route:(name,arg)=>name+':'+String(arg)});
 for(const [key,value] of Object.entries(options.methods))state[key]=value.bind(state);
 for(const [key,value] of Object.entries(options.computed))Object.defineProperty(state,key,{get:value.bind(state)});
 return state;
}
test('BTC conversion works with zero BTC holdings when wallet API provides its rate',()=>{
 const state=overview([{symbol:'USDT',balance_in_wallet:'100',balance_in_wallet_usd:'100'},{symbol:'BTC',balance_in_wallet:'0',usd_rate:'100000'}]);
 assert.equal(state.totalBalanceUSD,100);assert.equal(state.totalBalanceBTC,0.001);
});
test('missing BTC quote stays unavailable instead of showing a made-up zero estimate',()=>{
 const state=overview([{symbol:'USDT',balance_in_wallet:'100'}]);assert.equal(state.totalBalanceBTC,null);
});
test('BTC market quote is available even without a BTC wallet row',()=>{
 const state=overview([{symbol:'USDT',balance_in_wallet:'100'}],[{base_currency:'BTC',quote_currency:'USDT',last:'50000'}]);assert.equal(state.totalBalanceBTC,0.002);
});
test('asset list uses API currency name and filters its existing balances',()=>{
 const state=overview([{symbol:'BTC',currency:'Bitcoin',balance_in_wallet:'0.1',usd_rate:'50000'},{symbol:'USDT',currency:'Tether USD',balance_in_wallet:'0'}]);
 assert.equal(state.assetRows[0].name,'Bitcoin');assert.equal(state.assetRows[0].totalAmount,0.1);
 state.hideSmallAssets=true;assert.equal(state.assetRows.length,1);state.search='Tether';assert.equal(state.assetRows.length,0);
});
