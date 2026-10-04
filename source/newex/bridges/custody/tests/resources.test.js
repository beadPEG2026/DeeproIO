'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
function fixture(resources={},balance=500000){
  const stats={signs:0,limits:[]};
  const tx=()=>({txID:'hash',raw_data_hex:'00'.repeat(100),raw_data:{expiration:Date.now()+60000}});
  const w={isAddress:()=>true,address:{toHex:a=>a,fromPrivateKey:()=> 'alice'},
    contract:()=>({at:async()=>({decimals:()=>({call:async()=>6}),balanceOf:()=>({call:async()=>2000000})})}),
    trx:{getBalance:async()=>balance,getAccountResources:async()=>resources,
      getChainParameters:async()=>[{key:'getEnergyFee',value:100},{key:'getTransactionFee',value:1000}],
      sign:async t=>{stats.signs++;return t;}},
    transactionBuilder:{triggerConstantContract:async()=>({result:{result:true},energy_used:100000}),
      triggerSmartContract:async(c,fn,options)=>{stats.limits.push(options.feeLimit);return {result:{result:true},transaction:tx()};}}};
  return {adapter:require('../tron')(w),stats,w,p:{sender:'alice',destination:'bob',contract:'token',amount:'2',max_fee:'20',private_key:'fixture'}};
}
test('delegated Energy reduces burn estimate, never the VM energy ceiling; quote cannot sign',async()=>{
 const {adapter,stats,p}=fixture({EnergyLimit:140000,EnergyUsed:10000});
 const q=await adapter.estimate(p);assert.equal(q.energy_required,'120000');assert.equal(q.energy_shortfall,'0');
 assert.equal(q.fee,'0.210000');assert.equal(q.native_shortfall,'0.000000');assert.equal(stats.signs,0);
 assert.ok(stats.limits.every(v=>v>=12000000));
 const prepared=await adapter.prepare(p);assert.equal(prepared.fee,'0.210000');assert.equal(stats.signs,1);
});
test('partial and exhausted Energy are priced at current chain rate with integer margin',async()=>{
 const {adapter,p}=fixture({EnergyLimit:70000,EnergyUsed:10000});const q=await adapter.estimate(p);
 assert.equal(q.energy_shortfall,'60000');assert.equal(q.fee,'6.210000');assert.equal(q.native_shortfall,'5.710000');
 const empty=fixture({EnergyLimit:1,EnergyUsed:100});assert.equal((await empty.adapter.estimate(empty.p)).fee,'12.210000');
});
test('resource provider failure and insufficient balance never sign',async()=>{
 const f=fixture();await assert.rejects(()=>f.adapter.prepare(f.p),/INSUFFICIENT_GAS/);assert.equal(f.stats.signs,0);
 f.w.trx.getAccountResources=async()=>{throw Error('RPC unavailable');};await assert.rejects(()=>f.adapter.prepare(f.p));assert.equal(f.stats.signs,0);
});
test('resources are refreshed before signing, preventing stale delegation quotes',async()=>{
 const f=fixture({EnergyLimit:140000});await f.adapter.estimate(f.p);f.w.trx.getAccountResources=async()=>({});
 await assert.rejects(()=>f.adapter.prepare(f.p),/INSUFFICIENT_GAS/);assert.equal(f.stats.signs,0);
});
test('low VM fee ceiling is rejected even with delegated energy',async()=>{
 const f=fixture({EnergyLimit:140000});await assert.rejects(()=>f.adapter.prepare({...f.p,max_fee:'1'}),/FEE_LIMIT/);assert.equal(f.stats.signs,0);
});
test('malformed or imprecise resource response fails closed',async()=>{
 for(const resources of [null,{EnergyLimit:-1},{EnergyLimit:'9007199254740993'},{EnergyLimit:'1.5'}]){
 const f=fixture(resources);await assert.rejects(()=>f.adapter.prepare(f.p),/RESOURCE_ESTIMATE_UNAVAILABLE/);assert.equal(f.stats.signs,0);
 }
});
