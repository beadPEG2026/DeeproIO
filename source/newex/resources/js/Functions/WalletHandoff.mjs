const key='deepro:withdraw-transfer';
const ttl=15*60*1000;
export function saveWithdrawalDraft(user, symbol, form, storage=window.sessionStorage, now=Date.now()) {
 // Session-only, owner-bound, no credentials/2FA and no automatic submission.
 const draft={user:String(user),symbol:String(symbol),at:now,form:{}};
 for(const field of ['withdraw_type','network','address','internal_uid','amount','payment_id']) draft.form[field]=form[field] ?? null;
 storage.setItem(key,JSON.stringify(draft));
}
export function takeWithdrawalDraft(user,symbol,storage=window.sessionStorage,now=Date.now()) {
 const raw=storage.getItem(key);if(!raw)return null;
 storage.removeItem(key);
 try {const draft=JSON.parse(raw);if(draft.user!==String(user)||draft.symbol!==String(symbol)||now-draft.at<0||now-draft.at>ttl)return null;
  const form={};for(const field of ['withdraw_type','network','address','internal_uid','amount','payment_id']) {const v=draft.form?.[field];if(v===null||typeof v==='string'||typeof v==='number')form[field]=v;}
  return form;
 }catch(_){return null;}
}
export function transferContext(search, currencies) {
 const query=new URLSearchParams(search);
 const symbol=String(query.get('symbol')||'').toUpperCase();
 const currency=Object.values(currencies||{}).find(c=>String(c.symbol||c.name||'').toUpperCase()===symbol);
 return {currency:currency?.id || null, from:query.get('from')==='trade'?'trade':'funding', withdraw:query.get('return')==='withdraw'&&!!currency};
}
