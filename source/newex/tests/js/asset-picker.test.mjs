import {test} from 'node:test';
import assert from 'node:assert/strict';
import {assetSections} from '../../resources/js/Functions/AssetPicker.mjs';
const assets=[{symbol:'USDT',name:'Tether USD'},{symbol:'BTC',name:'Bitcoin'},{symbol:'UMI',name:'Umi Token'},{symbol:'SPYon',asset_category:'etf',name:'S&P ETF'},{symbol:'AAPLon',asset_category:'stock',name:'Apple'}];
test('picker separates crypto, stocks and ETFs without changing the input catalog',()=>{assert.deepEqual(assetSections(assets).flatMap(g=>g.assets.map(a=>a.symbol)),['BTC','UMI','USDT']);assert.deepEqual(assetSections(assets,'stocks').flatMap(g=>g.assets.map(a=>a.symbol)),['AAPLon','SPYon']);assert.equal(assets[0].symbol,'USDT')});
test('search uses name and symbol, ignores case and remains within the selected category',()=>{assert.equal(assetSections(assets,'crypto','  tether  ')[0].assets[0].symbol,'USDT');assert.equal(assetSections(assets,'stocks','aaplon')[0].assets[0].name,'Apple');assert.deepEqual(assetSections(assets,'crypto','apple'),[]);assert.deepEqual(assetSections([]),[])});
