'use strict';
const path=require('path'),{createRequire}=require('module');
const local=createRequire(path.join(__dirname,'../ethereum/app.js'));
// database.js resolves its Pool from cwd; reuse the existing pinned deployment dependencies.
process.chdir(path.join(__dirname,'../ethereum'));
require('../env-loader');
require('fs').writeFileSync('/tmp/deepro-xlayer-bridge.pid',String(process.pid),{mode:0o600});
global.port=Number(process.env.APP_XLAYER_PORT||18008);
global.database_credentials={host:process.env.DB_HOST,user:process.env.DB_USERNAME,password:process.env.DB_PASSWORD,database:process.env.DB_DATABASE,port:Number(process.env.DB_PORT),ssl:process.env.DB_SSL==='true'?{rejectUnauthorized:process.env.DB_SSL_VERIFY!=='false'}:false};
require('../database').connect();
const {web3}=require('./bridge/methods');
const app=local('express')();
const probe=async()=>{if(Number(await web3.eth.getChainId())!==196)throw Error('WRONG_CHAIN');return web3.eth.getBlockNumber();};
// No legacy unauthenticated transaction endpoint is installed for a new chain.
require('../runtime').start(app,'xlayer',probe,app=>{
  app.get('/wallet/create',(req,res)=>{const a=web3.eth.accounts.create();res.json({address:a.address,private_key:a.privateKey});});
});
