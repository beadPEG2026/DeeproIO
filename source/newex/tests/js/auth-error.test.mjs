import test from 'node:test';
import assert from 'node:assert/strict';
import { authError } from '../../resources/js/Functions/AuthError.mjs';

test('Chinese forms translate password rule messages with localized attribute names', () => {
    assert.equal(authError('The 密码 must be at least 8 characters.', 'zh-cn'), '密码至少需要 8 个字符。');
    assert.equal(authError('The password must be at least 12 characters.', 'zh-cn'), '密码至少需要 12 个字符。');
    assert.equal(authError('The 密碼 must be at least 8 characters.', 'zh-tw'), '密碼至少需要 8 個字元。');
    assert.equal(authError('The 密码 confirmation does not match.', 'zh-cn'), '两次输入的密码不一致。');
    assert.equal(authError('password 确认不一致。', 'zh-cn'), '两次输入的密码不一致。');
    assert.equal(authError('The 密码 field is required.', 'zh-cn'), '请输入密码。');
});

test('Chinese forms translate email messages after server attribute localization', () => {
    assert.equal(authError('The 邮箱 field is required.', 'zh-cn'), '请输入邮箱。');
    assert.equal(authError('The 邮箱 must be a valid email address.', 'zh-cn'), '请输入有效的邮箱地址。');
    assert.equal(authError('The 電子郵件 must be a valid email address.', 'zh-tw'), '請輸入有效的電子郵件地址。');
});

test('unrelated and non-Chinese messages remain untouched', () => {
    assert.equal(authError('The password must be at least 8 characters.', 'en'), 'The password must be at least 8 characters.');
    assert.equal(authError('验证码不正确。', 'zh-cn'), '验证码不正确。');
    assert.equal(authError(null, 'zh-cn'), null);
});
