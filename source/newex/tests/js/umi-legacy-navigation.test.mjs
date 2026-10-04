import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import {umiReturnPath} from '../../resources/js/Functions/UmiNavigation.mjs';
const require=createRequire(import.meta.url),compiler=require('vue-template-compiler');
const read=path=>readFileSync(new URL('../../resources/js/'+path,import.meta.url),'utf8');
test('UMI handoff returns to an allowlisted section and never to an arbitrary URL',()=>{
 for(const [path,tab] of [['/market/UMI-USDT','join'],['/market/HK08379-USDT','points'],['/wallets/deposit/crypto/UMI','mine']])assert.equal(umiReturnPath(path+'?from=umi&umi_tab='+tab),'/umi-ecosystem/portfolio?tab='+tab);
 assert.equal(umiReturnPath('/market/UMI-USDT?from=umi&umi_tab=https://example.com'),'/umi-ecosystem/portfolio?tab=mine');
 for(const path of ['/market/BTC-USDT?from=umi','/wallets/deposit/crypto/UMI','/login?from=umi','/market/UMI-USDT?from=another'])assert.equal(umiReturnPath(path),null);
});
test('legacy-style funded views and independent page navigation compile with the actual Vue compiler',()=>{
 for(const file of ['Pages/Umi/FundedHome.vue','Components/UmiIncome.vue','Components/UmiTeamOverview.vue','Components/UmiReturnLink.vue'])assert.deepEqual(compiler.compile(compiler.parseComponent(read(file)).template.content).errors,[],file);
});
