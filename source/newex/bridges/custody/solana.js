'use strict';
const {units,decimal,positive}=require('./units');
module.exports=(lib,token,connection,bs58)=>{
  const pk=a=>new lib.PublicKey(a);const genesis='5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d';
  const ready=async()=>{if(await connection.getGenesisHash()!==genesis)throw Error('CUSTODY_WRONG_CHAIN');};
  const key=s=>lib.Keypair.fromSecretKey(Uint8Array.from(String(s).startsWith('[')?JSON.parse(s):Buffer.from(s,'hex')));
  const account=async(mint,owner)=>token.getAssociatedTokenAddress(pk(mint),pk(owner));
  const scale=async c=>c?(await token.getMint(connection,pk(c),'finalized')).decimals:9;
  return {
    async validate(p){await ready();pk(p.sender);if(p.destination)pk(p.destination);if(p.contract)await scale(p.contract);if(p.private_key&&key(p.private_key).publicKey.toBase58()!==p.sender)throw Error('CUSTODY_KEY_MISMATCH');return {valid:true};},
    async balance(p){await ready();const d=await scale(p.contract);let n=0n;if(p.contract){const a=await account(p.contract,p.sender);if(await connection.getAccountInfo(a,'finalized'))n=BigInt((await connection.getTokenAccountBalance(a,'finalized')).value.amount);}else n=BigInt(await connection.getBalance(pk(p.sender),'finalized'));return {balance:decimal(n,d),native_balance:decimal(await connection.getBalance(pk(p.sender),'finalized'),9),decimals:d};},
    async prepare(p){
      await this.validate(p);const from=key(p.private_key),d=await scale(p.contract);let amount=positive(units(p.amount,d));
      const latest=await connection.getLatestBlockhash('finalized');const tx=new lib.Transaction({feePayer:from.publicKey,blockhash:latest.blockhash,lastValidBlockHeight:latest.lastValidBlockHeight});let rent=0n;
      if(p.contract){const mint=pk(p.contract),source=await account(p.contract,p.sender),dest=await account(p.contract,p.destination);if(!await connection.getAccountInfo(dest,'finalized')){tx.add(token.createAssociatedTokenAccountInstruction(from.publicKey,dest,pk(p.destination),mint));rent=BigInt(await connection.getMinimumBalanceForRentExemption(token.ACCOUNT_SIZE));}tx.add(token.createTransferCheckedInstruction(source,mint,dest,from.publicKey,amount,d));}
      else tx.add(lib.SystemProgram.transfer({fromPubkey:from.publicKey,toPubkey:pk(p.destination),lamports:amount}));
      const quote=await connection.getFeeForMessage(tx.compileMessage(),'confirmed');if(quote.value===null)throw Error('CUSTODY_RESOURCE_ESTIMATE_UNAVAILABLE');const fee=BigInt(quote.value)+rent;
      if(fee>units(p.max_fee,9))throw Error('CUSTODY_FEE_LIMIT');if(!p.contract&&p.sweep){amount=positive(amount-fee);tx.instructions=[lib.SystemProgram.transfer({fromPubkey:from.publicKey,toPubkey:pk(p.destination),lamports:amount})];}
      const b=await this.balance(p);if(units(b.balance,d)<amount||units(b.native_balance,9)<(p.contract?fee:amount+fee))throw Error('CUSTODY_INSUFFICIENT_GAS_OR_BALANCE');
      tx.sign(from);const sim=await connection.simulateTransaction(tx);if(sim.value.err)throw Error('CUSTODY_SIMULATION_FAILED');
      return {raw:tx.serialize().toString('base64'),txn:bs58.encode(tx.signature),amount:decimal(amount,d),fee:decimal(fee,9),decimals:d,recent_blockhash:latest.blockhash,last_valid_block_height:latest.lastValidBlockHeight};
    },
    async broadcast(p){const tx=lib.Transaction.from(Buffer.from(p.raw,'base64'));if(bs58.encode(tx.signature)!==p.txn)throw Error('CUSTODY_PAYLOAD_MISMATCH');const result=await connection.sendRawTransaction(Buffer.from(p.raw,'base64'),{skipPreflight:false,maxRetries:0});if(result!==p.txn)throw Error('CUSTODY_HASH_MISMATCH');return {txn:p.txn};},
    async receipt(p){
      await ready();const status=(await connection.getSignatureStatuses([p.txn],{searchTransactionHistory:true})).value[0];
      if(!status){
        const height=await connection.getBlockHeight('finalized');
        // An invalid blockhash proves it cannot land now, not that it never landed in pruned history.
        return {state:height>p.prepared.last_valid_block_height?'expired':'pending',final:false,txn:p.txn,finalized_block_height:height,reason:'signature_history_absence_not_proven'};
      }
      if(status.confirmationStatus!=='finalized')return {state:'pending'};if(status.err)return {state:'failed',final:true,txn:p.txn};
      const tx=await connection.getParsedTransaction(p.txn,{commitment:'finalized',maxSupportedTransactionVersion:0});if(!tx)return {state:'pending'};
      if(tx.meta?.err)return {state:'failed',final:true,txn:p.txn};
      const all=tx.transaction.message.instructions.concat(...(tx.meta.innerInstructions||[]).map(v=>v.instructions));
      const amount=units(p.amount,p.prepared.decimals);let matches;
      if(p.contract){const source=(await account(p.contract,p.sender)).toBase58(),dest=(await account(p.contract,p.destination)).toBase58();matches=all.filter(i=>i.program==='spl-token'&&i.parsed?.type==='transferChecked'&&i.parsed.info.source===source&&i.parsed.info.destination===dest&&i.parsed.info.authority===p.sender&&i.parsed.info.mint===p.contract&&BigInt(i.parsed.info.tokenAmount.amount)===amount);}
      else matches=all.filter(i=>i.program==='system'&&i.parsed?.type==='transfer'&&i.parsed.info.source===p.sender&&i.parsed.info.destination===p.destination&&BigInt(i.parsed.info.lamports)===amount);
      if(matches.length!==1)throw Error('CUSTODY_RECEIPT_MISMATCH');
      return {state:'confirmed',txn:p.txn,amount:p.amount,confirmations:1,slot:tx.slot,finality:'finalized'};
    }
  };
};
