import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync} from 'node:fs';
import {networkIdentity,networkRouteKind,networkOptionDescription,networkDisplayName} from '../../resources/js/Functions/NetworkIdentity.mjs';
test('token standard does not override the actual chain identity',()=>{
 assert.equal(networkIdentity(25,'X Layer (USDT / ERC20)').name,'X Layer');
 assert.equal(networkIdentity(null,'X Layer (ERC20)').name,'X Layer');
 assert.equal(networkIdentity(null,'Polygon (ERC20)').name,'Polygon');
 assert.equal(networkIdentity(null,'BNB Smart Chain (BEP20)').name,'BNB Smart Chain');
 assert.equal(networkIdentity(null,'TRON (TRC20)').name,'TRON');
 assert.equal(networkIdentity(null,'Ethereum (ERC20)').name,'Ethereum');
});
test('same-chain admin routes retain their identity and explain native vs token',()=>{
 const options=[{value:2,text:'Ethereum (ETH)'},{value:3,text:'Ethereum (ERC20)'},{value:15,text:'Polygon (POL)'},{value:16,text:'Polygon (ERC20)'},{value:8,text:'TRON (TRC20)'}];
 assert.equal(networkOptionDescription(options[0],options),'Native asset');
 assert.equal(networkOptionDescription(options[1],options),'Token');
 assert.equal(networkOptionDescription(options[2],options),'Native asset');
 assert.equal(networkOptionDescription(options[3],options),'Token');
 assert.equal(networkOptionDescription(options[4],options),'');
 assert.deepEqual(options.map(x=>x.value),[2,3,15,16,8]);
 assert.equal(networkRouteKind(25),'Token');
 assert.equal(networkRouteKind(null),'');
 assert.equal(networkOptionDescription({value:99,text:'Internal'},[]),'');
});
test('every enabled blockchain has a local logo and unknown networks are not mislabeled',()=>{
 for(const id of [2,3,5,6,7,8,9,15,16,20,21,22,23,24,25]){
  const logo=networkIdentity(id).logo;assert.ok(existsSync(new URL('../../public'+logo,import.meta.url)),logo);
 }
 for(const id of [0,4,11,13,97,98])assert.equal(networkIdentity(id),null);
 assert.equal(networkIdentity(196),null); // Not an application network ID.
});
test('wallet chain labels retain ERC20 only on Ethereum and do not change route IDs',()=>{
 assert.equal(networkDisplayName(3,'Ethereum (ERC20)'),'Ethereum (ERC20)');
 assert.equal(networkDisplayName(2,'Ethereum'),'Ethereum');
 assert.equal(networkDisplayName(25,'X Layer (USDT / ERC20)'),'X Layer');
 assert.equal(networkDisplayName(16,'Polygon (ERC20)'),'Polygon');
 assert.equal(networkDisplayName(6,'BNB Smart Chain (ERC20)'),'BNB Smart Chain');
 assert.equal(networkDisplayName(9,'Bitcoin'),'Bitcoin');
 assert.equal(networkDisplayName(99,'Internal'),'Internal');
});
