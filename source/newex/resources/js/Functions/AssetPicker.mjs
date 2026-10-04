export function assetSections(assets = [], category = 'crypto', search = '') {
    const query = search.trim().toLocaleLowerCase();
    const rows = assets.filter(asset => {
        const stock = ['stock', 'etf'].includes(asset.asset_category);
        return stock === (category === 'stocks') && [asset.symbol, asset.name, asset.fullname].join(' ').toLocaleLowerCase().includes(query);
    }).slice().sort((a, b) => String(a.symbol).localeCompare(String(b.symbol), 'en', {sensitivity: 'base'}));
    return rows.reduce((groups, asset) => {
        const first = String(asset.symbol || '#').charAt(0).toUpperCase();
        const letter = /^[A-Z]$/.test(first) ? first : '#';
        const last = groups[groups.length - 1];
        if (last && last.letter === letter) last.assets.push(asset);
        else groups.push({letter, assets: [asset]});
        return groups;
    }, []);
}
