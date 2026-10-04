import test from 'node:test';
import assert from 'node:assert/strict';
import {fundedRewardEstimate} from '../../resources/js/Functions/UserDisplay.mjs';
test('funded reward preview truncates to the same eight decimals as the reserve',()=>{
 assert.equal(fundedRewardEstimate('0.0001','0.024657534246575342'),'0.00000002');
 assert.equal(fundedRewardEstimate('0.5','0.1'),'0.00050000');
 assert.equal(fundedRewardEstimate('','0.1'),'0.00000000');
 assert.equal(fundedRewardEstimate('-1','0.1'),'0.00000000');
});
