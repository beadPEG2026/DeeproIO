import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../../resources/js/Functions/UmiDisplay.js',import.meta.url),'utf8');
const {formatUmi,umiTime,validUmiAmount}=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
test('exact decimals retain every meaningful digit and missing balances stay unavailable',()=>{
 assert.equal(formatUmi('99999999999999999.123456789123456789123456'),'99,999,999,999,999,999.123456789123456789123456');
 assert.equal(formatUmi(null),'—');assert.equal(formatUmi('0'),'0');
 assert.equal(formatUmi('-1234.5000'),'-1,234.5');
 assert.equal(validUmiAmount('0.000000000000000000000001','0.000000000000000000000001'),true);
 assert.equal(validUmiAmount('0.000000000000000000000002','0.000000000000000000000001'),false);
 assert.equal(validUmiAmount('1e2','100'),false);
});
test('absolute times render UTC+8 with seconds and unqualified source times are not guessed',()=>{
 assert.equal(umiTime('2026-10-01T16:00:01Z'),'2026/10/02 00:00:01 (UTC+8)');
 assert.equal(umiTime('2026-10-01 18:00:01'),'2026-10-01 18:00:01');
});
