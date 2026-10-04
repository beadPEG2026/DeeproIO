import test from 'node:test';
import assert from 'node:assert/strict';
import {copyText} from '../../resources/js/Functions/Clipboard.mjs';

test('denied clipboard permission copies inside the open modal and restores focus',async()=>{
 let appended,focused,removed=false,copied;
 const dialog={appendChild(node){appended=node}};
 const active={closest:()=>dialog,selectionStart:2,selectionEnd:4,focus(){focused=this},setSelectionRange(a,b){assert.deepEqual([a,b],[2,4])}};
 const input={style:{},focus(){focused=this},select(){},setSelectionRange(){},remove(){removed=true}};
 const environment={navigator:{clipboard:{writeText:async()=>{throw new Error('denied')}}},document:{activeElement:active,createElement:()=>input,execCommand(command){assert.equal(command,'copy');assert.equal(appended,input);assert.equal(focused,input);copied=input.value;return true}}};
 await copyText('000148',null,environment);
 assert.equal(copied,'000148');assert.equal(focused,active);assert.equal(removed,true);
 environment.document.execCommand=()=>false;
 await assert.rejects(copyText('receiver-code',null,environment),/clipboard_unavailable/);
 assert.equal(focused,active);
});

test('native clipboard success does not touch modal selection',async()=>{
 let copied;await copyText('receiver-code',null,{navigator:{clipboard:{writeText:async text=>copied=text}}});assert.equal(copied,'receiver-code');
});

test('support waits for provider readiness and can recover from a blocked script',async()=>{
 const {loadSupportChat,chatEmbedUrl,hideSupportChat}=await import('../../resources/js/Functions/SupportChat.mjs?recovery');
 let script,timer,hidden=0;
 const environment={document:{getElementById:()=>null,createElement:()=>({setAttribute(){},remove(){}}),head:{appendChild(node){script=node}}},Tawk_API:{hideWidget(){hidden++}},setTimeout(fn){timer=fn;return 1},clearTimeout(){}};
 assert.equal(chatEmbedUrl('https://evil.test/chat/abc/def'),null);
 const url='https://tawk.to/chat/abc/def';const first=loadSupportChat(url,environment);
 assert.equal(loadSupportChat(url,environment),first);assert.equal(script.src,'https://embed.tawk.to/abc/def');
 script.onerror();await assert.rejects(first,/chat_unavailable/);
 const second=loadSupportChat(url,environment);timer();await assert.rejects(second,/chat_timeout/);
 // A late provider ready event must allow the next explicit user retry.
 environment.Tawk_API.onLoad();assert.equal(await loadSupportChat(url,environment),environment.Tawk_API);
 hideSupportChat(environment);assert.equal(hidden,2);
});
