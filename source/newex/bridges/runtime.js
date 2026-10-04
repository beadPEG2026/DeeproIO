const SAFE = new Set(['/wallet/create','/wallet/balance/bnb','/wallet/balance/bep','/wallet/balance/eth','/wallet/balance/erc','/wallet/balance/trx','/wallet/balance/trc','/wallet/balance/matic','/wallet/balance/matic20','/wallet/balance/sol','/wallet/balance/spl','/wallet/balance/ton','/wallet/validate/ton','/wallet/transactions/ton','/blockchain/latest/block']);
function bounded(fn, ms=10000) { let timer; return Promise.race([Promise.resolve().then(fn),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('TIMEOUT')),ms);})]).finally(()=>clearTimeout(timer)); }
exports.start = (app, chain, probe, gateways) => {
  let state={rpc:false,database:false,checked_at:null};
  let pending;
  const check=()=>pending || (pending=(async()=>{
    const results=await Promise.allSettled([bounded(probe),bounded(()=>global.database.query('SELECT 1'))]);
    state={rpc:results[0].status==='fulfilled', database:results[1].status==='fulfilled', checked_at:new Date().toISOString()};
    for (const [i, name] of [[0,'rpc_error'],[1,'database_error']]) {
      if (results[i].status==='rejected') {
        const code=String(results[i].reason?.code || 'UNAVAILABLE');
        state[name]=/^[A-Z0-9_]{2,40}$/.test(code)?code:'UNAVAILABLE';
      }
    }
    if (state.rpc) state.height=String(results[0].value);
    return state;
  })().finally(()=>{pending=null;}));
  app.disable('x-powered-by');
  app.get('/health',(req,res)=>{res.status(state.rpc&&state.database?200:503).json({service:'deepro-wallet-bridge',chain,port:global.port,...state,configuration_issues:global.configurationIssues||[],broadcast_enabled:process.env.BRIDGE_BROADCAST_ENABLED==='true'||process.env.CUSTODY_ONLY==='true',custody_only:process.env.CUSTODY_ONLY==='true'});});
  app.use(async(req,res,next)=>{
    res.setTimeout(chain==='tron' && ['/custody/prepare','/custody/estimate'].includes(req.path)?60000:20000,()=>{if(!res.headersSent)res.status(504).json({success:false,message:'Wallet service timed out'});});
    if (!SAFE.has(req.path) && !req.path.startsWith('/custody/') && process.env.BRIDGE_BROADCAST_ENABLED !== 'true') return res.status(503).json({success:false,message:'Broadcasting is disabled for this environment'});
    if (process.env.CUSTODY_ONLY === 'true' && !SAFE.has(req.path) && !req.path.startsWith('/custody/')) return res.status(409).json({success:false,message:'Use the verified custody workflow'});
    if(!state.checked_at || Date.now()-Date.parse(state.checked_at)>30000)await check();
    if(!state.database || (!state.rpc && req.path!=='/wallet/create')) return res.status(503).json({success:false,message:'Wallet service is not ready'});
    next();
  });
  require('./custody').install(app,chain);
  gateways(app);
  app.use((err,req,res,next)=>{if(!res.headersSent)res.status(502).json({success:false,message:'Wallet service request failed'});});
  const server=app.listen(global.port, process.env.BRIDGE_BIND_HOST || '127.0.0.1',()=>console.log(`Deepro ${chain} bridge listening on ${global.port}`));
  server.requestTimeout=25000;
  check();
  setInterval(check,30000).unref();
};
