import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import {formatAccountUid} from '../../resources/js/Functions/AccountUid.mjs';
import * as functions from '../../resources/js/Functions/DisplayPreferences.mjs';
const require=createRequire(import.meta.url);
const compiler=require('vue-template-compiler');
const read=path=>readFileSync(new URL('../../'+path,import.meta.url),'utf8');
const hubSource=read('resources/js/Components/SiteHub.vue');
const chinese=JSON.parse(read('resources/lang/zh-cn.json'));

function storage(initial={}) {
    const rows=new Map(Object.entries(initial));
    return {fail:false,getItem:key=>rows.get(key)??null,setItem(key,value){if(this.fail)throw new Error('storage denied');rows.set(key,value)}};
}
function preferenceRuntime(localStorage=storage()) {
    const listeners=new Map(),events=[];
    const window={addEventListener(name,fn){if(!listeners.has(name))listeners.set(name,[]);listeners.get(name).push(fn)},dispatchEvent(event){events.push(event);for(const fn of listeners.get(event.type)||[])fn(event)}};
    const context={...functions,Vue:{observable:value=>value},localStorage,window,document:{documentElement:{dataset:{}}},CustomEvent:class {constructor(type,options){this.type=type;this.detail=options.detail}}};
    vm.runInNewContext(read('resources/js/Mixins/DisplayPreferences.js').replace(/^import .*;\s*$/gm,'').replace('export default {','globalThis.mixin = {'),context);
    return {...context,listeners,events,state:context.mixin.data().displayPreferences,save:context.mixin.methods.saveDisplayPreferences};
}
function hub({runtime=preferenceRuntime(),user={id:148,referral_code:'DP-Original-Code'},clipboard=async()=>{}}={}) {
    const context={copyText:clipboard,DisplayPreferences:runtime.mixin,ActionIcon:{},ThemeMode:{},LanguageSwitcher:{},BottomMenu:{},DiscoveryPanel:{},BrandCaption:{},formatAccountUid,contentText:x=>x,navigator:{clipboard:{writeText:clipboard}},setTimeout:()=>1,clearTimeout(){}};
    vm.runInNewContext(hubSource.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\s*$/gm,'').replace('export default {','globalThis.component = {'),context);
    const component=context.component,focused=[],emitted=[];
    const instance={...component.data.call({initial:'profile'}),...runtime.mixin.data(),$page:{props:{user,displayCurrencies:['USDT','USD','CNY','JPY','HKD','EUR'].map(symbol=>({symbol,rate:1}))}},$t:key=>chinese[key]||key,$i18n:{locale:'zh-cn'},route:name=>'/'+name,$nextTick:fn=>fn(),$emit:event=>emitted.push(event),$refs:{currencyPreference:{focus:()=>focused.push('currency')},colorsPreference:{focus:()=>focused.push('colors')},recipientCodeLink:{focus:()=>focused.push('recipient-link')},recipientCopy:{focus:()=>focused.push('recipient-copy')},preferenceChoices:{querySelector:()=>({focus:()=>focused.push('selected-choice')})}}};
    for(const definition of [runtime.mixin,component]) {
        for(const [key,fn] of Object.entries(definition.methods||{}))instance[key]=fn.bind(instance);
        for(const [key,fn] of Object.entries(definition.computed||{}))Object.defineProperty(instance,key,{get:()=>fn.call(instance),configurable:true});
    }
    return {instance,focused,emitted,runtime};
}

test('UID is a display-only padded string without truncation or precision loss',()=>{
    for(const [input,expected] of [[1,'000001'],[148,'000148'],['000148','000148'],[999999,'999999'],[1000000,'1000000'],['9223372036854775807','9223372036854775807'],[148n,'000148']])assert.equal(formatAccountUid(input),expected);
    for(const input of [null,undefined,0,'0',-1,'-1','1.2','1e3',Number.MAX_SAFE_INTEGER+1,{},''])assert.equal(formatAccountUid(input),'—');
});

test('account UID copy and internal recipient copy retain their separate original identities',async()=>{
    const copied=[],user={id:148,referral_code:'DP-Original-Code'};
    const {instance,focused}=hub({user,clipboard:async value=>copied.push(value)});
    assert.equal(instance.accountUid,'000148');await instance.copyUid();
    instance.openRecipientCode();assert.equal(instance.section,'recipient-code');assert.equal(focused.at(-1),'recipient-copy');
    await instance.copyRecipientCode();assert.deepEqual(copied,['000148','DP-Original-Code']);
    instance.back();assert.equal(instance.section,'profile');assert.equal(focused.at(-1),'recipient-link');
    assert.deepEqual(user,{id:148,referral_code:'DP-Original-Code'});
});

test('clipboard failure is reported and absent account data is never invented',async()=>{
    const {instance}=hub({clipboard:async()=>{throw new Error('denied')}});
    await instance.copyUid();assert.match(instance.message,/长按复制/);assert.equal(instance.manualCopy,'000148');
    const guest=hub({user:null,clipboard:async()=>{throw new Error('should not be called')}}).instance;
    assert.equal(guest.accountUid,'—');assert.equal(guest.internalRecipientCode,'');await guest.copyUid();await guest.copyRecipientCode();assert.equal(guest.message,'');
});

test('each preference saves immediately, returns to its row, and survives a fresh runtime',()=>{
    const local=storage(),runtime=preferenceRuntime(local),{instance,focused}=hub({runtime});
    instance.openPreference('currency');assert.equal(instance.section,'preference-currency');assert.equal(focused.at(-1),'selected-choice');
    instance.choosePreference('CNY');assert.equal(instance.section,'preferences');assert.equal(focused.at(-1),'currency');assert.equal(instance.displayPreferences.currency,'CNY');
    instance.openPreference('colors');instance.choosePreference('red-up');assert.equal(instance.marketColorsLabel,'红涨绿跌');assert.equal(focused.at(-1),'colors');
    assert.equal(runtime.document.documentElement.dataset.marketColors,'red-up');assert.equal(runtime.events.length,2);
    assert.deepEqual({...preferenceRuntime(local).state},{currency:'CNY',colors:'red-up'});
    assert.deepEqual({...runtime.events.at(-1).detail},{currency:'CNY',colors:'red-up'});
});

test('blocked storage keeps original selection and chart DOM colors while showing a retry message',()=>{
    const local=storage(),runtime=preferenceRuntime(local),{instance}=hub({runtime});
    instance.openPreference('colors');local.fail=true;instance.choosePreference('red-up');
    assert.equal(instance.section,'preference-colors');assert.equal(instance.displayPreferences.colors,'green-up');assert.equal(runtime.document.documentElement.dataset.marketColors,'green-up');
    assert.equal(runtime.events.length,0);assert.match(instance.message,/保存失败，原设置已保留/);assert.equal(local.getItem('deepro.displayPreferences'),null);
    local.fail=false;instance.choosePreference('red-up');assert.equal(instance.section,'preferences');assert.equal(instance.message,'');assert.equal(runtime.state.colors,'red-up');
});

test('back and Escape leave preferences unchanged and return to the correct dialog level',()=>{
    const {instance,emitted,runtime}=hub();
    instance.openPreference('currency');instance.cancel();assert.equal(instance.section,'preferences');assert.equal(runtime.events.length,0);
    instance.back();assert.equal(instance.section,'profile');instance.cancel();assert.deepEqual(emitted,['close']);
    instance.openPreference('not-a-preference');assert.equal(instance.section,'profile');
    instance.openPreference('currency');instance.choosePreference('NOT-A-CURRENCY');assert.equal(instance.section,'preference-currency');assert.equal(runtime.events.length,0);
});

test('another tab updates shared preferences and malformed events retain the last valid selection',()=>{
    const runtime=preferenceRuntime();
    runtime.window.dispatchEvent({type:'storage',key:'deepro.displayPreferences',newValue:JSON.stringify({currency:'HKD',colors:'red-up'})});
    assert.deepEqual({...runtime.state},{currency:'HKD',colors:'red-up'});assert.equal(runtime.document.documentElement.dataset.marketColors,'red-up');
    runtime.window.dispatchEvent({type:'storage',key:'deepro.displayPreferences',newValue:'{invalid'});assert.equal(runtime.state.currency,'HKD');
});

test('existing chart bridge reads the new saved preference and is unchanged on a rejected save',()=>{
    const local=storage(),runtime=preferenceRuntime(local),overrides=[];
    const frame={addEventListener(){}};
    runtime.window.location={origin:'https://fixture.invalid'};
    vm.runInNewContext(read('public/js/deepro-display-colors.js'),{window:frame,parent:runtime.window,location:{origin:'https://fixture.invalid'},localStorage:local});
    frame.attachDeeproDisplayColors({applyOverrides:value=>overrides.push(value),applyStudiesOverrides(){}});
    runtime.save({currency:'USDT',colors:'red-up'});
    assert.equal(overrides.at(-1)['mainSeriesProperties.candleStyle.upColor'],'#e60012');
    assert.equal(overrides.at(-1)['mainSeriesProperties.candleStyle.downColor'],'#008a32');
    assert.equal(overrides.length,2);local.fail=true;assert.equal(runtime.save({currency:'USDT',colors:'green-up'}),false);assert.equal(overrides.length,2);
});

test('Vue template compiles and copy buttons are outside all navigation links',()=>{
    const template=compiler.parseComponent(hubSource).template.content;
    const result=compiler.compile(template);assert.deepEqual(result.errors,[]);
    let copyButtons=0;
    const visit=(node,ancestors=[])=>{
        if(node.type!==1)return;
        if(node.tag==='button' && node.attrsMap['@click']==='copyUid') {copyButtons++;assert.equal(ancestors.some(parent=>['link','a'].includes(parent.tag.toLowerCase())),false);}
        for(const child of node.children||[])visit(child,[...ancestors,node]);
        for(const condition of node.ifConditions||[])if(condition.block!==node)visit(condition.block,ancestors);
    };
    visit(result.ast);assert.equal(copyButtons,1);
});

test('about keeps missing material as placeholders while explicit hidden/API/unpublished paths remain absent',async()=>{
 const {instance}=hub();instance.$page.props.siteNavigation={api_public:false,suppressed_paths:['/about','/terms','/docs/api','/umi-ecosystem'],groups:[{title:'Live',links:[{path:'/markets',label:'Markets'},{path:'/fees',label:'Fees',visible:false}]}]};
 const items=instance.aboutGroups.flatMap(group=>group.links),paths=items.map(item=>item.path);
 assert.ok(paths.includes('/privacy-gdpr'));assert.equal(items.find(item=>item.path==='/privacy-gdpr').unavailable,true);assert.equal(items.find(item=>item.path==='/markets').unavailable,false);
 for(const path of ['/about','/terms','/docs/api','/umi-ecosystem','/umi-ecosystem/portfolio','/fees'])assert.equal(paths.includes(path),false);
 let navigated=false;instance.$inertia={visit(){navigated=true}};await instance.activate(items.find(item=>item.path==='/privacy-gdpr'));assert.equal(navigated,false);assert.equal(instance.message,'暂未开通此功能');
});
