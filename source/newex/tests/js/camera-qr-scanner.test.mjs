import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
import {walletUiCopy} from '../../resources/js/Functions/WalletUiCopy.mjs';
const require=createRequire(import.meta.url),{transformSync}=require('@babel/core');
const source=readFileSync(new URL('../../resources/js/Components/CameraQrScanner.vue',import.meta.url),'utf8');
const code=transformSync(source.slice(source.indexOf('<script>')+8,source.lastIndexOf('</script>')),{babelrc:false,configFile:false,plugins:[require.resolve('@babel/plugin-transform-modules-commonjs')]}).code;
const deferred=()=>{let resolve;const promise=new Promise(yes=>{resolve=yes});return {promise,resolve}};
function scanner(getUserMedia,{decoded=null,secure=true}={}) {
 const events=[],timers=new Map(),exports={},video={videoWidth:640,videoHeight:480,readyState:3,srcObject:null,play:async()=>{}};
 const document={body:{style:{}},hidden:false,removeEventListener(){},createElement:()=>({getContext:()=>({drawImage(){},getImageData:()=>({data:new Uint8ClampedArray(16),width:2,height:2})})})};
 const sandbox={exports,window:{isSecureContext:secure},navigator:{mediaDevices:{getUserMedia}},document,setTimeout:fn=>{const id=timers.size+1;timers.set(id,fn);return id},clearTimeout:id=>timers.delete(id),Uint8ClampedArray,require(name){if(name==='jsqr')return {__esModule:true,default:()=>decoded?{data:decoded}:null};if(name==='@/Functions/WalletUiCopy.mjs')return {walletUiCopy};return {}}};
 vm.runInNewContext(code,sandbox);
 const options=exports.default,state=options.data();Object.assign(state,{$refs:{video},$i18n:{locale:'zh-cn'},$t:key=>key,$emit:(...args)=>events.push(args)});
 for(const [key,value] of Object.entries(options.methods))state[key]=value.bind(state);
 return {state,options,events,timers,video,document};
}
const media=()=>{const stopped={count:0};return {stopped,getTracks:()=>[{stop(){stopped.count++}}]}};
test('live video scanning imports decoded QR and immediately stops camera tracks',async()=>{
 const stream=media(),{state,events,timers}=scanner(async()=>stream,{decoded:'bitcoin:bc1qexample'});
 await state.start();assert.equal(stream.stopped.count,1);assert.deepEqual(events,[['decoded','bitcoin:bc1qexample']]);assert.equal(timers.size,0);assert.equal(state.stream,null);
});
test('closing while permission dialog is pending stops any late camera stream',async()=>{
 const request=deferred(),stream=media(),{state,options,events}=scanner(()=>request.promise);
 const pending=state.start();options.beforeDestroy.call(state);request.resolve(stream);await pending;
 assert.equal(stream.stopped.count,1);assert.equal(events.length,0);assert.equal(state.stream,null);
});
test('permission denial explains image fallback in Chinese without creating a scan timer',async()=>{
 const {state,timers}=scanner(async()=>{const e=new Error();e.name='NotAllowedError';throw e});await state.start();
 assert.match(state.error,/请允许使用相机/);assert.equal(state.loading,false);assert.equal(timers.size,0);
});
test('closing an active scanner clears polling and video stream',async()=>{
 const stream=media(),{state,options,timers,video}=scanner(async()=>stream);await state.start();
 assert.equal(timers.size,1);options.beforeDestroy.call(state);assert.equal(timers.size,0);assert.equal(stream.stopped.count,1);assert.equal(video.srcObject,null);
});
test('insecure browser context never opens camera and offers manual alternatives',async()=>{
 let calls=0;const {state}=scanner(async()=>{calls++;return media()},{secure:false});await state.start();
 assert.equal(calls,0);assert.match(state.error,/相册.*粘贴地址/);
});
