'use strict';
// Deterministic RPC/SDK doubles: these prove adapter decisions, not live chain finality.
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const hash=s=>crypto.createHash('sha256').update(String(s)).digest();
const address=s=>({toString:()=>s,equals:other=>other.toString()===s});
const message=(id,info)=>({id,info,body:{hash:()=>hash(id)}});
const tx=(lt,msg,outputs=[],description={computePhase:{type:'vm',success:true},actionPhase:{success:true,skippedActions:0}})=>({lt:BigInt(lt),inMessage:msg,outMessages:new Map(outputs.map((o,i)=>[i,o])),description,hash:()=>hash(lt)});
function tonFixture(){
 const library={Address:{parse:address},storeMessage:m=>m,beginCell:()=>({store(m){this.m=m;return this;},endCell(){return {hash:()=>hash(this.m.id)};}})};
 const outgoing=message('sent',{type:'internal',dest:address('bob'),value:{coins:2000000000n}});
 const sender=tx(1000,message('intent',{type:'external-in'}),[outgoing]),receiver=tx(2000,outgoing);
 const data={alice:[sender],bob:[receiver],seqno:8},calls=[];
 const client={getTransactions:async(a,opts)=>{calls.push({address:a.toString(),...opts});const rows=data[a.toString()];const index=opts.lt?rows.findIndex(r=>r.lt.toString()===opts.lt)+(opts.inclusive?0:1):0;return rows.slice(index,index+100);},runMethod:async()=>({stack:{readNumber:()=>data.seqno}})};
 return {a:require('../ton')(library,{},client),p:{txn:hash('intent').toString('hex'),sender:'alice',destination:'bob',amount:'2',prepared:{seqno:7,expires_at:1}},data,client,calls,sender,receiver};
}
test('TON follows exact sender and recipient messages beyond the most recent 100 transactions',async()=>{
 const f=tonFixture();f.data.alice=[...Array.from({length:201},(_,i)=>tx(1300-i,message('noise-a-'+i,{type:'external-in'}))),f.sender];
 f.data.bob=[...Array.from({length:150},(_,i)=>tx(2300-i,message('noise-b-'+i,{type:'internal'}))),f.receiver];
 const r=await f.a.receipt(f.p);assert.equal(r.state,'confirmed');assert.equal(r.sender_tx,f.sender.hash().toString('hex'));assert.equal(r.receiver_tx,f.receiver.hash().toString('hex'));
 assert.equal(f.calls.length,5);assert.ok(f.calls.every(c=>c.archival===true&&c.inclusive===true));assert.equal(f.calls[1].lt,'1201');assert.equal(f.calls[1].hash,hash(1201).toString('base64'));
});
test('TON cannot refund an empty history or an expired local clock',async()=>{
 const f=tonFixture();f.data.alice=[];const r=await f.a.receipt(f.p);assert.equal(r.state,'expired');assert.equal(r.final,false);assert.equal(r.reason,'transaction_not_found');
});
test('TON bounds repeated pagination cursors and never classifies a truncated search as final',async()=>{
 const f=tonFixture();const rows=Array.from({length:100},(_,i)=>tx(1000-i,message('noise-'+i,{type:'external-in'})));
 let calls=0;f.client.getTransactions=async()=>{calls++;return rows;};const r=await f.a.receipt(f.p);assert.equal(calls,2);assert.equal(r.final,false);assert.equal(r.reason,'history_cursor_stalled');
});
test('TON final failed requires exact failed intent, no outgoing value, and consumed seqno',async()=>{
 const f=tonFixture();f.sender.description={aborted:true};f.sender.outMessages=new Map();
 let r=await f.a.receipt(f.p);assert.equal(r.final,true);assert.equal(r.state,'failed');assert.equal(r.txn,f.p.txn);
 f.data.seqno=7;r=await f.a.receipt(f.p);assert.equal(r.final,false);assert.equal(r.reason,'failed_replay_not_excluded');
 f.data.seqno=8;f.sender.outMessages.set(0,message('other',{type:'internal'}));r=await f.a.receipt(f.p);assert.equal(r.final,false);assert.equal(r.reason,'failed_with_outgoing_messages');
});
test('TON ignored send error with no output is final only when seqno is consumed',async()=>{
 const f=tonFixture();f.sender.description.actionPhase.skippedActions=1;f.sender.outMessages=new Map();assert.equal((await f.a.receipt(f.p)).final,true);
 f.client.runMethod=async()=>{throw Error('RPC unavailable');};assert.equal((await f.a.receipt(f.p)).final,false);
});
test('TON missing recipient is pending, never inferred from the sender success',async()=>{
 const f=tonFixture();f.data.bob=[];const r=await f.a.receipt(f.p);assert.equal(r.state,'pending');assert.equal(r.final,false);
});
test('Solana missing historical signature stays non-final after blockhash expiry',async()=>{
 const c={getGenesisHash:async()=> '5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d',getSignatureStatuses:async()=>({value:[null]}),getBlockHeight:async()=>150};
 const r=await require('../solana')({}, {},c,{}).receipt({txn:'signature',prepared:{last_valid_block_height:100}});assert.equal(r.state,'expired');assert.equal(r.final,false);assert.equal(r.finalized_block_height,150);
});
function tronFixture(){
 const data={info:{id:'hash',blockNumber:100,receipt:{result:'OUT_OF_ENERGY'}},solid:{id:'hash',blockNumber:100,receipt:{result:'SUCCESS'}},tx:{txID:'hash',ret:[{contractRet:'SUCCESS'}],raw_data:{contract:[{parameter:{value:{owner_address:'alice',to_address:'bob',amount:2000000}}}]}}};
 const w={address:{toHex:a=>a},trx:{getTransactionInfo:async()=>data.info,getCurrentBlock:async()=>({block_header:{raw_data:{number:140}}})},solidityNode:{request:async endpoint=>endpoint.endsWith('gettransactionbyid')?data.tx:data.solid}};
 return {a:require('../tron')(w),data,p:{txn:'hash',sender:'alice',destination:'bob',amount:'2',confirmations:20,prepared:{decimals:6,expires_at:1}}};
}
test('TRON ignores a stale full-node failure when exact irreversible receipt proves success',async()=>{const f=tronFixture();assert.equal((await f.a.receipt(f.p)).state,'confirmed');});
test('TRON final failure is based on the exact solid transaction and execution result',async()=>{
 const f=tronFixture();f.data.solid.receipt.result='OUT_OF_ENERGY';f.data.tx.ret[0].contractRet='OUT_OF_ENERGY';assert.equal((await f.a.receipt(f.p)).final,true);
 f.data.tx.txID='other';assert.equal((await f.a.receipt(f.p)).state,'pending');f.data.tx.txID='hash';f.data.solid.blockNumber=101;assert.equal((await f.a.receipt(f.p)).state,'pending');
});
test('TRON cannot finalize an expired transaction absent from history',async()=>{const f=tronFixture();f.data.info={};const r=await f.a.receipt(f.p);assert.equal(r.state,'expired');assert.equal(r.final,false);});
