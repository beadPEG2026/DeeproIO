import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
const require=createRequire(import.meta.url),compiler=require('vue-template-compiler');
const source=readFileSync(new URL('../../resources/js/Pages/Staking/Stakings.vue',import.meta.url),'utf8');
const template=readFileSync(new URL('../../resources/js/Themes/default/Web/Pages/Staking/Stakings.template',import.meta.url),'utf8');
const context={Template:options=>options,AppLayout:{},IconFilter:{},annualizedRate(){},firstStakingPeriod(){},window:{getSelection:()=>''}};
vm.runInNewContext(source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*\n/gm,'').replace('export default Template(','globalThis.options=Template('),context);
test('clicking non-interactive staking card content opens that exact product once',()=>{
 const calls=[],state={setStaking:(...args)=>calls.push(args)},staking={id:7};context.options.methods.openStakingCard.call(state,{target:{closest:()=>null}},staking,0);assert.deepEqual(calls,[[staking,0]]);
});
test('native product links and text selection do not trigger a second card navigation',()=>{
 let calls=0;const state={setStaking:()=>calls++},method=context.options.methods.openStakingCard;
 method.call(state,{target:{closest:()=>({tagName:'A'})}},{id:1},0);
 method.call(state,{defaultPrevented:true,target:{closest:()=>null}},{id:1},0);
 context.window.getSelection=()=> '12.5%';method.call(state,{target:{closest:()=>null}},{id:1},0);context.window.getSelection=()=>'';assert.equal(calls,0);
});
test('keyboard-accessible product destination remains a real link with a product label',()=>{
 const parsed=compiler.compile(template);assert.deepEqual(parsed.errors,[]);
 function visit(node){if(!node)return null;if(node.tag==='Link' && node.attrsMap.class?.includes('staking-card__btn'))return node;for(const child of node.children||[]){const result=visit(child);if(result)return result}for(const condition of (node.ifConditions||[]).slice(1)){const result=visit(condition.block);if(result)return result}return null}
 const link=visit(parsed.ast);assert.ok(link);assert.equal(link.attrsMap[':href'],"route('staking',staking.id)+'?staking_type='+$page.props.staking_type");assert.match(link.attrsMap[':aria-label'],/staking.currency_symbol/);assert.equal(link.attrsMap['@click'],undefined);
});
