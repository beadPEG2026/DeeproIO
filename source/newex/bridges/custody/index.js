'use strict';
const crypto=require('crypto'),path=require('path');const {createRequire}=require('module');const {digest}=require('./units');
exports.install=(app,chain)=>{
  const local=createRequire(path.join(__dirname,'..',chain==='xlayer'?'ethereum':chain,'app.js'));
  let adapter;
  if(['ethereum','bnb','polygon','xlayer'].includes(chain))adapter=require('./evm')((chain==='xlayer'?require('../xlayer/bridge/methods'):local('./bridge/methods')).web3,chain);
  else if(chain==='tron')adapter=require('./tron')(local('./bridge/methods').web3);
  else if(chain==='solana'){let bs=local('bs58');adapter=require('./solana')(local('@solana/web3.js'),local('@solana/spl-token'),local('./bridge/methods').connection,bs.default||bs);}
  else if(chain==='ton')adapter=require('./ton')(local('@ton/ton'),local('@ton/crypto'),new(local('@ton/ton').TonClient)(global.ton));
  if(!adapter)return;
  app.use('/custody',local('express').json({limit:'128kb',verify:(req,res,b)=>{req.custodyRaw=b.toString();}}));
  app.post('/custody/:action',async(req,res)=>{
    try {
      const action=req.params.action;if(!['balance','validate','estimate','prepare','broadcast','receipt'].includes(action))throw Error('CUSTODY_ACTION_INVALID');
      const stamp=req.get('X-Custody-Time')||'',sig=req.get('X-Custody-Signature')||'';
      const expected=crypto.createHmac('sha256',process.env.APP_KEY||'').update(stamp+'.'+action+'.'+req.custodyRaw).digest('hex');
      if(!process.env.APP_KEY || !/^\d+$/.test(stamp) || Math.abs(Date.now()/1000-Number(stamp))>60 || !/^[a-f0-9]{64}$/.test(sig)||!crypto.timingSafeEqual(Buffer.from(sig),Buffer.from(expected)))return res.status(403).json({success:false,code:'CUSTODY_AUTH_REQUIRED'});
      const p=req.body;
      if(['prepare','broadcast','receipt'].includes(action)){
        const q=await global.database.query('SELECT * FROM custody_transfers WHERE id=$1',[p.id]);const row=q.rows[0];
        if(!row||row.chain!==chain||row.sender!==p.sender||row.destination!==p.destination||(row.contract||null)!==(p.contract||null))throw Error('CUSTODY_INTENT_MISMATCH');
        const {units}=require('./units');if(units(row.sent_amount||row.amount,18)!==units(p.amount,18))throw Error('CUSTODY_AMOUNT_MISMATCH');
        if(action!=='receipt'){
          const n=(await global.database.query('SELECT enabled,max_fee,native_max_fee,confirmations FROM custody_networks WHERE chain=$1',[chain])).rows[0];if(!n?.enabled)throw Error('CUSTODY_NETWORK_DISABLED');if(units(row.max_fee,18)>units(!row.contract&&n.native_max_fee!=null?n.native_max_fee:n.max_fee,18))throw Error('CUSTODY_FEE_LIMIT');
        }
        if(action==='prepare'&&row.status!=='approved')throw Error('CUSTODY_STATE_CONFLICT');
        if(action==='broadcast'){
          if(!['confirming','prepared'].includes(row.status)||row.txn!==p.txn||row.prepared?.raw_digest!==digest(p.raw))throw Error('CUSTODY_PAYLOAD_MISMATCH');
          if(process.env.BRIDGE_BROADCAST_ENABLED!=='true'&&process.env.CUSTODY_ONLY!=='true')throw Error('CUSTODY_BROADCAST_DISABLED');
        }
        if(action==='receipt'&&(row.txn!==p.txn||!['confirming','review'].includes(row.status)))throw Error('CUSTODY_STATE_CONFLICT');
        p.max_fee=String(row.max_fee);p.confirmations=row.confirmations;p.sweep=row.purpose==='sweep';if(action!=='prepare')p.prepared=row.prepared;
      }
      if((action==='prepare'||(action==='validate'&&Object.prototype.hasOwnProperty.call(p,'private_key'))) && !String(p.private_key||'').trim())throw Error('CUSTODY_KEY_MISSING');
      const result=await adapter[action](p);if(action==='prepare')result.raw_digest=digest(result.raw);
      if(!res.headersSent&&!res.writableEnded)res.json({success:true,...result});
    }catch(e){const code=/^CUSTODY_[A-Z_0-9]+$/.test(e.message)?e.message:'CUSTODY_RPC_OR_SIGNING_UNAVAILABLE';if(!res.headersSent&&!res.writableEnded)res.status(422).json({success:false,code});}
  });
};
