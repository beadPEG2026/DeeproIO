'use strict';
exports.units=(value,decimals)=>{
  const s=String(value); if(!/^\d+(\.\d+)?$/.test(s)||!Number.isInteger(decimals)||decimals<0||decimals>36)throw Error('CUSTODY_INVALID_AMOUNT');
  const [a,b='']=s.split('.'); if(b.slice(decimals).replace(/0/g,''))throw Error('CUSTODY_PRECISION_EXCEEDED');
  return BigInt(a+(b.slice(0,decimals).padEnd(decimals,'0')));
};
exports.decimal=(value,decimals)=>{const n=BigInt(value);if(n<0n)throw Error('CUSTODY_NEGATIVE_AMOUNT');const s=n.toString().padStart(decimals+1,'0');return decimals?s.slice(0,-decimals)+'.'+s.slice(-decimals):s;};
exports.positive=n=>{if(BigInt(n)<=0n)throw Error('CUSTODY_INSUFFICIENT_BALANCE');return n;};
exports.digest=v=>require('crypto').createHash('sha256').update(JSON.stringify(v)).digest('hex');
