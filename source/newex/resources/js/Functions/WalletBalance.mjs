// Decimal strings remain exact until the display boundary.
export function balanceDecimal(value, precision = 18) {
 const text = String(value ?? '').trim();
 if (!/^\d+(?:\.\d+)?$/.test(text)) return null;
 const [whole, fraction = ''] = text.split('.');
 return whole.replace(/^0+(?=\d)/, '') + (fraction.slice(0, precision).replace(/0+$/, '') ? '.' + fraction.slice(0, precision).replace(/0+$/, '') : '');
}
export function compareBalance(left, right) {
 const parts = value => {const [whole, fraction=''] = (balanceDecimal(value) || '0').split('.');return BigInt(whole + fraction.padEnd(18, '0'));};
 const a=parts(left), b=parts(right); return a>b ? 1 : a<b ? -1 : 0;
}
export function addBalance(left, right, precision=18) {
 const units=value=>{const [whole,fraction='']=(balanceDecimal(value)||'0').split('.');return BigInt(whole+fraction.padEnd(18,'0'));};
 const digits=(units(left)+units(right)).toString().padStart(19,'0');
 return balanceDecimal(digits.slice(0,-18)+'.'+digits.slice(-18), precision);
}
export function walletBalanceReady(status, updatedAt, now=Date.now()) {
 return status==='ready' && updatedAt>0 && now>=updatedAt && now-updatedAt<60000;
}
// A recent snapshot can remain visible while refreshing, but cannot authorize a payment.
export function walletBalanceVisible(status, updatedAt, now=Date.now()) {
 return ['ready','loading'].includes(status) && walletBalanceReady('ready',updatedAt,now);
}

export function percentageBalance(value, percent, precision=18) {
 const text=balanceDecimal(value)||'0', [whole,fraction='']=text.split('.');
 const units=BigInt(whole+fraction.padEnd(18,'0'));
 const ratio=BigInt(Math.max(0,Math.min(10000,Math.round(Number(percent)*100)))||0);
 const raw=(units*ratio/10000n).toString().padStart(19,'0');
 return balanceDecimal(raw.slice(0,-18)+'.'+raw.slice(-18),precision);
}
