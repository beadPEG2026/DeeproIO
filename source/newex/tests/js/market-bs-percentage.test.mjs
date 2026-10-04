import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { bsRatioToPercent, bsPercentToRatio } from '../../resources/js/Functions/MarketBsPercentage.mjs';

test('percentage conversion preserves decimal precision, zero, empty and legacy values', () => {
    for (const [percentage, ratio] of [
        ['30', '0.3'], ['29', '0.29'], ['30.5', '0.305'], ['0', '0'],
        ['100', '1'], ['250', '2.5'], ['-30', '-0.3'], ['0.000001', '0.00000001'],
        ['99.9999999999999999', '0.999999999999999999'],
    ]) {
        assert.equal(bsPercentToRatio(percentage), ratio);
        assert.equal(bsRatioToPercent(ratio), percentage);
    }
    for (const value of [null, undefined, '', ' ']) {
        assert.equal(bsPercentToRatio(value), null);
        assert.equal(bsRatioToPercent(value), null);
    }
    assert.equal(bsRatioToPercent('0.300000'), '30');
    assert.equal(bsRatioToPercent(1e-8), '0.000001');
    assert.equal(bsPercentToRatio('3e1'), '0.3');
    assert.equal(bsPercentToRatio(' 030.00 '), '0.3');
    assert.equal(bsPercentToRatio('30%'), '30%'); // Existing numeric validator rejects it.
    assert.equal(bsPercentToRatio('1e99999'), 'NaN');
});

test('actual market form loads percentages and sends a ratio on repeated submissions', () => {
    const source = readFileSync(new URL('../../resources/js/Pages/Admin/Markets/Form.vue', import.meta.url), 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/^import .*;?\s*$/gm, '')
        .replace('export default Template(', 'globalThis.component = Template(');
    const context = { legacyText: x => x, bsRatioToPercent, bsPercentToRatio, Template: x => x,
        NavButtonLink: {}, AppLayout: {}, TextInput: {}, TextareaInput: {}, LoadingButton: {}, TrashedMessage: {}, SelectInput: {} };
    vm.runInNewContext(script, context);
    const component = context.component;
    const submitted = [];
    const instance = { ...component.data(), isEdit: true, market: {id: 1, bs: '0.3', last: '100', base_precision: 8, quote_precision: 2},
        loadCurrencies() {}, $page: {props: {}}, route: () => '/test', $inertia: { put: (url, payload) => submitted.push(payload) } };
    component.mounted.call(instance);
    assert.equal(instance.form.bs_percent, '30');
    for (let i = 0; i < 2; i++) component.methods.submit.call(instance);
    for (const payload of submitted) {
        assert.equal(payload.bs, '0.3');
        assert.equal('bs_percent' in payload, false);
        assert.equal('last' in payload, false);
    }
    assert.equal(instance.form.bs_percent, '30');
    assert.equal(instance.form.bs, '0.3');
    instance.form.bs_percent = '30.5';
    component.methods.submit.call(instance);
    assert.equal(submitted.at(-1).bs, '0.305');
});


test('BS-only save sends no unrelated fields, preserves drafts and handles errors', async () => {
    const source = readFileSync(new URL('../../resources/js/Pages/Admin/Markets/Form.vue', import.meta.url), 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/^import .*;?\s*$/gm, '')
        .replace('export default Template(', 'globalThis.component = Template(');
    let resolveSave;
    const sent = [];
    const context = { legacyText: x => x, bsRatioToPercent, bsPercentToRatio, Template: x => x,
        axios: { put: (url, payload) => { sent.push({url, payload}); return new Promise(resolve => { resolveSave = resolve; }); } },
        NavButtonLink: {}, AppLayout: {}, TextInput: {}, TextareaInput: {}, LoadingButton: {}, TrashedMessage: {}, SelectInput: {} };
    vm.runInNewContext(script, context);
    const component = context.component;
    const toasts = [];
    const instance = { ...component.data(), isEdit: true, market: { id: 15 },
        form: {bs: '0.1', bs_percent: '30', min_trade_size: 0, chart_symbol: 'UNSAVED-DRAFT'},
        $page: {props: {}}, route: (name, id) => `${name}/${id}`,
        $toast: {open: value => toasts.push(value), error: value => toasts.push(value), warning() {}},
        $inertia: {put() {throw new Error('Full submit must not race with BS save');}} };
    const save = component.methods.saveBs.call(instance);
    assert.equal(instance.savingBs, true);
    assert.equal(sent[0].url, 'admin.markets.bs.update/15');
    assert.equal(JSON.stringify(sent[0].payload), '{"bs":"0.3"}');
    await component.methods.saveBs.call(instance);
    component.methods.submit.call(instance);
    assert.equal(sent.length, 1);
    instance.form.bs_percent = '50';
    resolveSave({data: {bs: '0.3'}});
    await save;
    assert.equal(instance.form.bs, '0.3');
    assert.equal(instance.form.bs_percent, '50');
    assert.equal(instance.form.chart_symbol, 'UNSAVED-DRAFT');
    assert.equal(instance.form.min_trade_size, 0);
    assert.equal(instance.savingBs, false);
    context.axios.put = async () => {throw {response: {data: {errors: {bs: ['Invalid BS']}}}};};
    await component.methods.saveBs.call(instance);
    assert.equal(instance.bsError, 'Invalid BS');
    assert.equal(instance.form.bs, '0.3');
    assert.equal(instance.form.bs_percent, '50');
    assert.equal(instance.savingBs, false);
});
