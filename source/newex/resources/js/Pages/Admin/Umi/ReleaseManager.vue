<template><section class="ub-card">
  <h2>{{ $t("释放计划管理") }}</h2><p>{{ $t("调整从") }} {{portfolio.release_effective_on}} {{ $t("起生效，原始计划和已结算收益保留。暂停期间不释放，恢复后按日继续，不自动补发暂停日期。") }}</p>
  <form @submit.prevent="save"><fieldset class="ub-fieldset" :disabled="busy||state.read_only">
    <label>{{ $t("释放来源") }}<themed-select v-model="target" @change="load"><option disabled value="">{{ $t("请选择") }}</option><option v-if="portfolio.opening" value="continuity">{{ $t("原账户每日释放") }}</option><option v-for="p in portfolio.plans" :key="p.id" :value="'plan:'+p.id">{{ $t("新计划 #") }}{{p.id}}</option></themed-select></label>
    <template v-if="target"><label>{{ $t("状态") }}<themed-select v-model="status"><option value="active">{{ $t("正常释放") }}</option><option value="paused">{{ $t("暂停释放") }}</option></themed-select></label>
      <label>{{target==='continuity'?$t("每日释放数量 · UMI"):$t("该计划日比例（0.008 = 0.8%）")}}<input v-model.trim="amount" required inputmode="decimal" /></label>
      <label>{{ $t("调整依据") }}<textarea v-model.trim="reason" required minlength="10" maxlength="2000" rows="3" /></label>
      <button>{{ $t("保存释放版本") }}</button></template>
  </fieldset></form><p v-if="message" role="status">{{message}}</p><p v-if="error" class="ub-error" role="alert">{{error}}</p>
  <h3>{{ $t("释放变更记录") }}</h3><p v-if="!portfolio.release_history.length">{{ $t("尚无调整，沿用原日量或计划比例。") }}</p>
  <div class="ub-table"><table v-if="portfolio.release_history.length"><thead><tr><th>{{ $t("生效日 / 来源") }}</th><th>{{ $t("日释放量") }}</th><th>{{ $t("状态与依据") }}</th></tr></thead><tbody><tr v-for="v in portfolio.release_history" :key="v.id"><td>{{v.effective_on}}<small>{{v.target_type==='continuity'?$t("历史接续"):$t("计划 #")+v.target_id}}</small></td><td>{{fmt(v.daily_amount)}} UMI</td><td>{{v.status==='active'?$t("正常释放"):$t("暂停")}}<small>{{v.reason}}</small></td></tr></tbody></table></div>
  <h3>{{ $t("等级变化记录") }}</h3><p>{{ $t("从本功能上线开始记录，原快照等级保留在历史档案。") }}</p><p v-if="!portfolio.level_history.length">{{ $t("尚无新等级变化。") }}</p>
  <div class="ub-table"><table v-if="portfolio.level_history.length"><thead><tr><th>{{ $t("日期") }}</th><th>{{ $t("等级") }}</th><th>{{ $t("个人 / 小区业绩") }}</th></tr></thead><tbody><tr v-for="v in portfolio.level_history" :key="v.id"><td>{{v.business_date}}</td><td>V{{v.before_level}} → V{{v.after_level}}</td><td>{{fmt(v.personal)}} / {{fmt(v.small_area)}} USDT</td></tr></tbody></table></div>
</section></template>
<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import axios from 'axios';
export default {props:{portfolio:Object,state:Object},data:()=>({target:'',status:'active',amount:'',revision:0,reason:'',busy:false,error:'',message:'',pending:null}),methods:{
  fmt(n){return String(n??'0').replace(/(\.\d*?)0+$/,'$1').replace(/\.$/,'');},
  load(){const r=this.target==='continuity'?this.portfolio.release:this.portfolio.plans.find(p=>'plan:'+p.id===this.target)?.release;if(!r)return;this.status=r.status;this.amount=this.fmt(this.target==='continuity'?r.daily_amount:r.daily_rate);this.revision=r.revision_id;this.error='';},
  async save(){if(this.busy)return;this.busy=true;this.error='';this.message='';const legacy=this.target==='continuity';const v={action:'release_revision',account_id:this.portfolio.account.id,target_type:legacy?'continuity':'plan',target_id:legacy?this.portfolio.account.id:Number(this.target.split(':')[1]),expected_revision:this.revision,status:this.status,reason:this.reason,[legacy?'daily_amount':'daily_rate']:this.amount};const hash=JSON.stringify(v);if(!this.pending||this.pending.hash!==hash)this.pending={hash,key:crypto.randomUUID()};v.request_key=this.pending.key;
    try{await axios.post(this.route('admin.umi.business.submit'),v);this.pending=null;this.message=legacyText("释放调整已保存。");this.$inertia.reload({preserveScroll:true,onSuccess:()=>this.load()});}catch(e){this.error=Object.values(e.response?.data?.errors||{}).flat().join('；')||legacyText("暂时无法确认结果，请保留输入重试。");}finally{this.busy=false;}}
}};
</script>
