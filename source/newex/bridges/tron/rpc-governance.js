'use strict';
const {createHash}=require('node:crypto');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

function budget(pool,key,intervalMs=1000) {
  const fingerprint=createHash('sha256').update(key).digest('hex');
  return {
    fingerprint,
    async acquire() {
      const deadline=Date.now()+5000;
      do {
        const {rows:[r]}=await pool.query('SELECT * FROM deepro_rpc_reserve($1,$2,$3)',[fingerprint,Math.max(100,intervalMs),'node']);
        if(r.admitted){
          if(r.wait_ms>0)await sleep(r.wait_ms);
          const {rows:[state]}=await pool.query('SELECT blocked_until > clock_timestamp() AS blocked FROM rpc_provider_budgets WHERE key_hash=$1',[fingerprint]);
          if(state?.blocked)throw Error('TRONGRID_SHARED_COOLDOWN');
          return;
        }
        if(Date.now()+r.wait_ms>deadline)throw Error('TRONGRID_SHARED_COOLDOWN');
        await sleep(Math.max(1,r.wait_ms)+Math.floor(Math.random()*30));
      } while(Date.now()<deadline);
      throw Error('TRONGRID_SHARED_BUSY');
    },
    async outcome(status,retryAfter) {
      const seconds=Number.isFinite(Number(retryAfter))?Math.ceil(Number(retryAfter)):Math.ceil((Date.parse(retryAfter)-Date.now())/1000);
      await pool.query('SELECT deepro_rpc_outcome($1,$2,$3)',[fingerprint,status,Math.min(2147480000,Math.max(0,seconds||0))]);
    }
  };
}

function wrapProviders(providers,governor) {
  const cache=new Map(),pending=new Map();
  for(const provider of new Set(providers)) {
    const original=provider.request.bind(provider);
    provider.request=async function(path,payload={},method='get') {
      const route=String(path).replace(/^\//,'');
      const ttl=/^(wallet|walletsolidity)\/getnowblock$/.test(route)?3000:route==='wallet/getchainparameters'?60000:0;
      const key=JSON.stringify([route,payload,method]);
      if(ttl && cache.has(key) && cache.get(key).until>Date.now())return cache.get(key).data;
      if(ttl && pending.has(key))return pending.get(key);
      const execute=async()=>{
        await governor.acquire();
        let data;
        try { data=await original(path,payload,method); }
        catch(e) {
          await governor.outcome(Number(e.response?.status)||0,e.response?.headers?.['retry-after']);
          // Never propagate Axios config (contains the API key) or blindly retry broadcasts.
          throw Error('TRONGRID_HTTP_'+(Number(e.response?.status)||'UNAVAILABLE'));
        }
        const message=String(data?.Error||data?.error||'');
        const limited=Number(data?.statusCode)===429 || /frequency limit|rate limit|quota exceeded|too many requests/i.test(message);
        await governor.outcome(limited?429:200);
        if(limited)throw Error('TRONGRID_HTTP_429');
        if(ttl && ((route.endsWith('getnowblock')&&data?.block_header?.raw_data?.number!=null) || (route==='wallet/getchainparameters'&&Array.isArray(data?.chainParameter)))) {
          cache.set(key,{data,until:Date.now()+ttl});
        }
        return data;
      };
      if(!ttl)return execute();
      const promise=execute().finally(()=>pending.delete(key));pending.set(key,promise);return promise;
    };
  }
}
module.exports={budget,wrapProviders};
