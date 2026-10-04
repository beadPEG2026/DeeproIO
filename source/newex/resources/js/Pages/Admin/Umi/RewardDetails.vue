<template><section class="ub-card"><h2>{{ $t("逐笔收益与计算路径") }}</h2><p>{{ $t("应计、实际到账与额度变化按原记录展示，查看团队路径可核对每一层比例。") }}</p>
<p v-if="!portfolio.rewards.total">{{ $t("暂无新收益记录。") }}</p>
<details v-for="r in portfolio.rewards.data" :key="r.id" class="ub-card"><summary>{{r.business_date}} · {{kind(r.kind)}} {{ $t("· 应计") }} {{fmt(r.expected)}} {{ $t("/ 到账") }} {{fmt(r.paid)}} UMI</summary>
  <dl><div><dt>{{ $t("计算基数 × 比例") }}</dt><dd>{{fmt(r.base)}} × {{fmt(r.rate)}}</dd></div><div><dt>{{ $t("已用额度变化") }}</dt><dd>{{fmt(r.quota_before)}} → {{fmt(r.quota_after)}}</dd></div><div><dt>{{ $t("结果") }}</dt><dd>{{reason(r.reason)}} {{ $t("· 规则 #") }}{{r.rule_id}}</dd></div></dl>
  <p v-if="context(r).release_revision">{{ $t("释放调整版本 #") }}{{context(r).release_revision}}</p>
  <div class="ub-table" v-if="context(r).path"><table><thead><tr><th>{{ $t("途经账户") }}</th><th>{{ $t("等级") }}</th><th>{{ $t("比例 / 已占比例") }}</th></tr></thead><tbody><tr v-for="p in context(r).path" :key="p.id"><td>#{{p.id}}</td><td>V{{p.level}}</td><td>{{fmt(p.rate)}} / {{fmt(p.intermediary_max)}}</td></tr></tbody></table></div>
  <small>{{ $t("操作编号") }} {{r.operation_id}}</small>
</details><div class="ub-pagination"><a v-if="portfolio.rewards.prev_page_url" :href="portfolio.rewards.prev_page_url">{{ $t("上一页") }}</a><span>{{ $t("共") }} {{portfolio.rewards.total}} {{ $t("条") }}</span><a v-if="portfolio.rewards.next_page_url" :href="portfolio.rewards.next_page_url">{{ $t("下一页") }}</a></div>
</section></template>
<script>
import { legacyText } from '@/Functions/LegacyTranslation';

export default{props:{portfolio:Object},methods:{fmt(n){return String(n??'0').replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'');},context(r){try{return JSON.parse(r.context);}catch(_){return {};}},kind(s){return {linear:legacyText("线性释放"),team:legacyText("团队级差"),referral:legacyText("直推"),interest:legacyText("宝利息"),peer:legacyText("平级")}[s]||s;},reason(s){return {legacy_review:legacyText("待核对后补发"),quota_limited:legacyText("额度封顶"),excluded:legacyText("动态奖励排除")}[s]||legacyText("正常");}}};
</script>
