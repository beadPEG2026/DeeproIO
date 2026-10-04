/** Format exact decimal strings without converting money through Number. */
export function formatUmi(value) {
    if (value === null || value === undefined || value === '') return '—';
    const raw=String(value);
    if (!/^-?\d+(\.\d+)?$/.test(raw)) return raw;
    const [whole,fraction='']=raw.split('.');
    const decimal=fraction.replace(/0+$/,'');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g,',')+(decimal?'.'+decimal:'');
}
export function umiTime(value, locale = 'zh-CN') {
    if (!value) return '—';
    if (!/(?:Z|[+-]\d{2}:?\d{2})$/.test(String(value))) return String(value);
    const parsed=new Date(value);
    if (!Number.isFinite(parsed.getTime())) return String(value);
    return new Intl.DateTimeFormat(locale,{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(parsed)+' (UTC+8)';
}
export function positiveUmi(value) { return /^\d+(\.\d+)?$/.test(String(value)) && /[1-9]/.test(String(value)); }
export function umiStatus(value) {
    return ({cancelled:'已退回',waiting_evidence:'等待历史证据',resolved:'已恢复',active:'已生效',completed:'已完成',pending:'待提交',custody:'托管处理中',confirmed:'链上已确认',review:'待核对',
        paid:'已到 Deepro 资金账户',awaiting_topup:'等待补充 UMI',funded:'资金已齐备',pending_burn:'等待销毁确认',
        awaiting_credit:'等待充值到账',points_only:'待确权',locked:'已确权 · 锁定中',tradable:'已解锁',posted:'已记账',
        static:'理财收益',team:'团队收益',referral:'推荐收益',activation:'充值销毁',withdrawal:'提取收益销毁',injury:'超额销毁',
        spot:'Deepro 现货划入',deepro_spot:'Deepro 现货成交',activation_intake:'充值划入',withdrawal_spot_topup:'提现现货补款',withdrawal_extra_b:'提现额外准备',wallet:'Deepro 资金账户',verified_chain:'已核验充值',account_cutover:'原账户本金接续',exchange_transfer:'转入交易账户',
        entitlement:'确权',unlock:'解锁',transfer:'站内划转',write_off:'核销',burn_credit:'销毁积分',correction:'调整',reversal:'冲正'})[value] || value || '—';
}

export function validUmiAmount(value, maximum) {
    if (!/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/.test(String(value))) return false;
    const scale = v => {const [w,f='']=String(v).split('.');return BigInt(w)*10n**24n+BigInt((f+'0'.repeat(24)).slice(0,24));};
    try {return scale(value)>0n && scale(value)<=scale(maximum);} catch (_) {return false;}
}
