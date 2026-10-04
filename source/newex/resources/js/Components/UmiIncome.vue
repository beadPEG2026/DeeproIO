<template>
 <section class="ui-income">
  <article class="ui-income__quota"><div><small>{{ $t('剩余额度') }} · UMI</small><strong>{{fmt(quota.active_remaining_umi)}}</strong><span>{{quota.active_count||0}} {{ $t('轮释放中') }}</span></div><img src="/umi/v2/art/quota-box.png" alt=""></article>
  <div class="ui-income__summary">
   <article><small>{{ $t('累计收益') }} · UMI</small><strong>{{ fmt(summary.total_umi) }}</strong></article>
   <article><small>{{ $t('今日到账收益') }} · UMI</small><strong>{{ fmt(summary.today_umi) }}</strong></article>
  </div>
  <nav :aria-label="$t('收益类别')"><button v-for="item in kinds" :key="item.key" :aria-pressed="kind===item.key" @click="choose(item.key)">{{ $t(item.label) }}</button></nav>
  <header><span>{{ $t('收益明细') }}</span><button @click="$emit('transfer',kind)">{{ $t('划转') }} →</button></header>
  <p v-if="loading" role="status">{{ $t('正在查询…') }}</p>
  <p v-else-if="error" role="alert">{{ error }} <button @click="load(page)">{{ $t('重试') }}</button></p>
  <template v-else-if="records">
   <p v-if="!records.rows.length">{{ $t('暂无收益记录') }}</p>
   <article v-for="row in records.rows" :key="row.id" class="ui-income__record">
    <div><b>{{ $t(label(row.kind)) }}</b><strong>+{{ fmt(row.released_umi) }} UMI</strong></div>
    <dl><dt>{{ $t('记录编号') }}</dt><dd>{{ row.id }}</dd><dt>{{ $t('业务日期') }}</dt><dd>{{ row.business_date }}</dd><dt>{{ $t('到账时间') }}</dt><dd>{{ time(row.created_at) }}</dd></dl>
   </article>
   <footer v-if="records.last_page>1"><button :disabled="page<=1" @click="load(page-1)">{{ $t('上一页') }}</button><span>{{ page }} / {{ records.last_page }}</span><button :disabled="page>=records.last_page" @click="load(page+1)">{{ $t('下一页') }}</button></footer>
  </template>
 </section>
</template>
<script>
import axios from 'axios';
import UmiLocale from '@/Functions/UmiLocale';
import {formatUmi,umiTime} from '@/Functions/UmiDisplay';
export default {
 mixins:[UmiLocale],props:{quota:{type:Object,default:()=>({})},summary:{type:Object,required:true},initialKind:{type:String,default:''}},
 data(){return {kind:this.initialKind,page:1,loading:false,error:'',records:null,sequence:0,kinds:[{key:'',label:'全部'},{key:'static',label:'理财收益'},{key:'team',label:'团队收益'},{key:'referral',label:'推荐收益'}]}},
 watch:{initialKind(kind){this.choose(kind)}},mounted(){this.load(1)},beforeDestroy(){this.sequence++},
 methods:{fmt:formatUmi,time(v){return umiTime(v,this.$i18n.locale)},label(kind){return this.kinds.find(item=>item.key===kind)?.label||'收益'},choose(kind){this.kind=kind;this.load(1)},async load(page){const sequence=++this.sequence;this.loading=true;this.error='';try{const r=await axios.get('/umi-ecosystem/portfolio/records',{params:{dataset:'releases',source:this.kind,status:'posted',page}});if(sequence!==this.sequence)return;this.records=r.data;this.page=page}catch(e){if(sequence===this.sequence)this.error=e.response?.data?.message||this.$t('查询失败，请重试。')}finally{if(sequence===this.sequence)this.loading=false}}}
};
</script>
<style scoped>
.ui-income{margin-top:18px}.ui-income__quota{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #c4cec6;border-radius:12px;padding:16px;margin-bottom:12px}.ui-income__quota small,.ui-income__quota span{display:block;color:#657269;font-size:12px}.ui-income__quota strong{display:block;font-size:28px;margin:6px 0;overflow-wrap:anywhere}.ui-income__quota img{width:65px;height:65px;object-fit:contain}.ui-income__summary article,.ui-income__record{border-color:#c4cec6!important;border-radius:12px!important;box-shadow:none}body.dark .ui-income__quota{background:#1f2c23;border-color:#425a49}body.dark .ui-income__quota small,body.dark .ui-income__quota span{color:#b2c2b7}.ui-income__summary{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ui-income__summary article,.ui-income__record{padding:18px;border:1px solid #dce6df;border-radius:16px;background:#fff}.ui-income__summary small{color:#657c6d}.ui-income__summary strong{display:block;font-size:24px;overflow-wrap:anywhere;margin-top:6px}.ui-income nav{display:flex;gap:14px;overflow-x:auto;margin-top:22px;border-bottom:1px solid #e0e8e3}.ui-income button{border:0;background:none;color:inherit;min-height:44px;padding:8px;white-space:nowrap;font:inherit}.ui-income nav button[aria-pressed=true]{color:#218241;border-bottom:3px solid #218241;font-weight:700}.ui-income header,.ui-income footer,.ui-income__record>div{display:flex;align-items:center;justify-content:space-between;gap:12px}.ui-income header{margin:10px 0}.ui-income__record{margin:10px 0;font-size:13px}.ui-income__record strong{color:#258441;overflow-wrap:anywhere;text-align:right}.ui-income__record dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:7px;margin:12px 0 0}.ui-income__record dt{color:#718076}.ui-income__record dd{margin:0;text-align:right}.ui-income footer{margin-top:16px}.ui-income button:disabled{opacity:.4}body.dark .ui-income__summary article,body.dark .ui-income__record{background:#202a24;border-color:#405449}body.dark .ui-income__summary small,body.dark .ui-income__record dt{color:#bed2c5}
</style>
