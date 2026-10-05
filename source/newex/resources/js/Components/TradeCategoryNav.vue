<template>
<div class="dp-trade-categories">
 <nav :aria-label="$t('Trading type')">
  <button v-for="item in items" :key="item.key" type="button" :class="{'is-active':item.key===(futures?'futures':'spot')}" :aria-current="item.key===(futures?'futures':'spot')?'page':null" @click="choose(item.key)">{{ item.label }}</button>
  <button class="dp-trade-categories__more" type="button" :aria-label="$t('More trading pages')" :aria-expanded="menuOpen" @click="menuOpen=!menuOpen"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
 </nav>
 <div v-if="menuOpen" class="dp-trade-categories__menu"><button type="button" @click="openHub('profile')">{{ $t('Profile') }}</button><theme-mode/><language-switcher/><a :href="route('markets')">{{ $t('Crypto') }}</a><a :href="route('stocks')">{{ $t('Stocks') }}</a></div>
 <div v-if="message" class="dp-trade-categories__toast" role="status" aria-live="polite">{{ message }}</div>
</div>
</template>
<script>
import ThemeMode from '@/Components/ThemeMode';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
export default {
 components:{ThemeMode,LanguageSwitcher},
 props:{market:{type:Object,required:true},futures:Boolean},
 data:()=>({menuOpen:false,message:'',messageTimer:null}),
 computed:{items(){return [
  {key:'futures',label:this.$t('Futures')},
  {key:'spot',label:this.$t('Spot')},
  {key:'margin',label:this.$t('Margin')},
  {key:'onchain',label:this.$t('On-chain')},
  {key:'bots',label:this.$t('Trading bots')}]}},
 beforeDestroy(){clearTimeout(this.messageTimer)},
 methods:{
  openHub(panel){this.menuOpen=false;window.dispatchEvent(new CustomEvent('deepro:hub',{detail:panel}))},
  choose(key){this.menuOpen=false;if(key==='spot')return this.$inertia.visit(this.route('market',this.market.name));if(key==='futures'&&[true,1,'1'].includes(this.market.has_futures))return this.$inertia.visit(this.route('futures-market',this.market.name));this.unavailable()},
  unavailable(){this.menuOpen=false;this.message=this.$t('This feature is not available yet');clearTimeout(this.messageTimer);this.messageTimer=setTimeout(()=>{this.message=''},2600)}
 }
}
</script>
<style scoped>
.dp-trade-categories{position:relative;background:var(--ui-surface,#fff);border-bottom:1px solid var(--ui-divider,#edf0f4);z-index:9}.dp-trade-categories nav{display:flex;align-items:center;gap:22px;max-width:1600px;margin:auto;padding:0 22px;overflow-x:auto;scrollbar-width:none}.dp-trade-categories nav::-webkit-scrollbar{display:none}.dp-trade-categories nav button{flex:none;min-height:48px;padding:0;border:0;border-bottom:3px solid transparent;background:none;color:var(--ui-muted,#808793);font:inherit;font-size:15px;white-space:nowrap}.dp-trade-categories nav button.is-active{color:var(--ui-text,#111820);font-weight:800;border-bottom-color:var(--ui-text,#111820)}.dp-trade-categories nav .dp-trade-categories__more{margin-left:auto;display:flex;align-items:center;min-width:26px}.dp-trade-categories__menu{position:absolute;right:14px;top:100%;display:grid;min-width:160px;border:1px solid var(--ui-divider,#edf0f4);border-radius:14px;background:var(--ui-surface,#fff);box-shadow:0 12px 35px #0002;padding:6px}.dp-trade-categories__menu>a,.dp-trade-categories__menu>button{display:block;text-align:left;border:0;background:none;padding:12px;color:var(--ui-text,#151c26)}.dp-trade-categories__toast{position:fixed;z-index:100;left:50%;top:42%;transform:translate(-50%,-50%);max-width:calc(100vw - 40px);padding:13px 20px;border-radius:12px;background:#17212feb;color:#fff;font-size:14px;text-align:center;box-shadow:0 12px 35px #0002}@media(max-width:600px){.dp-trade-categories nav{padding:0 14px;gap:18px}.dp-trade-categories nav button{font-size:14px;min-height:45px}}@media(max-width:360px){.dp-trade-categories nav{gap:13px;padding:0 10px}.dp-trade-categories nav button{font-size:12px}}
</style>
