<template><div>
  <h3>{{ $t("认购档位") }}</h3><p>{{ $t("以 USDT 价值匹配，下限包含、上限不包含；各档位必须连续。仅影响新认购。") }}</p>
  <div class="ub-table"><table><thead><tr><th>{{ $t("下限 USDT") }}</th><th>{{ $t("上限 USDT") }}</th><th>{{ $t("额度倍数") }}</th></tr></thead><tbody><tr v-for="(t,i) in value.tiers" :key="i"><td><input v-model.trim="t.min" :aria-label="$t(&quot;档位&quot;)+(i+1)+$t(&quot;下限&quot;)" required inputmode="decimal"/></td><td><input v-if="i<value.tiers.length-1" v-model.trim="t.max" :aria-label="$t(&quot;档位&quot;)+(i+1)+$t(&quot;上限&quot;)" required inputmode="decimal"/><span v-else>{{ $t("无上限") }}</span></td><td><input v-model.trim="t.multiplier" :aria-label="$t(&quot;档位&quot;)+(i+1)+$t(&quot;倍数&quot;)" required inputmode="decimal"/></td></tr></tbody></table></div>
  <h3>{{ $t("团队等级") }}</h3><p>{{ $t("V1–V3 有现存用户样本；V4–V9 按已取得材料配置。手动等级作为下限，自动业绩等级仍可向上晋升。") }}</p>
  <div class="ub-table"><table><thead><tr><th>{{ $t("等级") }}</th><th>{{ $t("个人 USDT") }}</th><th>{{ $t("小区 USDT") }}</th><th>{{ $t("级差比例") }}</th></tr></thead><tbody><tr v-for="r in value.ranks" :key="r.level"><td>V{{r.level}}</td><td><input v-model.trim="r.personal" :aria-label="'V'+r.level+$t(&quot;个人门槛&quot;)" required inputmode="decimal"/></td><td><input v-model.trim="r.small" :aria-label="'V'+r.level+$t(&quot;小区门槛&quot;)" required inputmode="decimal"/></td><td><input v-model.trim="r.rate" :aria-label="'V'+r.level+$t(&quot;比例&quot;)" required inputmode="decimal"/></td></tr></tbody></table></div>
  <h3>{{ $t("VIP 质押门槛") }}</h3><div class="ub-rule-grid"><label v-for="(n,i) in value.vip_thresholds" :key="i">VIP {{i+1}} · UMI<input v-model.trim="value.vip_thresholds[i]" required inputmode="decimal"/></label></div>
</div></template>
<script>
export default{props:{value:Object}};
</script>
