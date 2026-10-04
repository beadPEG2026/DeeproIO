import test from 'node:test';
import assert from 'node:assert/strict';
import {withdrawalAmountError as error, withdrawalRate} from '../../resources/js/Functions/WithdrawalFlow.mjs';
const valid={amount:'12.00000001',balance:'20',minimum:'10',maximum:'100',received:'9.00000001'};
test('valid decimal withdrawal can reach confirmation',()=>assert.equal(error(valid),''));
test('invalid and over-precision input cannot reach confirmation',()=>{for(const amount of ['','0','-1','1e3','NaN','Infinity','1,000','12.000000001','9'.repeat(400)])assert.ok(error({...valid,amount}));});
test('balance, minimum and maximum block impossible amounts',()=>{assert.match(error({...valid,amount:'21'}),/balance/);assert.match(error({...valid,amount:'9'}),/minimum/);assert.match(error({...valid,maximum:'11'}),/maximum/);assert.equal(error({...valid,maximum:'0'}),'');});
test('zero, negative and missing proceeds cannot be confirmed',()=>{for(const received of ['0','-1',undefined])assert.match(error({...valid,received}),/fee/);});

test('daily allowance is applied at its boundary',()=>{assert.equal(error({...valid,dailyAvailable:valid.amount}),'');assert.match(error({...valid,dailyAvailable:'12'}),/daily/);});
test('coin-denominated X Layer and internal rates use correct routes',()=>{const currency={withdraw_fee_fixed:99,withdrawal_fee_rates:{default:{percent:'0',fixed:'0.01'},xlayer20:{percent:'0.3',fixed:'2'},erc20:{percent:'0',fixed:'5'}}};assert.deepEqual(withdrawalRate(currency,25),{percent:'0.3',fixed:'2'});assert.equal(withdrawalRate(currency,24).fixed,'0.01');assert.equal(withdrawalRate(currency,3).fixed,'5');assert.deepEqual(withdrawalRate(currency,25,true),{percent:'0',fixed:'0'});});
import {recipientAddress} from '../../resources/js/Functions/RecipientAddress.js';
test('X Layer recipients and QR imports match chain 196 only',()=>{const address='0x1111111111111111111111111111111111111111';for(const id of [24,25]){assert.equal(recipientAddress(address,id),address);assert.equal(recipientAddress('ethereum:'+address+'@196',id),address);assert.throws(()=>recipientAddress('ethereum:'+address+'@1',id));assert.throws(()=>recipientAddress('invalid',id));}});
