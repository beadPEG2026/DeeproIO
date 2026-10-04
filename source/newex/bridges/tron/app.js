require('./tron-config');
const express=require('express');
const app=express();
const {web3}=require('./bridge/methods'); const probe=async()=>{const b=await web3.trx.getCurrentBlock(); if(!b?.block_header)throw Error('INVALID_NODE_RESPONSE');return b.block_header.raw_data.number;};
app.get('/rpc-status',(req,res)=>res.json(global.tronRpcGovernance||{shared_budget:false}));
require('../runtime').start(app, 'tron', probe, require('./bridge/gateways').gateways);
