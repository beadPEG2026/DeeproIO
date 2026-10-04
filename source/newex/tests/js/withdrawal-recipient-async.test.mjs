import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
import {recipientAddress} from '../../resources/js/Functions/RecipientAddress.js';
import * as withdrawalFlow from '../../resources/js/Functions/WithdrawalFlow.mjs';
import * as networkIdentity from '../../resources/js/Functions/NetworkIdentity.mjs';
import {walletUiCopy} from '../../resources/js/Functions/WalletUiCopy.mjs';

const require=createRequire(import.meta.url);
const {transformSync}=require('@babel/core');
const source=readFileSync(new URL('../../resources/js/Pages/Wallet/Withdraw/WithdrawCrypto.vue',import.meta.url),'utf8');
// Exercise the actual component methods without mounting unrelated page chrome.
const code=transformSync(source.slice(source.indexOf('<script>')+8,source.lastIndexOf('</script>')),{babelrc:false,configFile:false,plugins:[require.resolve('@babel/plugin-transform-modules-commonjs')]}).code;
const qrSource=readFileSync(new URL('../../resources/js/Functions/QrImage.js',import.meta.url),'utf8');
const qrCode=transformSync(qrSource,{babelrc:false,configFile:false,plugins:[require.resolve('@babel/plugin-transform-modules-commonjs')]}).code;
const oldAddress='0x'+'1'.repeat(40), newAddress='0x'+'2'.repeat(40);
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
const flush=()=>new Promise(resolve=>setImmediate(resolve));

function component({clipboard=()=>Promise.resolve(newAddress),networkRequest,networkRequests,scannedDraft=null}={}) {
 const images=[],revoked=[],focused=[],exports={},qrExports={},navigationListeners=new Map();
 const browserWindow={scrollTo(){},location:{href:'https://fixture.invalid/wallets/withdraw/crypto/USDT'},history:{state:null,replaceState(state,unused,url){this.state=state;browserWindow.location.href=new URL(url,browserWindow.location.href).href}}};
 const sandbox={exports,window:browserWindow,navigator:{clipboard:{readText:clipboard}},clearInterval,
  URL:Object.assign(class extends URL {},{createObjectURL:()=>`blob:${images.length}`,revokeObjectURL:url=>revoked.push(url)}),
  Image:class {constructor(){this.width=2;this.height=2;images.push(this);}},
  document:{getElementById:id=>({focus:()=>focused.push(id)}),createElement:()=>({getContext:()=>({drawImage(){},getImageData:()=>({data:new Uint8ClampedArray(16),width:2,height:2})})})},
  axios:{CancelToken:{source:()=>({token:{},cancel(){}})},get:()=>(networkRequests?.shift() || networkRequest).promise,isCancel:()=>false},
  require(name){
   if(name.startsWith('{Template}'))return {__esModule:true,default:options=>options};
   if(name==='vuex')return {mapGetters:()=>({})};
   if(name==='@/Functions/RecipientAddress')return {recipientAddress};
   if(name==='@/Functions/WithdrawalFlow.mjs')return withdrawalFlow;
   if(name==='@/Functions/NetworkIdentity.mjs')return networkIdentity;
   if(name==='@/Functions/WalletUiCopy.mjs')return {walletUiCopy};
   if(name==='@/Functions/QrImage')return qrExports;
   if(name==='@/Functions/ScannedRecipient.mjs')return {takeScannedRecipient:()=>scannedDraft};
   if(name==='@inertiajs/vue2')return {router:{on:(name,callback)=>{navigationListeners.set(name,callback);return ()=>navigationListeners.delete(name)}}};
   if(name==='jsqr')return {__esModule:true,default:()=>({data:newAddress})};
   return {};
  }
 };
 vm.runInNewContext(qrCode,{...sandbox,exports:qrExports});
 vm.runInNewContext(code,sandbox);
 const options=exports.default,state=options.data();
 Object.assign(state,{$t:key=>key,$nextTick:fn=>fn(),$toast:{success(){}},route:name=>name,currency:{symbol:'USDT',withdraw_status:true,has_payment_id:false},canShowInternalWithdraw:true,activeNetwork:3,recipientNetwork:3,activeAsset:{symbol:'USDT'},wallet:{},availableBalance:'100',calculatedFee:'9',limit:{status:false}});
 for(const [name,method] of Object.entries(options.methods))state[name]=method.bind(state);
 for(const name of ['isInternalWithdraw','canShowExternalForm','canShowWithdrawForm'])Object.defineProperty(state,name,{get:options.computed[name].bind(state)});
 state.form.address=oldAddress;
 state.form.amount='10';
 state.loadAddressBook=()=>{};
 return {state,options,images,revoked,focused,browserWindow,navigationListeners};
}

test('consumed scan URL stays removed after Inertia finish and preserves unrelated history state',()=>{
 const token='a'.repeat(32),initial='/wallets/withdraw/crypto/USDT?recipient_scan='+token+'&type=external#review';
 const {state,browserWindow,navigationListeners}=component({scannedDraft:{address:newAddress,networks:[2,3]}});
 state.$page={url:initial};browserWindow.location.href='https://fixture.invalid'+initial;browserWindow.history.state={url:initial,rememberedState:{unrelated:'keep'},scrollRegions:[{top:100}]};
 state.restoreScannedRecipient();assert.equal(state.form.address,newAddress);assert.equal(state.form.amount,0);assert.equal(state.form.payment_id,null);
 const clean='/wallets/withdraw/crypto/USDT?type=external#review';assert.equal(state.$page.url,clean);assert.equal(new URL(browserWindow.location.href).searchParams.has('recipient_scan'),false);
 // Reproduce a late Inertia scroll restore using its previous URL snapshot.
 browserWindow.history.replaceState({...browserWindow.history.state,url:initial},'',initial);
 navigationListeners.get('finish')({detail:{visit:{completed:true}}});
 assert.equal(browserWindow.location.href,'https://fixture.invalid'+clean);assert.equal(browserWindow.history.state.url,clean);
 assert.equal(browserWindow.history.state.rememberedState.unrelated,'keep');assert.equal(browserWindow.history.state.scrollRegions[0].top,100);assert.equal(navigationListeners.size,0);
});

test('scan finish cleanup never removes a later token or another asset route',()=>{
 for(const destination of ['/wallets/withdraw/crypto/USDT?recipient_scan='+'b'.repeat(32),'/wallets/withdraw/crypto/ETH?recipient_scan='+'a'.repeat(32)]){
  const initial='/wallets/withdraw/crypto/USDT?recipient_scan='+'a'.repeat(32),{state,browserWindow,navigationListeners}=component();
  state.$page={url:initial};browserWindow.location.href='https://fixture.invalid'+initial;state.restoreScannedRecipient();
  browserWindow.history.replaceState({url:destination},'',destination);state.$page.url=destination;
  navigationListeners.get('finish')({detail:{visit:{completed:true}}});
  assert.equal(browserWindow.location.href,'https://fixture.invalid'+destination);assert.equal(state.$page.url,destination);assert.equal(navigationListeners.size,0);
 }
});

test('scan navigation observer waits for completion and is removed when the page is destroyed',()=>{
 const {state,options,browserWindow,navigationListeners}=component();browserWindow.location.href+='?recipient_scan='+'a'.repeat(32);state.$page={url:browserWindow.location.href};state.restoreScannedRecipient();
 navigationListeners.get('finish')({detail:{visit:{completed:false}}});assert.equal(navigationListeners.size,1);
 options.beforeDestroy.call(state);assert.equal(navigationListeners.size,0);assert.equal(state.stopScannedRecipientNavigation,null);
});

test('X Layer XKO display addresses normalize only on X Layer',()=>{
 for(const id of [24,25])for(const prefix of ['xko','XKO','XkO'])assert.equal(recipientAddress(prefix+'a'.repeat(40),id),'0x'+'a'.repeat(40));
 for(const id of [2,3,5,6,15,16])assert.throws(()=>recipientAddress('xko'+'a'.repeat(40),id));
 for(const value of ['xko'+'a'.repeat(39),'xko'+'a'.repeat(41),'xko'+'z'.repeat(40)])assert.throws(()=>recipientAddress(value,25));
});

test('invalid recipient and QR safety errors are Chinese in a Chinese withdrawal form',()=>{
 const {state}=component();state.$i18n={locale:'zh-cn'};state.form.address='';
 assert.equal(state.validateRecipient(),false);
 assert.equal(state.addressError,'请输入有效的收款地址');
 assert.equal(state.recipientError(new Error('QR code does not match the selected network.')),'二维码地址与所选网络不匹配，请检查后重试。');
 assert.equal(state.recipientError(new Error('unrecognized exception')),'无法导入地址，请手动粘贴收款地址。');
 state.$i18n.locale='zh-tw';
 assert.equal(state.recipientError(new Error('Invalid wallet address')),'請輸入有效的收款地址');
 state.$i18n.locale='en';
 assert.equal(state.recipientError(new Error('Invalid wallet address')),'Invalid wallet address');
});

test('late single-network response preserves internal confirmation and 2FA',async()=>{
 const request=deferred(),{state}=component({networkRequest:request});
 state.form.withdraw_type='internal';state.form.internal_uid='DPABC123';state.form.twofa='123456';state.flowStep=2;
 state.loadNetworks();
 request.resolve({data:{networks:[{id:3,name:'Ethereum',available:true}]}});await flush();
 assert.equal(state.flowStep,2);assert.equal(state.form.twofa,'123456');assert.equal(state.form.internal_uid,'DPABC123');assert.equal(state.activeNetwork,null);
});

test('single-network response still selects an external withdrawal route',async()=>{
 const request=deferred(),{state}=component({networkRequest:request});state.loadNetworks();
 request.resolve({data:{networks:[{id:25,name:'X Layer',available:true}]}});await flush();
 assert.equal(state.activeNetwork,25);assert.equal(state.form.network,25);
});

test('current clipboard import replaces the recipient and clears memo',async()=>{
 const {state}=component();state.form.payment_id='123';await state.pasteRecipient();
 assert.equal(state.form.address,newAddress);assert.equal(state.form.payment_id,null);
});

test('clipboard result cannot cross departure and return to recipient step',async()=>{
 const request=deferred(),{state}=component({clipboard:()=>request.promise});const work=state.pasteRecipient();
 state.advanceWithdrawal();assert.equal(state.flowStep,2);state.goBack();assert.equal(state.flowStep,0);
 request.resolve(newAddress);await work;assert.equal(state.form.address,oldAddress);
});

test('clipboard result cannot cross a network change and return to original network',async()=>{
 const request=deferred(),{state}=component({clipboard:()=>request.promise});const work=state.pasteRecipient();
 state.activeNetwork=6;state.changeNetwork();state.activeNetwork=3;state.changeNetwork();state.form.address=oldAddress;
 request.resolve(newAddress);await work;assert.equal(state.form.address,oldAddress);
});

test('manual recipient edits and newer imports supersede old clipboard results',async()=>{
 const one=deferred(),two=deferred();let calls=0;const {state}=component({clipboard:()=>++calls===1?one.promise:two.promise});
 const first=state.pasteRecipient(),second=state.pasteRecipient();two.resolve('0x'+'3'.repeat(40));await second;one.resolve(newAddress);await first;
 assert.equal(state.form.address,'0x'+'3'.repeat(40));
 const editRequest=deferred(),edited=component({clipboard:()=>editRequest.promise}).state;const pending=edited.pasteRecipient();edited.form.address='0x'+'4'.repeat(40);
 editRequest.resolve(newAddress);await pending;assert.equal(edited.form.address,'0x'+'4'.repeat(40));
});

test('stale clipboard failures do not surface after leaving recipient step',async()=>{
 const request=deferred(),{state}=component({clipboard:()=>request.promise});const work=state.pasteRecipient();state.advanceWithdrawal();
 request.reject(new Error('permission denied'));await work;assert.equal(state.addressError,'');
});

test('QR import cannot change the confirmation recipient and always releases image URL',async()=>{
 const {state,images,revoked}=component();const work=state.scanRecipient({target:{files:[{type:'image/png',size:100}],value:'file'}});
 state.advanceWithdrawal();state.flowStep=2;images[0].onload();await work;
 assert.equal(state.form.address,oldAddress);assert.equal(state.scanning,false);assert.equal(revoked.length,1);
});

test('current QR import applies and selecting saved recipient cancels older imports',async()=>{
 const first=component();const work=first.state.scanRecipient({target:{files:[{type:'image/png',size:100}],value:'file'}});first.images[0].onload();await work;
 assert.equal(first.state.form.address,newAddress);assert.equal(first.state.scanning,false);
 const second=component();const pending=second.state.scanRecipient({target:{files:[{type:'image/png',size:100}],value:'file'}});
 second.state.selectRecipient({address:oldAddress,payment_id:'456'});second.images[0].onload();await pending;
 assert.equal(second.state.form.address,oldAddress);assert.equal(second.state.form.payment_id,'456');
});

test('destroying the withdrawal page invalidates pending imports',async()=>{
 const request=deferred(),{state,options}=component({clipboard:()=>request.promise});const work=state.pasteRecipient();options.beforeDestroy.call(state);
 request.resolve(newAddress);await work;assert.equal(state.form.address,oldAddress);
});

test('live camera address is validated for the current network and then closes',()=>{
 const {state}=component();state.form.payment_id='123';state.openCamera();assert.equal(state.cameraOpen,true);
 state.cameraRecipient(newAddress);assert.equal(state.form.address,newAddress);assert.equal(state.form.payment_id,null);assert.equal(state.cameraOpen,false);
});

test('camera results cannot cross a network change or overwrite recipient on invalid QR',()=>{
 const {state}=component();state.openCamera();state.activeNetwork=6;state.changeNetwork();state.form.address=oldAddress;
 state.cameraRecipient(newAddress);assert.equal(state.form.address,oldAddress);
 state.openCamera();state.cameraRecipient('https://example.invalid/login');assert.equal(state.form.address,oldAddress);assert.ok(state.addressError);assert.equal(state.cameraOpen,false);
});

test('combined details step validates amount before confirmation without clearing the recipient',()=>{
 const {state}=component();state.form.amount='101';state.advanceWithdrawal();assert.equal(state.flowStep,0);assert.equal(state.amountError,'Amount exceeds available balance.');assert.equal(state.form.address,oldAddress);
 state.form.amount='10';state.advanceWithdrawal();assert.equal(state.flowStep,2);state.goBack();assert.equal(state.flowStep,0);assert.equal(state.form.amount,'10');assert.equal(state.form.address,oldAddress);
});

test('a draft can be edited before selecting a network but cannot reach confirmation',()=>{
 const {state}=component();state.activeNetwork=null;state.recipientNetwork=null;state.form.address=newAddress;
 state.editRecipient();assert.equal(state.form.address,newAddress);assert.equal(state.canShowExternalForm,null);
 state.advanceWithdrawal();assert.equal(state.flowStep,0);assert.equal(state.addressError,'Please select network');
});

test('the first network selection retains and validates a manually entered draft',()=>{
 const {state}=component();state.recipientNetwork=null;state.activeNetwork=25;state.form.address='xko'+'a'.repeat(40);
 state.changeNetwork();assert.equal(state.form.address,'0x'+'a'.repeat(40));assert.equal(state.form.network,25);assert.equal(state.addressError,'');
});

test('an invalid draft is preserved with an error when its first network is selected',()=>{
 const {state}=component();state.recipientNetwork=null;state.activeNetwork=6;state.form.address='ethereum:'+oldAddress+'@1';
 state.changeNetwork();assert.equal(state.form.address,'ethereum:'+oldAddress+'@1');assert.equal(state.addressError,'QR code does not match the selected network.');
 state.advanceWithdrawal();assert.equal(state.flowStep,0);
});

test('a real network switch clears the previous address and memo; selecting the same one does not',()=>{
 const {state}=component();state.form.payment_id='777';state.changeNetwork();assert.equal(state.form.address,oldAddress);assert.equal(state.form.payment_id,'777');
 state.activeNetwork=6;state.changeNetwork();assert.equal(state.form.address,null);assert.equal(state.form.payment_id,null);assert.equal(state.recipientNetwork,6);
});

test('a draft typed while loading the first sole network survives the response',async()=>{
 const request=deferred(),{state}=component({networkRequest:request});state.activeNetwork=null;state.recipientNetwork=null;state.form.address=null;state.loadNetworks();state.form.address=newAddress;state.editRecipient();
 request.resolve({data:{networks:[{id:3,name:'Ethereum',available:true}]}});await flush();
 assert.equal(state.activeNetwork,3);assert.equal(state.form.address,newAddress);assert.equal(state.addressError,'');
});

test('a stale network response cannot select a route or end the current loading state',async()=>{
 const old=deferred(),fresh=deferred(),{state}=component({networkRequests:[old,fresh]});state.loadNetworks();state.loadNetworks();
 old.resolve({data:{networks:[{id:6,name:'BSC',available:true}]}});await flush();assert.equal(state.activeNetwork,null);assert.equal(state.networksLoading,true);
 fresh.resolve({data:{networks:[{id:3,name:'Ethereum',available:true}]}});await flush();assert.equal(state.activeNetwork,3);assert.equal(state.networksLoading,false);
});

test('manual address editing invalidates a camera result even if the original draft is restored',()=>{
 const {state}=component();state.openCamera();state.form.address=newAddress;state.editRecipient();state.form.address=oldAddress;state.cameraRecipient(newAddress);
 assert.equal(state.form.address,oldAddress);assert.equal(state.cameraOpen,false);
});

test('scan without a network explains the prerequisite and focuses its selector',()=>{
 const {state,focused}=component();state.activeNetwork=null;state.$i18n={locale:'zh-cn'};state.openCamera();
 assert.equal(state.cameraOpen,false);assert.equal(state.addressError,'请先选择网络，再扫描收款地址。');assert.deepEqual(focused,['withdraw-network']);
});

test('internal receiving code copy is distinct from UID and UMI invitation rules',()=>{
 const {state}=component();state.form.withdraw_type='internal';state.form.internal_uid='';state.$i18n={locale:'zh-cn'};
 assert.equal(state.validateRecipient(),false);assert.equal(state.addressError,'请输入收款人的内部收款码');
 state.form.internal_uid='DPABC123';assert.equal(state.validateRecipient(),true);assert.equal(state.form.internal_uid,'DPABC123');
 assert.match(state.walletCopy('Ask the recipient to copy their internal receiving code from Profile → Internal receiving code. This is not their UID or UMI invitation code.'),/个人中心 → 内部收款码/);
});


test('home scan draft does not auto-select even a sole network and still needs amount confirmation',async()=>{
 const request=deferred(),{state}=component({networkRequest:request});state.activeNetwork=null;state.recipientNetwork=null;state.form.address=newAddress;state.form.amount=0;state.scannedRecipientDraft={address:newAddress,networks:[2,3]};
 state.loadNetworks();request.resolve({data:{networks:[{id:3,name:'Ethereum',available:true}]}});await flush();
 assert.equal(state.activeNetwork,null);assert.equal(state.form.network,null);assert.equal(state.form.address,newAddress);state.advanceWithdrawal();assert.equal(state.flowStep,0);
 state.activeNetwork=3;state.changeNetwork();assert.equal(state.form.address,newAddress);state.advanceWithdrawal();assert.equal(state.flowStep,0);assert.ok(state.amountError);
});
test('explicit chain hints remain binding after handoff and manual edits discard the scan hint',()=>{
 const {state}=component();state.scannedRecipientDraft={address:oldAddress,networks:[2,3]};state.activeNetwork=6;state.recipientNetwork=null;state.changeNetwork();assert.equal(state.addressError,'QR code does not match the selected network.');state.advanceWithdrawal();assert.equal(state.flowStep,0);
 state.form.address=newAddress;state.editRecipient();assert.equal(state.scannedRecipientDraft,null);state.changeNetwork();assert.equal(state.addressError,'');
});
