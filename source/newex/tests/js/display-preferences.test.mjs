import test from 'node:test';
import assert from 'node:assert/strict';
import {sanitizePreferences,displayCurrency,displayValue,pinHongKongMarket,currencyLabel} from '../../resources/js/Functions/DisplayPreferences.mjs';
test('unsupported currencies fall back without an invented exchange rate',()=>{
 const options=[{symbol:'USDT',rate:1},{symbol:'CNY',rate:7.2},{symbol:'EUR',rate:0}];
 assert.equal(displayCurrency(options,'EUR').symbol,'EUR');assert.equal(displayValue(10,displayCurrency(options,'CNY')),'72.00');assert.equal(displayValue(null,options[0]),'—');
 assert.deepEqual(sanitizePreferences({currency:'<script>',colors:'bad'}),{currency:'USDT',colors:'green-up'});
});
test('HK08379 remains above sorting only within the filtered HK list',()=>{
 const rows=[{name:'HK00700-USDT'},{name:'HK08379-USDT'},{name:'HK00005-USDT'}];
 assert.deepEqual(pinHongKongMarket(rows,'HK').map(m=>m.name),['HK08379-USDT','HK00700-USDT','HK00005-USDT']);
 assert.equal(pinHongKongMarket(rows,'US'),rows);assert.equal(rows[0].name,'HK00700-USDT');
 assert.equal(pinHongKongMarket([rows[0]],'HK').length,1);
});

test('configured currency glyphs persist and invalid rates never produce a value',()=>{
 assert.equal(sanitizePreferences({currency:'€'}).currency,'EUR');
 assert.equal(displayValue(10,displayCurrency([{symbol:'USD',rate:Infinity}],'USD')),'—');
});

test('display labels follow Chinese and English while stored codes stay ISO',()=>{assert.equal(currencyLabel('CNY','zh-cn'),'CNY · 人民币');assert.equal(currencyLabel('JPY','zh-cn'),'JPY · 日元');assert.equal(currencyLabel('HKD','zh-tw'),'HKD · 港幣');assert.equal(currencyLabel('USD','en'),'USD · US Dollar');assert.equal(displayValue(10,{symbol:'CNY',rate:null}),'—');});
