<template>
<div class="dp-global-data">
 <div class="dp-global-grid">
  <article v-for="key in keys" :key="key" :class="['dp-global-card',{'is-stale':feed(key).stale,'is-sentiment':key==='sentiment'}]">
   <h3>{{ t(titles[key]) }}</h3>
   <template v-if="feed(key).available">
    <strong class="dp-global-value">{{ key==='global'?'$'+compact(feed(key).value):feed(key).value }}<small v-if="key!=='global' && feed(key).classification"> / 100</small></strong>
    <span v-if="key==='global' && feed(key).change!=null" :class="feed(key).change>0?'is-up':'is-down'">{{ signed(feed(key).change) }}</span>
    <template v-if="key==='altcoin'"><div class="dp-alt-scale"><i :style="{left:feed(key).value+'%'}"></i></div><div class="dp-scale-labels"><span>BTC</span><span>{{ t('Altcoin season') }}</span></div></template>
    <svg v-if="key==='sentiment'" class="dp-sentiment-gauge" viewBox="0 0 160 85" role="img" :aria-label="t(feed(key).classification||titles[key])"><path d="M 15 75 A 65 65 0 0 1 145 75" fill="none" stroke="#e9edf0" stroke-width="12"/><path d="M 15 75 A 65 65 0 0 1 145 75" fill="none" stroke="#eac43c" stroke-width="12" pathLength="100" :stroke-dasharray="feed(key).value+' 100'"/><line x1="80" y1="75" :x2="needle(feed(key).value).x" :y2="needle(feed(key).value).y" stroke="currentColor" stroke-width="3"/><circle cx="80" cy="75" r="5" fill="currentColor"/></svg>
    <p v-if="key!=='global' && feed(key).classification">{{ t(feed(key).classification||titles[key]) }}</p>
    <small v-if="feed(key).stale" class="dp-global-stale">{{ t('Quote delayed') }}</small>
   </template>
   <p v-else class="dp-global-unavailable">{{ t(loading?'Loading...':'No data') }}</p>

  </article>
 </div>
 <details class="dp-global-information"><summary :aria-label="t('Data information')">ⓘ {{ t('Data information') }}</summary><p><a href="https://coinmarketcap.com/charts/" target="_blank" rel="noopener noreferrer">CoinMarketCap ↗</a></p><p v-for="key in keys" :key="key">{{ t(titles[key]) }} · {{ t('Updated') }} {{ time(feed(key).sourceTime) }}</p><p>{{ t('Global indicators follow CoinMarketCap methodology.') }}</p></details><button v-if="failed" class="dp-global-retry" @click="load">{{ t('Retry') }}</button>
</div>
</template>
<script>
import axios from 'axios';
export default {
 props:{enabled:{type:Object,default:()=>({})}},computed:{keys(){return (this.enabled.cardOrder||['global','altcoin','sentiment']).filter(k=>this.enabled[k]!==false)}},
 data:()=>({data:{},loading:false,failed:false,alive:true,timer:null,titles:{global:'Global crypto market cap',altcoin:'Altcoin Season Index',sentiment:'Fear and Greed Index'}}),
 mounted(){this.load();this.timer=setInterval(()=>{if(!document.hidden)this.load()},60000)},
 beforeDestroy(){this.alive=false;clearInterval(this.timer)},
 methods:{t(s){return this.$t(s)},feed(k){return this.data[k]||{}},async load(){if(this.loading)return;this.loading=true;try{const r=await axios.get('/markets/data/overview',{timeout:12000});if(this.alive){this.data=r.data.data||{};this.failed=Object.values(this.data).some(f=>!f.available||f.stale)}}catch(_){this.failed=true;this.data=Object.fromEntries(Object.entries(this.data).map(([k,v])=>[k,{...v,stale:true}]))}finally{this.loading=false}},compact(n){return new Intl.NumberFormat('en',{notation:'compact',maximumFractionDigits:2}).format(n)},signed(v){return (v>0?'+':'')+Number(v).toFixed(2)+'%'},time(v){return v?new Date(v).toLocaleString(this.$i18n.locale,{month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false}):'—'},needle(v){const a=Math.PI*(1-v/100);return {x:80+52*Math.cos(a),y:75-52*Math.sin(a)}}}
}
</script>
