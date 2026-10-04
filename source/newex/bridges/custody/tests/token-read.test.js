'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {createRequire}=require('node:module');
const path=require('node:path');

test('EVM token reads decode RPC results using the installed Web3 ABI decoder',async()=>{
  const local=createRequire(path.join(__dirname,'../../bnb/app.js'));
  const Web3=local('web3'),calls=[];
  const provider={send(payload,done){
    calls.push(payload.method);
    let result;
    if(payload.method==='eth_chainId')result='0x38';
    else if(payload.method==='eth_getBalance')result='0xde0b6b3a7640000';
    else if(payload.method==='eth_call'){
      const data=payload.params[0].data;
      if(data==='0x313ce567')result='0x'+(18n).toString(16).padStart(64,'0');
      else if(data.startsWith('0x70a08231'))result='0x'+(1234000000000000000n).toString(16).padStart(64,'0');
      else return done(Error('Unexpected contract call'));
    }else return done(Error('Unexpected RPC method: '+payload.method));
    done(null,{jsonrpc:'2.0',id:payload.id,result});
  }};
  const adapter=require('../evm')(new Web3(provider),'bnb');
  assert.deepEqual(await adapter.balance({sender:'0x'+'1'.repeat(40),contract:'0x'+'2'.repeat(40)}),{
    balance:'1.234000000000000000',native_balance:'1.000000000000000000',decimals:18
  });
  assert.equal(calls.filter(m=>m==='eth_call').length,2);
  assert.ok(calls.every(m=>!m.includes('send')));
});

test('TRC20 reads provide request-local owners without setting a global signer',async()=>{
  const reads=[];
  const adapter=require('../tron')({
    isAddress:a=>['alice','bob','token'].includes(a),
    contract:()=>({at:async()=>({
      decimals:()=>({call:async options=>{
        assert.ok(['alice','bob'].includes(options?.from),'missing read owner');
        reads.push(['decimals',options.from]);return 6;
      }}),
      balanceOf:owner=>({call:async options=>{
        assert.equal(options?.from,owner,'owner leaked between requests');
        reads.push(['balanceOf',owner]);return owner==='alice'?'1234567':'2000000';
      }})
    })}),
    trx:{getBalance:async()=>3000000},
    setAddress:()=>assert.fail('must not mutate shared default address'),
    setPrivateKey:()=>assert.fail('read-only queries must not set a private key')
  });
  const results=await Promise.all(['alice','bob'].map(sender=>adapter.balance({sender,contract:'token'})));
  assert.deepEqual(results.map(r=>r.balance),['1.234567','2.000000']);
  assert.ok(results.every(r=>r.native_balance==='3.000000'&&r.decimals===6));
  assert.deepEqual(await adapter.validate({sender:'bob',contract:'token'}),{valid:true});
  assert.equal(reads.length,5);
});
