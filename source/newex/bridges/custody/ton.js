'use strict';
const {units,decimal,positive}=require('./units');
module.exports=(ton,crypto,client)=>{
  const same=(a,b)=>ton.Address.parse(a).equals(ton.Address.parse(b));
  // Archival pagination is bounded per poll. Missing/incomplete history is never refund evidence.
  const history=async(address,matches)=>{
    // Explicitly include the cursor so TonClient does not request limit+1 beyond provider caps.
    let cursor;const seen=new Set();
    for(let page=0;page<20;page++){
      const rows=await client.getTransactions(ton.Address.parse(address),{limit:100,archival:true,inclusive:true,...cursor});
      const match=rows.find(matches);if(match)return {tx:match};
      if(!rows.length)return {reason:'transaction_not_found'};
      const last=rows[rows.length-1],next={lt:last.lt.toString(),hash:last.hash().toString('base64')};
      const key=next.lt+':'+next.hash;
      if(seen.has(key))return {reason:'history_cursor_stalled'};
      seen.add(key);cursor=next;
      if(rows.length<100)return {reason:'transaction_not_found'};
    }
    return {reason:'history_page_limit'};
  };
  const wallet=async secret=>{const key=await crypto.mnemonicToPrivateKey(secret.trim().split(/\s+/));return {key,wallet:ton.WalletContractV4.create({workchain:0,publicKey:key.publicKey})};};
  return {
    async validate(p){ton.Address.parse(p.sender);if(p.destination)ton.Address.parse(p.destination);if(p.contract)throw Error('CUSTODY_UNSUPPORTED_ASSET');if(p.private_key&&!same((await wallet(p.private_key)).wallet.address.toString(),p.sender))throw Error('CUSTODY_KEY_MISMATCH');return {valid:true};},
    async balance(p){await this.validate(p);return {balance:decimal(await client.getBalance(ton.Address.parse(p.sender)),9),decimals:9};},
    async prepare(p){
      await this.validate(p);const {key,wallet:w}=await wallet(p.private_key);const opened=client.open(w);const seqno=await opened.getSeqno();
      const expiry=Math.floor(Date.now()/1000)+300;let amount=positive(units(p.amount,9));const cap=units(p.max_fee,9);
      const state=await client.getContractState(w.address);const init=state.state==='active'?undefined:w.init;
      const create=n=>w.createTransfer({seqno,secretKey:key.secretKey,timeout:expiry,sendMode:3,messages:[ton.internal({to:ton.Address.parse(p.destination),value:n,bounce:false})]});
      let body=create(amount);
      const estimate=await client.estimateExternalMessageFee(w.address,{body,initCode:init?.code,initData:init?.data,ignoreSignature:false});
      const f=estimate.source_fees;const fee=(BigInt(f.in_fwd_fee)+BigInt(f.storage_fee)+BigInt(f.gas_fee)+BigInt(f.fwd_fee))*2n;
      if(fee>cap)throw Error('CUSTODY_FEE_LIMIT');if(p.sweep){amount=positive(amount-fee);body=create(amount);}
      if(await client.getBalance(w.address)<amount+fee)throw Error('CUSTODY_INSUFFICIENT_GAS_OR_BALANCE');
      const external=ton.beginCell().store(ton.storeMessage(ton.external({to:w.address,init,body}))).endCell();
      return {raw:external.toBoc().toString('base64'),txn:body.hash().toString('hex'),message_hash:external.hash().toString('hex'),amount:decimal(amount,9),fee:decimal(fee,9),decimals:9,seqno,expires_at:expiry};
    },
    async broadcast(p){const cell=ton.Cell.fromBase64(p.raw);if(cell.hash().toString('hex')!==p.prepared.message_hash)throw Error('CUSTODY_PAYLOAD_MISMATCH');await client.sendFile(Buffer.from(p.raw,'base64'));return {txn:p.txn};},
    async receipt(p){
      const sender=await history(p.sender,t=>t.inMessage?.info?.type==='external-in'&&t.inMessage.body.hash().toString('hex')===p.txn);
      const tx=sender.tx;
      if(!tx)return {state:Date.now()/1000>p.prepared.expires_at+180?'expired':'pending',final:false,txn:p.txn,reason:sender.reason};
      const description=tx.description,allOutputs=[...tx.outMessages.values()];
      const failed=description.aborted || description.computePhase?.type!=='vm' || !description.computePhase.success || !description.actionPhase?.success || description.actionPhase.skippedActions>0;
      if(failed){
        // A failed attempt may still be replayable if the wallet did not consume its seqno.
        // Any emitted message requires its own delivery/recovery proof, never a blanket refund.
        let seqno;
        try{seqno=(await client.runMethod(ton.Address.parse(p.sender),'seqno')).stack.readNumber();}catch(_){/* Keep under review if account state cannot be established. */}
        const consumed=Number.isSafeInteger(seqno)&&Number.isSafeInteger(p.prepared.seqno)&&seqno>p.prepared.seqno;
        return {state:'failed',final:allOutputs.length===0&&consumed,txn:p.txn,sender_tx:tx.hash().toString('hex'),sender_lt:tx.lt.toString(),seqno,
          reason:allOutputs.length?'failed_with_outgoing_messages':!consumed?'failed_replay_not_excluded':'failed_without_transfer_seqno_consumed'};
      }
      const outputs=[...tx.outMessages.values()].filter(m=>m.info.type==='internal'&&same(m.info.dest.toString(),p.destination)&&m.info.value.coins===units(p.amount,9));
      if(outputs.length!==1)throw Error('CUSTODY_RECEIPT_MISMATCH');
      // Follow the outgoing message to the receiver, rather than trusting the sender's latest transaction.
      const msg=ton.beginCell().store(ton.storeMessage(outputs[0])).endCell().hash().toString('hex');
      const received=await history(p.destination,t=>t.inMessage&&ton.beginCell().store(ton.storeMessage(t.inMessage)).endCell().hash().toString('hex')===msg);
      const receipt=received.tx;
      if(!receipt)return {state:'pending',final:false,txn:p.txn,reason:received.reason};
      if(receipt.inMessage.info.bounced)return {state:'failed',final:false,txn:p.txn,reason:'bounce_return_not_proven'};
      // An uninitialized receiving wallet may skip compute while accepting a non-bouncing TON transfer.
      return {state:'confirmed',txn:p.txn,amount:p.amount,confirmations:1,sender_tx:tx.hash().toString('hex'),receiver_tx:receipt.hash().toString('hex')};
    }
  };
};
