import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source = readFileSync(new URL('../../resources/js/Functions/UmiRequest.js', import.meta.url), 'utf8');
const {prepareIntent, retainIntentAfterError, validShareAmount} = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
test('an uncertain retry keeps the receipt key; changed amount and recipient get a new intent', () => {
    let count = 0; const key = () => 'key-' + ++count;
    const first = prepareIntent(null, 'stock_to_deepro', {amount:'1'}, key);
    assert.equal(prepareIntent(first, 'stock_to_deepro', {amount:'1'}, key).key, first.key);
    assert.notEqual(prepareIntent(first, 'stock_to_deepro', {amount:'2'}, key).key, first.key);
    assert.notEqual(prepareIntent(first, 'transfer', {amount:'1',to_member_id:3}, key).key, first.key);
    assert.equal(retainIntentAfterError(new Error('network disconnected')), true);
    assert.equal(retainIntentAfterError({response:{status:503}}), true);
    assert.equal(retainIntentAfterError({response:{status:422}}), false);
});
test('stock maximum compares exact decimal quantities, including values beyond Number precision', () => {
    assert.equal(validShareAmount('0','5'), false);
    assert.equal(validShareAmount('-1','5'), false);
    assert.equal(validShareAmount('1e2','100'), false);
    assert.equal(validShareAmount('1.000000001','5'), false);
    assert.equal(validShareAmount('0.00000001','0.00000001'), true);
    assert.equal(validShareAmount('5.00000001','5'), false);
    assert.equal(validShareAmount('99999999999999999.99999999','99999999999999999.99999998'), false);
});
