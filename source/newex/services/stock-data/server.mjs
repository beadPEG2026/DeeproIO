import http from 'node:http';
import {StockMarket,registerStockAssets} from './stocks.mjs';
const market=new StockMarket(async(url,options,timeout=5000)=>{const parsed=new URL(url);if(parsed.protocol!=='https:'||!['www.binance.com','api.binance.com'].includes(parsed.hostname))throw Error('invalid_provider');const r=await fetch(url,{...options,redirect:'error',signal:AbortSignal.timeout(timeout)});if(!r.ok)throw Error('provider_unavailable');return r.json();});
http.createServer(async(req,res)=>{res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
if(req.method==='GET'&&req.url==='/health'){res.end(JSON.stringify({status:'ok'}));return;}
if(req.method!=='POST'||!['/v1/stocks/quotes','/v1/stocks/candles','/v1/stocks/depth','/v1/stocks/inspect','/v1/stocks/trades'].includes(req.url)){res.writeHead(404).end(JSON.stringify({error:'not_found'}));return;}
try{let body='';for await(const chunk of req){body+=chunk;if(body.length>262144){res.writeHead(413).end('{}');return;}}const data=JSON.parse(body||'{}');if(data.assets)registerStockAssets(data.assets);if(data.asset)registerStockAssets([data.asset]);const result=req.url.endsWith('/quotes')?await market.quotes():req.url.endsWith('/depth')?{data:await market.depth(data)}:req.url.endsWith('/inspect')?await market.inspect(data):req.url.endsWith('/trades')?await market.trades(data):await market.candles(data);res.end(JSON.stringify(result));}
catch(e){res.writeHead(502).end(JSON.stringify({error:String(e.message||'stock_data_unavailable')}));}
}).listen(8011,'127.0.0.1');
