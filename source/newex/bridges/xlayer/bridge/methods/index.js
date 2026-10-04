'use strict';
// Reuse the installed Ethereum dependency set, never its configured provider.
const {createRequire}=require('module');
const local=createRequire(require('path').join(__dirname,'../../../ethereum/app.js'));
const Web3=local('web3');
exports.web3=new Web3(new Web3.providers.HttpProvider(process.env.APP_XLAYER_NODE||'https://rpc.xlayer.tech',{keepAlive:true,timeout:15000}));
