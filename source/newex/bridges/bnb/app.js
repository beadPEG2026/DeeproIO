require('./bnb-config');
const express=require('express');
const app=express();
const {web3}=require('./bridge/methods'); const probe=async()=>{if(Number(await web3.eth.getChainId())!==56)throw Error('WRONG_CHAIN');return web3.eth.getBlockNumber();};
require('../runtime').start(app, 'bnb', probe, require('./bridge/gateways').gateways);
