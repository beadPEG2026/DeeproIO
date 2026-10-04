require('./eth-config');
const express=require('express');
const app=express();
const {web3}=require('./bridge/methods'); const probe=async()=>{if(!process.env.APP_ETHEREUM_NODE)throw Error('RPC_NOT_CONFIGURED'); const id=await web3.eth.getChainId(); if(Number(id)!==1)throw Error('WRONG_CHAIN'); return web3.eth.getBlockNumber();};
require('../runtime').start(app, 'ethereum', probe, require('./bridge/gateways').gateways);
