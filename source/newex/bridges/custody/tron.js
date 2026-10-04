'use strict';
const {units,decimal,positive}=require('./units');
module.exports=web3=>{
  const valid=a=>{if(!web3.isAddress(a))throw Error('CUSTODY_INVALID_ADDRESS');return a;};
  const hex=a=>web3.address.toHex(a).toLowerCase();
  // Read-only contract calls still require an owner address. Keep it request-local.
  const decimals=async(c,a)=>c?Number((await(await web3.contract().at(valid(c))).decimals().call({from:valid(a)})).toString()):6;
  const tokenBalance=async(c,a)=>(await(await web3.contract().at(c)).balanceOf(a).call({from:valid(a)})).toString();
  const params=()=>web3.trx.getChainParameters();
  const feeParameter=(values,key)=>BigInt(values.find(x=>x.key===key)?.value??(()=>{throw Error('CUSTODY_RESOURCE_PRICE_UNAVAILABLE');})());
  const resourceInteger=value=>{if(value===undefined)return 0n;if(!/^\d+$/.test(String(value))||!Number.isSafeInteger(Number(value)))throw Error('CUSTODY_RESOURCE_ESTIMATE_UNAVAILABLE');return BigInt(value);};
  const remaining=(limit,used)=>{const l=resourceInteger(limit),u=resourceInteger(used);return l>u?l-u:0n;};
  const validateLocal=p=>{valid(p.sender);if(p.destination)valid(p.destination);if(p.private_key&&hex(web3.address.fromPrivateKey(p.private_key))!==hex(p.sender))throw Error('CUSTODY_KEY_MISMATCH');};
  async function quote(p){
    valid(p.sender);valid(p.destination);const d=await decimals(p.contract,p.sender);let amount=positive(units(p.amount,d));
    const cap=units(p.max_fee,6);if(cap<=0n||cap>BigInt(Number.MAX_SAFE_INTEGER))throw Error('CUSTODY_FEE_LIMIT');
    const cp=await params(),bandwidthPrice=feeParameter(cp,'getTransactionFee');let tx,fee,resources={};
    if(p.contract){
      const args=[{type:'address',value:p.destination},{type:'uint256',value:amount.toString()}];
      const sim=await web3.transactionBuilder.triggerConstantContract(p.contract,'transfer(address,uint256)',{},args,p.sender);
      if(!sim.result?.result||!sim.energy_used)throw Error('CUSTODY_RESOURCE_ESTIMATE_UNAVAILABLE');
      const required=(resourceInteger(sim.energy_used)*120n+99n)/100n;
      const account=await web3.trx.getAccountResources(p.sender);
      if(!account||typeof account!=='object'||Array.isArray(account)||account.Error)throw Error('CUSTODY_RESOURCE_ESTIMATE_UNAVAILABLE');
      const available=remaining(account.EnergyLimit,account.EnergyUsed),missing=required>available?required-available:0n;
      const price=feeParameter(cp,'getEnergyFee');
      let built=await web3.transactionBuilder.triggerSmartContract(p.contract,'transfer(address,uint256)',{feeLimit:Number(cap)},args,p.sender);
      if(!built.result?.result||!built.transaction?.raw_data_hex)throw Error('CUSTODY_SIMULATION_FAILED');
      // Reserve paid bandwidth conservatively; free/staked bandwidth may be consumed by another transaction.
      const bytes=BigInt(built.transaction.raw_data_hex.length/2+110),bandwidth=bytes*bandwidthPrice;
      // fee_limit also caps delegated Energy, so never reduce it to the TRX burn shortfall.
      if(cap<=bandwidth||required*price>cap-bandwidth)throw Error('CUSTODY_FEE_LIMIT');
      built=await web3.transactionBuilder.triggerSmartContract(p.contract,'transfer(address,uint256)',{feeLimit:Number(cap-bandwidth)},args,p.sender);
      if(!built.result?.result||!built.transaction)throw Error('CUSTODY_SIMULATION_FAILED');
      tx=built.transaction;fee=missing*price+bandwidth;
      resources={energy_required:required.toString(),energy_available:available.toString(),energy_shortfall:missing.toString(),bandwidth_reserved:bytes.toString(),energy_price_sun:price.toString()};
    }else{
      if(amount>BigInt(Number.MAX_SAFE_INTEGER))throw Error('CUSTODY_PRECISION_EXCEEDED');
      tx=await web3.transactionBuilder.sendTrx(p.destination,Number(amount),p.sender);
      fee=BigInt(tx.raw_data_hex.length/2+110)*bandwidthPrice;
      const recipient=await web3.trx.getAccount(p.destination);
      if(!recipient.address)fee+=feeParameter(cp,'getCreateNewAccountFeeInSystemContract')+feeParameter(cp,'getCreateAccountFee');
      if(p.sweep){amount=positive(amount-fee);tx=await web3.transactionBuilder.sendTrx(p.destination,Number(amount),p.sender);}
    }
    if(fee>cap)throw Error('CUSTODY_FEE_LIMIT');return {tx,fee,amount,decimals:d,resources};
  }
  return {
    async validate(p){validateLocal(p);if(p.contract)await decimals(p.contract,p.sender);return {valid:true};},
    async balance(p,verifiedDecimals=null){valid(p.sender);const d=verifiedDecimals??await decimals(p.contract,p.sender);const native=await web3.trx.getBalance(p.sender);return {balance:decimal(p.contract?await tokenBalance(p.contract,p.sender):native,d),native_balance:decimal(native,6),decimals:d};},
    // Quotes are read-only and never require a private key. Refresh again immediately before signing.
    async estimate(p){
      const q=await quote(p);const b=await this.balance(p,q.decimals);
      return {...q.resources,fee:decimal(q.fee,6),native_balance:b.native_balance,balance:b.balance,decimals:q.decimals,
        native_shortfall:decimal(q.fee>units(b.native_balance,6)?q.fee-units(b.native_balance,6):0n,6)};
    },
    async prepare(p){
      validateLocal(p);const q=await quote(p);const {tx,fee,amount,decimals:d}=q;
      const balance=await this.balance(p,d);
      if(units(balance.balance,d)<amount||units(balance.native_balance,6)<(p.contract?fee:amount+fee))throw Error('CUSTODY_INSUFFICIENT_GAS_OR_BALANCE');
      const signed=await web3.trx.sign(tx,p.private_key);
      return {raw:signed,txn:signed.txID,amount:decimal(amount,d),fee:decimal(fee,6),decimals:d,resources:q.resources,expires_at:Math.floor(signed.raw_data.expiration/1000)};
    },
    async broadcast(p){if(p.raw.txID!==p.txn)throw Error('CUSTODY_PAYLOAD_MISMATCH');const r=await web3.trx.sendRawTransaction(p.raw);if(!r.result&&r.code!=='DUP_TRANSACTION_ERROR')throw Error('CUSTODY_BROADCAST_UNCERTAIN');return {txn:p.txn};},
    async receipt(p){
      let r;try{r=await web3.trx.getTransactionInfo(p.txn);}catch(e){return {state:'pending'};}
      if(!r?.id){return {state:p.prepared.expires_at&&Date.now()/1000>p.prepared.expires_at+180?'expired':'pending',final:false,txn:p.txn,reason:'transaction_history_absence_not_proven'};}
      if(r.id!==p.txn)throw Error('CUSTODY_RECEIPT_MISMATCH');
      const latest=await web3.trx.getCurrentBlock();const count=latest.block_header.raw_data.number-r.blockNumber+1;
      if(count<p.confirmations)return {state:'pending'};
      const solid=await web3.solidityNode.request('walletsolidity/gettransactioninfobyid',{value:p.txn},'post');if(solid?.id!==p.txn||solid.blockNumber!==r.blockNumber)return {state:'pending'};
      const tx=await web3.solidityNode.request('walletsolidity/gettransactionbyid',{value:p.txn},'post');
      if(tx?.txID!==p.txn||!tx.ret?.[0]?.contractRet)return {state:'pending'};
      // Receipt, execution result and transfer logs must agree on the irreversible node.
      r=solid;
      if(tx.ret[0].contractRet!=='SUCCESS'||(r.receipt?.result&&r.receipt.result!=='SUCCESS'))return {state:'failed',final:true,txn:p.txn,confirmations:count,block_number:r.blockNumber};
      const value=tx.raw_data.contract[0]?.parameter?.value;const amount=units(p.amount,p.prepared.decimals);
      if(!value||value.owner_address.toLowerCase()!==hex(p.sender))throw Error('CUSTODY_RECEIPT_MISMATCH');
      if(p.contract){const topic='ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';const logs=(r.log||[]).filter(l=>l.address.toLowerCase()===hex(p.contract).slice(2)&&l.topics?.length===3&&l.topics[0]===topic&&l.topics[1].slice(-40).toLowerCase()===hex(p.sender).slice(2)&&l.topics[2].slice(-40).toLowerCase()===hex(p.destination).slice(2)&&BigInt('0x'+l.data)===amount);if(logs.length!==1)throw Error('CUSTODY_RECEIPT_MISMATCH');}
      else if(value.to_address.toLowerCase()!==hex(p.destination)||BigInt(value.amount)!==amount)throw Error('CUSTODY_RECEIPT_MISMATCH');
      return {state:'confirmed',txn:p.txn,amount:p.amount,confirmations:count,block_number:r.blockNumber};
    }
  };
};
