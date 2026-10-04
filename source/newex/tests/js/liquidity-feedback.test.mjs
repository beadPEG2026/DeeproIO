import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

function createPage(post) {
    const source = readFileSync(new URL('../../resources/js/Pages/Admin/Liquidity/Index.vue', import.meta.url), 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/^import .*;?\s*$/gm, '')
        .replace('export default Template(', 'globalThis.component = Template(');
    const requests = [];
    const toasts = { success: [], error: [] };
    const context = {
        Template: value => value,
        legacyText: key => `translated:${key}`,
        axios: { post: (url, form) => { requests.push({ url, form }); return post(url, form); } },
        Badge: {}, NavLink: {}, NavButtonLink: {}, AppLayout: {}, Welcome: {},
        Pagination: {}, NavButton: {}, NavButtonActive: {},
    };
    vm.runInNewContext(script, context);
    const instance = {
        ...context.component.data(),
        route: name => name,
        $toast: { open: message => toasts.success.push(message), error: message => toasts.error.push(message) },
    };
    for (const [name, method] of Object.entries(context.component.methods)) instance[name] = method.bind(instance);
    return { instance, requests, toasts };
}

test('run and stop echo the actual server liquidity state and release sending', async () => {
    for (const method of ['runLiquidity', 'stopLiquidity']) {
        for (const liq of [false, true]) {
            const { instance, requests, toasts } = createPage(async () => ({ data: { status: 'Server confirmed', liq } }));
            const market = { id: 15, name: 'UMI-USDT', liq_enabled: !liq };
            const pending = instance[method](market);
            assert.equal(instance.sending, market.id);
            await pending;
            assert.equal(market.liq_enabled, liq);
            assert.equal(instance.sending, false);
            assert.equal(requests[0].url, method === 'runLiquidity' ? 'admin.liquidity.run' : 'admin.liquidity.stop');
            assert.equal(JSON.stringify(requests[0].form), '{"market":"UMI-USDT"}');
            assert.deepEqual(toasts, { success: ['Server confirmed'], error: [] });
        }
    }
});

test('failures display backend messages, market validation or translated fallback without changing state', async () => {
    const failures = [
        [{ response: { data: { message: 'Inventory required', errors: { market: ['Other validation'] } } } }, 'Inventory required'],
        [{ response: { data: { errors: { market: ['Market price unavailable'] } } } }, 'Market price unavailable'],
        [{ response: { status: 409, data: { message: 'Liquidity update already in progress' } } }, 'Liquidity update already in progress'],
        [{ response: { data: {} } }, 'translated:Request failed'],
        [new Error('Network Error'), 'translated:Request failed'],
    ];
    for (const method of ['runLiquidity', 'stopLiquidity']) {
        for (const isService of [false, true]) {
            for (const [failure, expected] of failures) {
                const { instance, toasts } = createPage(async () => { throw failure; });
                const model = isService
                    ? { name: 'market-service', command: 'market:test', status: method === 'stopLiquidity' }
                    : { id: 15, name: 'UMI-USDT', liq_enabled: method === 'stopLiquidity' };
                const before = { ...model };
                const pending = instance[method](model, isService);
                assert.equal(instance.sending, isService ? model.name : model.id);
                await pending;
                assert.deepEqual(model, before);
                assert.equal(instance.sending, false);
                assert.deepEqual(toasts, { success: [], error: [expected] });
            }
        }
    }
});

test('backend failure compensation disables only a market with explicit boolean false', async () => {
    for (const method of ['runLiquidity', 'stopLiquidity']) {
        for (const liq of [false, 'false', 0, null, true]) {
            const { instance, toasts } = createPage(async () => { throw { response: { status: 503, data: { liq, message: 'Liquidity start failed' } } }; });
            const market = { id: 15, name: 'UMI-USDT', liq_enabled: liq === true ? false : true };
            const previous = market.liq_enabled;
            await instance[method](market);
            assert.equal(market.liq_enabled, liq === false ? false : previous);
            assert.equal(instance.sending, false);
            assert.deepEqual(toasts, { success: [], error: ['Liquidity start failed'] });
        }

        const { instance } = createPage(async () => { throw { response: { status: 503, data: { liq: false, message: 'Service failed' } } }; });
        const service = { name: 'market-service', command: 'market:test', status: true };
        await instance[method](service, true);
        assert.deepEqual(service, { name: 'market-service', command: 'market:test', status: true });
        assert.equal(instance.sending, false);
    }
});

test('service success keeps service semantics and sending prevents overlapping run or stop', async () => {
    let resolveRequest;
    const { instance, requests, toasts } = createPage(() => new Promise(resolve => { resolveRequest = resolve; }));
    const service = { name: 'market-service', command: 'market:test', status: false };
    const started = instance.runLiquidity(service, true);
    await instance.runLiquidity(service, true);
    await instance.stopLiquidity(service, true);
    assert.equal(requests.length, 1);
    assert.equal(JSON.stringify(requests[0].form), '{"command":"market:test","service":"market-service"}');
    resolveRequest({ data: { status: 'Running' } });
    await started;
    assert.equal(service.status, 'Running');
    assert.equal(instance.sending, false);

    const stopped = instance.stopLiquidity(service, true);
    await instance.runLiquidity(service, true);
    await instance.stopLiquidity(service, true);
    assert.equal(requests.length, 2);
    resolveRequest({ data: { status: 'Stopped' } });
    await stopped;
    assert.equal(service.status, false);
    assert.equal(instance.sending, false);
    assert.deepEqual(toasts, { success: ['Running', 'Stopped'], error: [] });
});
