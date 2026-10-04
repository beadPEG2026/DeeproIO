require('./solana-config');
const express=require('express');
const app=express();
const {connection}=require('./bridge/methods'); const probe=()=>connection.getBlockHeight();
require('../runtime').start(app, 'solana', probe, require('./bridge/gateways').gateways);
