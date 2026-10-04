'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {budget,wrapProviders}=require('../rpc-governance');
test('Node shares the PHP key hash and honors database cooldown before sending',async()=>{
  const calls=[];const b=budget({query:async(sql,args)=>{calls.push({sql,args});return {rows:[{admitted:false,wait_ms:120000}]};}},'fixture-key');
  await assert.rejects(b.acquire(),/SHARED_COOLDOWN/);
  assert.equal(calls[0].args[0],require('node:crypto').createHash('sha256').update('fixture-key').digest('hex'));assert.equal(calls[0].args[2],'node');
});
test('One cache and inflight request across full/solid/event providers, no balance cache',async()=>{
  let sent=0,admitted=0;const providers=[0,1,2].map(()=>({request:async()=>{sent++;return {block_header:{raw_data:{number:123}}};}}));
  wrapProviders(providers,{acquire:async()=>{admitted++;},outcome:async()=>{}});
  await Promise.all(providers.map(p=>p.request('wallet/getnowblock',{},'post')));assert.equal(sent,1);assert.equal(admitted,1);
  await providers[0].request('wallet/getaccount',{address:'test'},'post');await providers[0].request('wallet/getaccount',{address:'test'},'post');assert.equal(sent,3);
});
test('429 and 403 publish cooldown once, sanitize errors, never retry signed broadcast',async()=>{
  for(const status of [429,403]){
    let sent=0;const outcomes=[];const p={request:async()=>{sent++;throw {response:{status,headers:{'retry-after':'120'},config:{headers:{key:'secret'}}}};}};
    wrapProviders([p],{acquire:async()=>{},outcome:async(...a)=>outcomes.push(a)});
    await assert.rejects(p.request('wallet/broadcasttransaction',{signature:['fixture']},'post'),new RegExp('^Error: TRONGRID_HTTP_'+status+'$'));
    assert.equal(sent,1);assert.deepEqual(outcomes,[[status,'120']]);
  }
});
test('HTTP-200 provider error is also a shared limit and is not cached',async()=>{
  const outcomes=[];const p={request:async()=>({Error:'The key exceeds the frequency limit'})};
  wrapProviders([p],{acquire:async()=>{},outcome:async(s)=>outcomes.push(s)});
  await assert.rejects(p.request('wallet/getnowblock'),/429/);assert.deepEqual(outcomes,[429]);
});

test('already reserved requests recheck a newly published cooldown before RPC',async()=>{
 let queries=0;const b=budget({query:async()=>({rows:[++queries===1?{admitted:true,wait_ms:1}:{blocked:true}]})},'fixture-key');
 await assert.rejects(b.acquire(),/SHARED_COOLDOWN/);assert.equal(queries,2);
});
