import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
import {scannedRecipient,storeScannedRecipient,takeScannedRecipient} from '../../resources/js/Functions/ScannedRecipient.mjs';
import {walletUiCopy} from '../../resources/js/Functions/WalletUiCopy.mjs';
const require=createRequire(import.meta.url),compiler=require('vue-template-compiler');
const evm='0x'+'a'.repeat(40),tron='T'+'A'.repeat(33);
const storage=()=>{const rows=new Map();return {rows,setItem:(k,v)=>rows.set(k,v),getItem:k=>rows.get(k),removeItem:k=>rows.delete(k)}};
const crypto={getRandomValues:buffer=>buffer.fill(9)};
test('scan recognizes explicit chain hints but strips payment amounts, Memo, labels and callbacks',()=>{
 const recipient=scannedRecipient('ethereum:'+evm+'@56?value=100&memo=1&callback=https://evil.test');
 assert.deepEqual(recipient,{address:evm,networks:[5,6],source:'ethereum:'+evm+'@56'});
 assert.deepEqual(scannedRecipient('tron:'+tron+'?amount=300').networks,[7,8]);
 assert.equal(scannedRecipient('xko'+'b'.repeat(40)).address,'0x'+'b'.repeat(40));
});
test('scanner refuses URLs, encoded redirects, unsupported chains and contract execution QR',()=>{
 for(const value of ['https://evil.test/'+evm,'javascript:alert(1)','data:text/html,hello','//evil.test','ethereum:'+evm+'/transfer?address='+evm,'ethereum:'+evm+'@999','bitcoin:javascript:alert(1)','ethereum:%30x'+'a'.repeat(40),'hello','x'.repeat(1025)])assert.throws(()=>scannedRecipient(value),value);
});
test('one-time local handoff keeps recipient out of the URL and preserves explicit chain constraints',()=>{
 const s=storage(),record=scannedRecipient('ethereum:'+evm+'@1?value=9'),token=storeScannedRecipient(record,s,crypto,1000);
 assert.match(token,/^[a-f0-9]{32}$/);assert.equal(token.includes(evm),false);
 assert.equal(takeScannedRecipient('f'.repeat(32),s,1001),null);
 assert.deepEqual(takeScannedRecipient(token,s,1002),{address:evm,networks:[2,3],source:'ethereum:'+evm+'@1'});
 assert.equal(takeScannedRecipient(token,s,1003),null);
});
test('expired/tampered drafts cannot navigate or widen the allowed network',()=>{
 const s=storage(),token=storeScannedRecipient(scannedRecipient(evm),s,crypto,0);
 assert.equal(takeScannedRecipient(token,s,600000),null);
 storeScannedRecipient(scannedRecipient(evm),s,crypto,1000);const key=[...s.rows.keys()][0],row=JSON.parse(s.getItem(key));row.networks=[8];s.setItem(key,JSON.stringify(row));assert.equal(takeScannedRecipient(token,s,1100),null);
 assert.throws(()=>storeScannedRecipient({address:evm,networks:[9]},s,crypto,0));
});
const source=readFileSync(new URL('../../resources/js/Components/HomeHeader.vue',import.meta.url),'utf8');
const {transformSync,parseSync}=require('@babel/core');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1];
// Replace only the bundler's import() boundary, so module latency/failures can be
// controlled while exercising the actual component and its existing epoch guards.
const executable=transformSync(script.replace(/^import .*;\s*$/gm,'').replace('export default {','globalThis.options={'),{
 babelrc:false,configFile:false,plugins:[({types})=>({visitor:{CallExpression(path){if(path.node.callee.type==='Import')path.replaceWith(types.callExpression(types.identifier('loadTestChunk'),path.node.arguments))}}})]
}).code;
function header(extra={}) {
 const context={ActionIcon:{},AssetPicker:{},walletUiCopy,readQrImage:async()=>evm,scannedRecipient,storeScannedRecipient:record=>{context.stored=record;return 'b'.repeat(32)},axios:{CancelToken:{source:()=>({token:1,cancel(){}})},isCancel:()=>false,get:async()=>({data:{data:[]}})},...extra};
 context.chunkRequests=[];
 context.loadTestChunk=path=>{context.chunkRequests.push(path);return extra.loadTestChunk ? extra.loadTestChunk(path) : Promise.resolve(path.includes('CameraQrScanner') ? {default:{name:'CameraQrScanner'}} : {readQrImage:context.readQrImage})};
 vm.runInNewContext(executable,context);
 const instance={...context.options.data(),$t:x=>x,$nextTick:fn=>fn(),$refs:{recipientDialog:{showModal(){},close(){}}},route:(name,symbol)=>'/withdraw/'+symbol,$inertia:{visit:path=>context.visits.push(path)}};context.visits=[];
 for(const [key,value]of Object.entries(context.options.methods))instance[key]=value.bind(instance);
 return {instance,context};
}
test('home scanner requires an explicit available asset selection and never defaults an address to USDT',async()=>{
 const {instance,context}=header();instance.loadAssets=()=>{};instance.openRecipient(evm);
 assert.equal(instance.selectedAsset,null);assert.equal(context.visits.length,0);instance.continueRecipient();assert.equal(context.visits.length,0);
 instance.assets=[{symbol:'ETH'}];instance.selectedAsset='USDT';instance.continueRecipient();assert.equal(context.visits.length,0);
 instance.selectedAsset='ETH';instance.continueRecipient();assert.equal(context.visits[0],'/withdraw/ETH?recipient_scan='+'b'.repeat(32));assert.equal(context.stored.address,evm);
});
test('invalid camera result never performs navigation or stores a payment URI',async()=>{
 const {instance,context}=header();await instance.openScanner();instance.cameraDecoded('https://evil.test/');assert.ok(instance.scanError);assert.equal(context.visits.length,0);assert.equal(context.stored,undefined);
});
test('closing while album decode is pending makes its result inert',async()=>{
 let resolve,started;const decoding=new Promise(r=>started=r),{instance,context}=header({readQrImage:()=>new Promise(r=>{resolve=r;started()})});const work=instance.scanImage({target:{files:[{}],value:'image'}});await decoding;instance.closeScanner();resolve(evm);await work;assert.equal(instance.recipient,null);assert.equal(context.visits.length,0);
});

test('home initialization loads no QR chunk; clicking the camera loads and reuses its component',async()=>{
 const {instance,context}=header();assert.deepEqual(context.chunkRequests,[]);
 const eager=parseSync(script,{babelrc:false,configFile:false}).program.body.filter(node=>node.type==='ImportDeclaration').map(node=>node.source.value);
 assert.equal(eager.some(path=>/CameraQrScanner|QrImage|jsqr/.test(path)),false);
 assert.equal(instance.cameraComponent,null);const loading=instance.openScanner();assert.equal(instance.cameraLoading,true);assert.equal(instance.cameraOpen,false);
 await loading;assert.equal(instance.cameraComponent.name,'CameraQrScanner');assert.equal(instance.cameraOpen,true);assert.equal(instance.cameraLoading,false);assert.deepEqual(context.chunkRequests,['./CameraQrScanner.vue']);
 instance.closeScanner();instance.cameraDecoded(evm);assert.equal(instance.recipient,null);
 await instance.openScanner();assert.equal(context.chunkRequests.length,1);assert.equal(instance.cameraOpen,true);
});

test('camera chunk failure shows recovery state and can retry or choose an album directly',async()=>{
 let fail=true,albumClicks=0;const {instance,context}=header({loadTestChunk:async()=>{if(fail)throw new Error('ChunkLoadError');return{default:{name:'CameraQrScanner'}}}});instance.$i18n={locale:'zh-cn'};instance.$refs.recipientImage={click:()=>albumClicks++};
 await instance.openScanner();assert.equal(instance.cameraOpen,false);assert.equal(instance.cameraLoading,false);assert.equal(instance.cameraLoadFailed,true);assert.match(instance.scanError,/无法使用相机/);
 instance.chooseImage();assert.equal(albumClicks,1);assert.equal(instance.scanError,'');assert.equal(instance.cameraLoadFailed,false);
 fail=false;await instance.openScanner();assert.equal(instance.cameraOpen,true);assert.equal(instance.cameraLoading,false);assert.equal(context.chunkRequests.length,2);
});

test('closing, choosing album or destroying while camera chunk loads prevents a late camera open',async()=>{
 for(const action of ['closeScanner','chooseImage','destroy']){
  let resolve;const {instance,context}=header({loadTestChunk:()=>new Promise(r=>resolve=r)});const work=instance.openScanner();
  if(action==='destroy')context.options.beforeDestroy.call(instance);else instance[action]();
  resolve({default:{name:'CameraQrScanner'}});await work;
  assert.equal(instance.cameraComponent,null);assert.equal(instance.cameraOpen,false);assert.equal(instance.cameraLoading,false);assert.equal(instance.scanError,'');
 }
});

test('superseded camera chunk failures do not overwrite a newer successful opening',async()=>{
 const pending=[];const {instance}=header({loadTestChunk:()=>new Promise((resolve,reject)=>pending.push({resolve,reject}))});
 const old=instance.openScanner();instance.closeScanner();const current=instance.openScanner();pending[1].resolve({default:{name:'CameraQrScanner'}});await current;pending[0].reject(new Error('ChunkLoadError'));await old;
 assert.equal(instance.cameraOpen,true);assert.equal(instance.cameraLoadFailed,false);assert.equal(instance.scanError,'');
});

test('album import waits for its decoder chunk and never decodes after close or destruction',async()=>{
 for(const close of ['closeScanner','destroy']){
  let resolve,decoded=0;const {instance,context}=header({loadTestChunk:()=>new Promise(r=>resolve=r)});
  const work=instance.scanImage({target:{files:[{}],value:'image'}});assert.equal(instance.scanning,true);assert.deepEqual(context.chunkRequests,['@/Functions/QrImage']);
  if(close==='destroy')context.options.beforeDestroy.call(instance);else instance.closeScanner();
  resolve({readQrImage:async()=>{decoded++;return evm}});await work;
  assert.equal(decoded,0);assert.equal(instance.recipient,null);assert.equal(instance.scanning,false);assert.equal(context.visits.length,0);
 }
});

test('newer album selection wins even when its decoder chunk arrives before the old one',async()=>{
 const pending=[];let decoded=0;const {instance}=header({loadTestChunk:()=>new Promise(resolve=>pending.push(resolve))});instance.loadAssets=()=>{};
 const first=instance.scanImage({target:{files:[{id:1}],value:'first'}}),second=instance.scanImage({target:{files:[{id:2}],value:'second'}});
 pending[1]({readQrImage:async file=>{decoded++;assert.equal(file.id,2);return tron}});await second;
 pending[0]({readQrImage:async()=>{decoded++;return evm}});await first;
 assert.equal(decoded,1);assert.equal(instance.recipient.address,tron);assert.equal(instance.selectedAsset,null);assert.equal(instance.scanning,false);
});

test('decoder chunk failure is actionable, stale failure is silent, and empty selection loads nothing',async()=>{
 const {instance,context}=header({loadTestChunk:async()=>{throw new Error('ChunkLoadError')}});
 await instance.scanImage({target:{files:[],value:''}});assert.deepEqual(context.chunkRequests,[]);
 await instance.scanImage({target:{files:[{}],value:'image'}});assert.equal(instance.scanError,'Unable to read this image.');assert.equal(instance.scanning,false);assert.equal(instance.recipient,null);
 let reject;const stale=header({loadTestChunk:()=>new Promise((_,r)=>reject=r)});const work=stale.instance.scanImage({target:{files:[{}],value:'image'}});stale.instance.closeScanner();reject(new Error('ChunkLoadError'));await work;assert.equal(stale.instance.scanError,'');
});
test('asset choice uses actual enabled crypto response and ignores stale response after closing',async()=>{
 let resolve;const {instance}=header({axios:{CancelToken:{source:()=>({token:1,cancel(){}})},isCancel:()=>false,get:()=>new Promise(r=>resolve=r)}});
 instance.recipient=scannedRecipient(evm);const work=instance.loadAssets();resolve({data:{data:[{symbol:'ETH',type:'coin',status:true,withdraw_status:true},{symbol:'BAD',type:'coin',status:true,withdraw_status:false},{symbol:'HK1',type:'coin',status:true,withdraw_status:true,asset_category:'stock'}]}});await work;assert.deepEqual(Array.from(instance.assets,a=>a.symbol),['ETH']);assert.equal(instance.selectedAsset,null);
 const pending=instance.loadAssets();instance.closeRecipient();resolve({data:{data:[{symbol:'NEW',type:'coin',status:true,withdraw_status:true}]}});await pending;assert.deepEqual(Array.from(instance.assets,a=>a.symbol),[]);
});
test('home camera and neutral asset handoff templates compile',()=>assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors,[]));
