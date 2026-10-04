import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {createHash} from 'node:crypto';
const require = createRequire(import.meta.url);
const {createManifest, PAGES} = require('../../build-page-preloads.cjs');

function compilation() {
    const assets = new Map([['js/app.js', {source: {source: () => 'current compiled entry'}}]]);
    const groups = new Map();
    for (const page of PAGES) {
        const name = `page-${page.replaceAll('/', '-')}-vue`;
        const file = `js/chunks/${name}.123456abcdef.js`;
        assets.set(file, {});
        groups.set(name, {getFiles: () => [file]});
    }
    return {assets, namedChunkGroups: groups, getAsset: name => assets.get(name)};
}

test('preloads the exact initial async group including a shared dependency, without warming other pages', () => {
    const c = compilation();
    c.assets.set('js/chunks/32.000000000001.js', {});
    c.namedChunkGroups.set('page-Home-Home-vue', {getFiles: () => ['js/chunks/32.000000000001.js', 'js/chunks/page-Home-Home-vue.123456abcdef.js']});
    const result = createManifest(c);
    assert.equal(result.entry_sha256, createHash('sha256').update('current compiled entry').digest('hex'));
    assert.deepEqual(result.pages['Home/Home'], ['/frontend/js/chunks/32.000000000001.js', '/frontend/js/chunks/page-Home-Home-vue.123456abcdef.js']);
    assert.equal(result.pages['Auth/Login'], undefined);
    assert.equal(result.pages['Home/Home'].some(f => f.includes('Market')), false);
});

test('fails the build rather than publishing missing or unsafe preload references', () => {
    for (const files of [[], ['../secret.js'], ['js/chunks/missing.123456abcdef.js'], Array.from({length: 7}, (_, n) => `js/chunks/${n}.123456abcdef.js`)]) {
        const c = compilation();
        c.namedChunkGroups.set('page-Home-Home-vue', {getFiles: () => files});
        assert.throws(() => createManifest(c), /Invalid initial page assets/);
    }
    const c = compilation(); c.namedChunkGroups.delete('page-Home-Home-vue');
    assert.throws(() => createManifest(c), /Missing initial page/);
    c.assets.delete('js/app.js');
    assert.throws(() => createManifest(c), /Frontend entry missing/);
});

test('accepts the actual Laravel Mix entry asset name with a leading slash', () => {
    const c = compilation();
    c.assets.set('/js/app.js', c.assets.get('js/app.js')); c.assets.delete('js/app.js');
    assert.equal(createManifest(c).entry_sha256, createHash('sha256').update('current compiled entry').digest('hex'));
});
