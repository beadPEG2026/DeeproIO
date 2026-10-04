const crypto = require('crypto');

// Generated from the actual async chunk groups, never by guessing a filename.
const PAGES = ['Home/Home', 'Market/Market', 'Market/Markets', 'MarketLite/Market', 'MarketLite/Markets', 'Explore/Stocks'];

function createManifest(compilation) {
    // Mix names its entry with a leading slash, while plain Webpack does not.
    const entry = compilation.getAsset('/js/app.js') || compilation.getAsset('js/app.js');
    if (!entry) throw new Error('Frontend entry missing from preload build');
    const pages = {};
    for (const page of PAGES) {
        const group = compilation.namedChunkGroups.get(`page-${page.replaceAll('/', '-')}-vue`);
        if (!group) throw new Error(`Missing initial page chunk group: ${page}`);
        const files = [...new Set(group.getFiles())].filter(file => file.endsWith('.js'));
        if (!files.length || files.length > 6 || files.some(file => !/^js\/chunks\/[A-Za-z0-9_.~-]+\.[a-f0-9]{12}\.js$/.test(file) || !compilation.getAsset(file))) {
            throw new Error(`Invalid initial page assets: ${page}`);
        }
        pages[page] = files.map(file => `/frontend/${file}`);
    }
    return {version: 1, entry_sha256: crypto.createHash('sha256').update(entry.source.source()).digest('hex'), pages};
}

class PagePreloadsPlugin {
    apply(compiler) {
        compiler.hooks.thisCompilation.tap('PagePreloadsPlugin', compilation => {
            compilation.hooks.processAssets.tap({name: 'PagePreloadsPlugin', stage: compiler.webpack.Compilation.PROCESS_ASSETS_STAGE_REPORT}, () => {
                compilation.emitAsset('page-preloads.json', new compiler.webpack.sources.RawSource(JSON.stringify(createManifest(compilation), null, 2) + '\n'));
            });
        });
    }
}

module.exports = {PagePreloadsPlugin, createManifest, PAGES};
