<template><section class="ub-quote" :aria-label="$t(&quot;操作预览&quot;)"><h3>{{ $t("确认本次变动") }}</h3><p>{{ $t(quote.notice) }}</p>
  <dl><div v-for="(n,asset) in quote.fees" :key="'fee'+asset"><dt>{{ $t("手续费") }}</dt><dd>{{fmt(n)}} {{asset}}</dd></div>
    <div v-for="(n,asset) in quote.wallet_changes" :key="'wallet'+asset"><dt>{{ $t("交易所资金账户") }}{{Number(n)>0?$t("到账"):$t("扣除")}}</dt><dd>{{fmt(n)}} {{asset}}</dd></div>
    <div v-for="(n,asset) in quote.recipient_changes" :key="'recipient'+asset"><dt>{{ $t("收款人到账") }}</dt><dd>{{fmt(n)}} {{asset}}</dd></div>
    <div v-for="c in quote.changes.filter(c=>Number(c.delta)!==0)" :key="c.pocket+c.asset"><dt>{{name(c.pocket)}}</dt><dd>{{fmt(c.delta)}} {{c.asset}}<small>{{ $t("变动后") }} {{fmt(c.after)}}</small></dd></div>
  </dl>
  <p v-for="(p,i) in quote.plans" :key="'plan'+i">{{ $t("增加额度") }} {{fmt(p.quota)}} {{ $t("UMI；从") }} {{p.starts_on}} {{ $t("起每日释放") }} {{fmt(p.daily_amount)}} {{ $t("UMI。") }}</p>
  <p v-for="(u,i) in quote.unstakes" :key="'unstake'+i">{{ $t("预计解锁：") }}{{u.unlock_at}}{{ $t("（北京时间），解押数量") }} {{fmt(u.amount)}} {{ $t("UMI。") }}</p>
  <small>{{ $t("规则版本 #") }}{{quote.rule_id}}</small>
</section></template>
<script>
import UmiLocale from '@/Functions/UmiLocale';
import { legacyText } from '@/Functions/LegacyTranslation';

export default {mixins:[UmiLocale],props:{quote:Object},methods:{fmt(n){return String(n??'0').replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'');},name(p){return {main:legacyText("可用余额"),linear:legacyText("线性收益"),team:legacyText("团队收益"),referral:legacyText("直推收益"),treasure:legacyText("UMI 宝"),reserve:legacyText("储备金"),vip:legacyText("VIP 质押"),unstaking:legacyText("解押中"),points:legacyText("内部积分")}[p]||p;}}};
</script>
