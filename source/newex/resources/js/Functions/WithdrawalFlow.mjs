// Client-side step feedback only; the existing server remains authoritative.
export function withdrawalAmountError({amount, balance, minimum=0, maximum=0, received, dailyAvailable=null}) {
 const raw=String(amount ?? '').trim();
 if (!/^\d+(?:\.\d{1,8})?$/.test(raw) || !Number.isFinite(Number(raw)) || Number(raw)<=0) return 'Enter a positive amount with up to 8 decimal places.';
 const value=Number(raw);
 if (value>Number(balance)) return 'Amount exceeds available balance.';
 if (value<Number(minimum)) return 'Amount is below the minimum withdrawal.';
 if (Number(maximum)>0 && value>Number(maximum)) return 'Amount exceeds the maximum withdrawal.';
 if (dailyAvailable!==null && value>Number(dailyAvailable)) return 'You have reached your daily withdrawal limit.';
 if (!Number.isFinite(Number(received)) || Number(received)<=0) return 'Withdrawal amount must exceed the fee.';
 return '';
}

export function withdrawalRate(currency, network, internal=false) {
 if (internal) return {percent:'0',fixed:'0'};
 const keys={3:'erc20',6:'bep20',8:'trc20',16:'matic20',21:'solspl',25:'xlayer20'};
 const key=keys[Number(network)] || 'default';
 if (currency.withdrawal_fee_rates?.[key]) return currency.withdrawal_fee_rates[key];
 const suffix={erc20:'erc',bep20:'bep',trc20:'trc',matic20:'matic',solspl:'sol',xlayer20:'xlayer'}[key];
 return {percent:currency[suffix?'withdraw_fee_'+suffix:'withdraw_fee'] || '0',fixed:currency[suffix?'withdraw_fee_'+suffix+'_fixed':'withdraw_fee_fixed'] || '0'};
}
