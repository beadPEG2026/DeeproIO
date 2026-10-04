require('./ton-config');
const express=require('express');
const app=express();
const TonWeb=require('tonweb'); const probe=async()=>{const p=new TonWeb.HttpProvider(global.ton.endpoint,{apiKey:global.ton.apiKey}); const r=await p.getMasterchainInfo();if(!r?.last)throw Error('INVALID_NODE_RESPONSE');return r.last.seqno;};
require('../runtime').start(app, 'ton', probe, require('./bridge/gateways').gateways);
