'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const TronWeb=require('tronweb');
const {wrapProviders}=require('../rpc-governance');
test('installed TronWeb startup probes and all three providers remain governed',async()=>{
 const H=TronWeb.providers.HttpProvider,original=H.prototype.request;let calls=0,admissions=0;
 H.prototype.request=async function(){calls++;return {configNodeInfo:{codeVersion:'4.7.4'},block_header:{raw_data:{number:123}}};};
 try {
  const providers=[0,1,2].map(()=>new H('https://fixture.invalid',1000,false,false,{'TRON-PRO-API-KEY':'fixture'}));
  wrapProviders(providers,{acquire:async()=>{admissions++;},outcome:async()=>{}});
  const web3=new TronWeb(...providers);
  assert.equal(web3.fullNode,providers[0]);assert.equal(web3.solidityNode,providers[1]);assert.equal(web3.eventServer,providers[2]);
  await web3.trx.getCurrentBlock();await web3.solidityNode.request('walletsolidity/getnowblock');await web3.eventServer.request('v1/fixture');
  assert.ok(calls>=4);assert.equal(calls,admissions);
 }finally{H.prototype.request=original;}
});
