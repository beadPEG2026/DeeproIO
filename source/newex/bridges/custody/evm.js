'use strict';
const {units,decimal,positive}=require('./units');
module.exports=(web3,chain)=>{
  const chainId={ethereum:1,bnb:56,polygon:137,xlayer:196}[chain];
  const valid=a=>{if(!web3.utils.isAddress(a)||/^0x0{40}$/i.test(a))throw Error('CUSTODY_INVALID_ADDRESS');return a;};
  // X Layer's OP gas oracle includes the data fee; do not underfund native sweeps.
  const dataFee=async()=>{if(chain!=='xlayer')return 0n;const value=await rpc('eth_call',[{to:'0x420000000000000000000000000000000000000F',data:'0xf1c7a58b'+'200'.padStart(64,'0')},'latest']);if(!/^0x[0-9a-fA-F]{1,64}$/.test(value))throw Error('CUSTODY_FEE_UNAVAILABLE');return BigInt(value);};
  const same=(a,b)=>String(a).toLowerCase()===String(b).toLowerCase();
  const rpc=(method,params)=>new Promise((resolve,reject)=>web3.currentProvider.send({jsonrpc:'2.0',id:Date.now(),method,params},(e,r)=>e||r?.error?reject(Error('CUSTODY_RPC_UNAVAILABLE')):resolve(r.result)));
  const ready=async()=>{if(Number(await web3.eth.getChainId())!==chainId)throw Error('CUSTODY_WRONG_CHAIN');};
  // Web3 1.x's ABI decoder requires a name, including for unnamed return values.
  const abi=[{constant:true,inputs:[],name:'decimals',outputs:[{name:'',type:'uint8'}],type:'function'},{constant:true,inputs:[{name:'owner',type:'address'}],name:'balanceOf',outputs:[{name:'',type:'uint256'}],type:'function'},{constant:false,inputs:[{name:'to',type:'address'},{name:'value',type:'uint256'}],name:'transfer',outputs:[{name:'',type:'bool'}],type:'function'}];
  const token=a=>new web3.eth.Contract(abi,valid(a));
  const scale=async c=>c?Number(await token(c).methods.decimals().call()):18;
  return {
    async validate(p){await ready();valid(p.sender);if(p.destination)valid(p.destination);if(p.contract)await scale(p.contract);if(p.private_key&&!same(web3.eth.accounts.privateKeyToAccount(p.private_key.startsWith('0x')?p.private_key:'0x'+p.private_key).address,p.sender))throw Error('CUSTODY_KEY_MISMATCH');return {valid:true};},
    async balance(p){await ready();valid(p.sender);const d=await scale(p.contract);return {balance:decimal(p.contract?await token(p.contract).methods.balanceOf(p.sender).call():await web3.eth.getBalance(p.sender),d),native_balance:decimal(await web3.eth.getBalance(p.sender),18),decimals:d};},
    async estimate(p){
      await ready();valid(p.sender);valid(p.destination);const d=await scale(p.contract),amount=positive(units(p.amount,d));
      const price=BigInt(await web3.eth.getGasPrice());
      const data=p.contract?token(p.contract).methods.transfer(p.destination,amount.toString()).encodeABI():'0x';
      const estimate=BigInt(await web3.eth.estimateGas({from:p.sender,to:p.contract||p.destination,data,value:p.contract?'0':p.sweep?'1':amount.toString()}));
      const fee=((estimate*120n+99n)/100n)*price+await dataFee();if(fee>units(p.max_fee,18))throw Error('CUSTODY_FEE_LIMIT');
      const b=await this.balance(p),native=units(b.native_balance,18);
      return {...b,fee:decimal(fee,18),native_shortfall:decimal(fee>native?fee-native:0n,18)};
    },
    async prepare(p){
      await this.validate(p);valid(p.destination);const d=await scale(p.contract);let amount=positive(units(p.amount,d));
      const price=BigInt(await web3.eth.getGasPrice());
      const data=p.contract?token(p.contract).methods.transfer(p.destination,amount.toString()).encodeABI():'0x';
      const base={from:p.sender,to:p.contract||p.destination,data,value:p.contract?'0':amount.toString()};
      // Estimate native sweep with a small value to avoid requiring amount+fee before deduction.
      const estimate=BigInt(await web3.eth.estimateGas({...base,value:!p.contract&&p.sweep?'1':base.value}));
      const gas=(estimate*120n+99n)/100n; const fee=gas*price+await dataFee();
      if(fee>units(p.max_fee,18))throw Error('CUSTODY_FEE_LIMIT');
      if(!p.contract&&p.sweep)amount=positive(amount-fee);
      const balance=await this.balance(p);
      if(units(balance.balance,d)<amount||units(balance.native_balance,18)<(p.contract?fee:amount+fee))throw Error('CUSTODY_INSUFFICIENT_GAS_OR_BALANCE');
      const nonce=await web3.eth.getTransactionCount(p.sender,'pending');
      const tx={...base,value:p.contract?'0':amount.toString(),gas:gas.toString(),gasPrice:price.toString(),nonce,chainId};
      const signed=await web3.eth.accounts.signTransaction(tx,p.private_key.startsWith('0x')?p.private_key:'0x'+p.private_key);
      if(chain==='xlayer'&&(signed.rawTransaction.length-2)/2>512)throw Error('CUSTODY_FEE_UNAVAILABLE');
      return {raw:signed.rawTransaction,txn:signed.transactionHash,amount:decimal(amount,d),fee:decimal(fee,18),decimals:d,nonce,chain_id:chainId};
    },
    async broadcast(p){await ready();if(web3.utils.keccak256(p.raw).toLowerCase()!==p.txn.toLowerCase())throw Error('CUSTODY_PAYLOAD_MISMATCH');
      try{const hash=await rpc('eth_sendRawTransaction',[p.raw]);if(!same(hash,p.txn))throw Error('CUSTODY_HASH_MISMATCH');}catch(e){if(!await web3.eth.getTransaction(p.txn))throw e;}
      return {txn:p.txn};
    },
    async receipt(p){
      await ready(); const r=await web3.eth.getTransactionReceipt(p.txn);if(!r)return {state:'pending'};
      const tx=await web3.eth.getTransaction(p.txn);const block=await web3.eth.getBlock(r.blockNumber);
      if(!tx||!block||block.hash!==r.blockHash||tx.blockHash!==r.blockHash)return {state:'pending'};
      const n=Number(await web3.eth.getBlockNumber())-Number(r.blockNumber)+1;if(n<p.confirmations)return {state:'pending'};
      if(chain==='xlayer'){const finalized=await web3.eth.getBlock('finalized');if(!finalized||Number(finalized.number)<Number(r.blockNumber))return {state:'pending'};}
      if(r.status===false||r.status===0||r.status==='0x0')return {state:'failed',final:true,txn:p.txn,confirmations:n,block_hash:r.blockHash};
      if(!same(tx.from,p.sender))throw Error('CUSTODY_RECEIPT_MISMATCH');
      const d=p.prepared.decimals, amount=units(p.amount,d);
      if(p.contract){
        const topic=web3.utils.sha3('Transfer(address,address,uint256)');
        const logs=r.logs.filter(l=>!l.removed&&same(l.address,p.contract)&&l.topics.length===3&&l.topics[0]===topic&&same('0x'+l.topics[1].slice(-40),p.sender)&&same('0x'+l.topics[2].slice(-40),p.destination)&&BigInt(l.data)===amount);
        if(logs.length!==1)throw Error('CUSTODY_RECEIPT_MISMATCH');
      }else if(!same(tx.to,p.destination)||BigInt(tx.value)!==amount)throw Error('CUSTODY_RECEIPT_MISMATCH');
      return {state:'confirmed',txn:p.txn,amount:p.amount,confirmations:n,block_hash:r.blockHash,block_number:r.blockNumber};
    }
  };
};
