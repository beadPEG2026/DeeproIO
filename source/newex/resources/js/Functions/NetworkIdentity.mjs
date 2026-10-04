// IDs are the application's network IDs, not EVM chain IDs.
const chains = {
 ethereum: {name:'Ethereum',logo:'/images/currencies/eth.png'},
 bsc: {name:'BNB Smart Chain',logo:'/images/currencies/bnb.png'},
 tron: {name:'TRON',logo:'/images/currencies/trx.png'},
 bitcoin: {name:'Bitcoin',logo:'/images/currencies/btc.png'},
 polygon: {name:'Polygon',logo:'/images/networks/polygon.svg'},
 solana: {name:'Solana',logo:'/images/currencies/sol.png'},
 xlayer: {name:'X Layer',logo:'/images/networks/xlayer.svg'},
 xrp: {name:'XRP Ledger',logo:'/images/networks/xrp.png'},
 ton: {name:'TON',logo:'/images/networks/ton.png'},
};
const ids = {2:'ethereum',3:'ethereum',5:'bsc',6:'bsc',7:'tron',8:'tron',9:'bitcoin',15:'polygon',16:'polygon',17:'bitcoin',20:'solana',21:'solana',22:'xrp',23:'ton',24:'xlayer',25:'xlayer'};
export function networkRouteKind(id) {
 if ([2,5,7,9,15,20,22,23,24].includes(Number(id))) return 'Native asset';
 if ([3,6,8,16,17,21,25].includes(Number(id))) return 'Token';
 return '';
}
// Admin filters intentionally retain both native and token route IDs. Explain
// repeated chain names without merging the distinct accounting/filter values.
export function networkOptionDescription(option, options) {
 const identity = networkIdentity(option.value, option.text);
 return identity && options.some(other => other !== option && networkIdentity(other.value, other.text) === identity)
  ? networkRouteKind(option.value) : '';
}
export function networkIdentity(id, name='') {
 if (id !== null && id !== undefined && id !== '' && ids[id]) return chains[ids[id]];
 const text=String(name || id || '').toLowerCase().replace(/[^a-z0-9]/g,'');
 // Specific chains precede token standards: X Layer also uses ERC20.
 for (const [key,pattern] of [['xlayer',/xlayer/],['polygon',/polygon|matic/],['bsc',/bnb|bsc|bep20/],['tron',/tron|trx|trc20/],['solana',/solana|^sol(spl)?$/],['bitcoin',/bitcoin|^btc$|brc20/],['xrp',/xrp|ripple/],['ton',/^ton(network)?$/],['ethereum',/ethereum|^eth$|erc20/]]) {
  if (pattern.test(text)) return chains[key];
 }
 return null;
}

// Display names do not alter the persisted route or its token contract.
export function networkDisplayName(id, name='') {
 const identity=networkIdentity(id,name);
 if (!identity) return String(name || '');
 return identity===chains.ethereum && (Number(id)===3 || /erc20/i.test(name))
  ? 'Ethereum (ERC20)' : identity.name;
}
