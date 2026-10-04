<template><section class="dp-home-dashboard">
 <div v-if="displayBanners.length" class="dp-banner" role="region" :aria-label="$t('Homepage banners')" @mouseenter="paused=true" @mouseleave="paused=false" @focusin="paused=true" @focusout="paused=false" @touchstart.passive="touchX=$event.touches[0].clientX" @touchend.passive="swipe($event)">
  <div class="dp-banner-window"><div class="dp-banner-track" :style="{transform:'translateX(-'+bannerIndex*100+'%)'}"><component v-for="(b,i) in displayBanners" :key="i" :is="b.link?'a':'div'" :href="b.link || null" :tabindex="b.link && i===bannerIndex?0:-1" :aria-hidden="i!==bannerIndex" class="dp-banner-card" :class="b.tone" ><img v-if="b.image" :src="b.image" alt=""><span class="dp-banner-copy"><small>DEEPRO</small><strong>{{b.title}}</strong><span>{{b.subtitle}}</span></span><span v-if="!b.image" class="dp-banner-art" aria-hidden="true"><action-icon :name="b.tone==='mint'?'ecosystem':b.tone==='violet'?'invite':'earn'"/></span></component></div></div>
  <div class="dp-banner-dots"><button v-for="(b,i) in displayBanners" :key="i" :aria-label="$t('Banner')+' '+(i+1)" :aria-current="i===bannerIndex?'true':null" @click="bannerIndex=i"><i/></button><button v-if="displayBanners.length>1" :aria-label="$t(auto?'Pause':'Play')" @click="auto=!auto">{{auto?'Ⅱ':'▷'}}</button></div>
 </div>
 <section class="dp-home-balance" :aria-busy="balanceLoading"><div><Link :href="route('wallets.new')" class="dp-home-balance__label">{{ $t('Estimated Assets') }} (BTC) ›</Link><strong>{{ $page.props.user ? btcBalanceValue : '—' }}<i class="dp-balance-live" :class="{'is-connected':balanceFresh}" :title="balanceFresh ? $t('Assets updated') : $t('Assets not refreshed')" :aria-label="balanceFresh ? $t('Assets updated') : $t('Assets not refreshed')"></i></strong><small>{{ $page.props.user ? '≈ ' + displayAmount(balance?.rawUsd) + ' ' + selectedDisplayCurrency.symbol : $t('Log in to view your assets') }}</small><small v-if="$page.props.user" class="dp-home-refresh" role="status"><span v-if="balanceUpdatedAt">{{ t('资产更新于','Assets updated') }} {{ updateTime(balanceUpdatedAt) }}</span><span v-if="balanceError || (balanceUpdatedAt && !balanceFresh)">{{ balanceUpdatedAt ? t(' · 更新失败或已过期，显示上次数据',' · Refresh unavailable or stale; showing previous data') : t('资产暂不可用，请重试','Assets unavailable. Please retry.') }}</span><button type="button" :disabled="balanceLoading" @click="loadBalance">{{ balanceLoading?t('更新中…','Refreshing…'):t('刷新','Refresh') }}</button></small></div><Link class="dp-add-funds" :href="route('wallets.deposit.crypto','USDT')">{{ $t('Add funds') }}</Link></section>
 <nav class="dp-home-services" :aria-label="$t('Quick actions')"><component v-for="a in actions" :key="a.label" :is="a.href?'a':'button'" :href="a.href || null" :class="{'is-ecosystem':a.icon==='ecosystem'}" @click="!a.href && openDiscover()"><span><action-icon :name="a.icon"/></span><b>{{ $t(a.label) }}</b></component></nav>
 <div class="dp-announcement-ticker" @mouseenter="noticePaused=true" @mouseleave="noticePaused=false" @focusin="noticePaused=true" @focusout="noticePaused=false"><action-icon name="bell"/><Link :href="notice ? route('article',notice.id):route('articles')">{{ notice ? notice.title : $t('Announcements') }}</Link><Link :href="route('articles')" :aria-label="$t('All announcements')">›</Link></div>
 <header class="dp-home-section-heading"><h2>{{ $t('Hot markets') }}</h2><Link :href="route('markets')">{{ $t('View All') }} ›</Link></header>
 <div class="dp-home-quotes" :aria-busy="ticker.loading"><a v-for="m in markets" :key="m.name" :href="route('market',m.name)"><header><img :src="m.base_currency_logo" alt=""><b>{{m.base_currency}}</b><small :class="changeClass(m.change)">{{change(m.change)}}</small></header><strong>{{number(m.last)}} <em>{{m.quote_currency}}</em></strong><svg v-if="paths(m.name).length" viewBox="0 0 92 36" :class="changeClass(m.change)" aria-hidden="true"><polyline v-for="(p,i) in paths(m.name)" :key="i" :points="p" fill="none" stroke="currentColor" stroke-width="1"/></svg><span v-else class="dp-home-quote-empty">{{ sparkState[m.name] === 'loading' || !sparkState[m.name] ? $t('Chart loading') : t('暂无图表数据','Chart unavailable') }}</span></a></div>
 <p v-if="ticker.loading || ticker.retrying || ticker.error || marketsStale || !markets.length" class="dp-home-refresh dp-home-ticker-status" role="status">
  <span v-if="ticker.loading">{{ $t('Loading markets…') }}</span><span v-else-if="ticker.retrying">{{ $t('Retrying...') }}</span><span v-else-if="ticker.error">{{ $t('Market list could not be updated.') }}</span><span v-else-if="!markets.length">{{ $t('No markets found') }}</span>
  <span v-if="marketsStale">{{ $t('Quotes may be stale. Showing the last available data.') }}</span>
  <button v-if="ticker.error || marketsStale || !markets.length" type="button" :disabled="ticker.loading" @click="loadTicker()">{{ $t('Retry') }}</button>
 </p>
 <p v-if="sparkFailed" class="dp-home-refresh" role="status">{{ t('部分图表暂不可用','Some charts are unavailable') }} <button type="button" :disabled="sparksLoading" @click="loadSparks">{{ sparksLoading?t('加载中…','Loading…'):t('重试图表','Retry charts') }}</button></p>
 <p v-if="message" class="dp-service-toast" role="status">{{message}}</p>
</section></template>
<script>
import DisplayPreferences from '@/Mixins/DisplayPreferences';
import ActionIcon from './ActionIcon.vue';
import MarketDisplay from '@/Mixins/Market/MarketDisplay.vue';
import {enabled} from '@/Functions/MarketRanking.mjs';
import {sparkPaths} from '@/Functions/MarketDiscovery.mjs';
import {createHomeTickerLoader, initialHomeTickerState, homeQuoteIsStale} from '@/Functions/HomeTicker.mjs';
export default {
 components:{ActionIcon},mixins:[MarketDisplay,DisplayPreferences],props:{banners:{type:Array,default:()=>[]},articles:Object},
 data:()=>({bannerIndex:0,noticeIndex:0,paused:false,noticePaused:false,auto:true,touchX:0,balance:null,balanceLoading:false,balanceError:false,balanceUpdatedAt:null,now:Date.now(),balanceSerial:0,ticker:initialHomeTickerState(),tickerLoader:null,sparks:{},sparkState:{},sparksLoading:false,sparkSerial:0,message:'',alive:true,cancel:axios.CancelToken.source()}),
 computed:{
  displayBanners(){const defaults=[{title:this.t('数字资产，从容掌握','Digital assets, confidently yours'),subtitle:this.t('关注市场，管理资产','Follow markets. Manage assets.'),tone:'gold',link:this.route('wallets.new')},{title:this.t('探索 UMI 生态','Explore the UMI ecosystem'),subtitle:this.t('连接产品，发现更多可能','Connect with products and possibilities'),tone:'mint',link:this.route('umi.portfolio')}];const list=this.banners.length?[...this.banners]:defaults;while(list.length<2)list.push(defaults[list.length]);if(!list.some(b=>b.link?.includes('referral-transactions')))list.push({title:this.t('邀好友 · 直推享 15% 返佣','Invite friends · 15% direct referral commission'),subtitle:this.t('交易手续费多代合计最高 50%，领取深度依等级规则','Up to 50% across all referral levels; eligibility depends on tier rules'),tone:'violet',link:this.route('reports.referral-transactions')});return list},
  balanceFresh(){return !!this.balanceUpdatedAt && !this.balanceError && this.now-this.balanceUpdatedAt<65000},
  marketsStale(){return this.markets.length>0 && (this.ticker.error || this.markets.some(m=>homeQuoteIsStale(m,Math.max(this.now,Date.now()))))},
  sparkFailed(){return Object.values(this.sparkState).some(s=>['error','empty'].includes(s))},
  btcBalanceValue(){const total=this.balance?.rawUsd;const price=Number(this.markets.find(m=>m.base_currency==='BTC')?.last);return total!==null&&total!==undefined&&Number.isFinite(total)&&price>0?(total/price).toFixed(8):'—'},
  notice(){const a=this.articles?.data||[];return a[this.noticeIndex%Math.max(1,a.length)]},
  balanceValue(){const value=this.balance?.totatUsdBalance;return value===null||value===undefined?'—':value},
  markets(){return this.displayMarkets.filter(m=>[true,1,'1'].includes(m.status)&&!enabled(m.stock_token)&&m.quote_currency==='USDT').sort((a,b)=>['BTC','ETH','SOL','UMI','BNB'].indexOf(a.base_currency)-['BTC','ETH','SOL','UMI','BNB'].indexOf(b.base_currency)).filter(m=>['BTC','ETH','SOL','UMI','BNB'].includes(m.base_currency)).slice(0,5)},
  actions(){return [{label:'Deposit',icon:'deposit',href:this.route('wallets.deposit.crypto','USDT')},{label:'Withdraw',icon:'withdraw',href:this.route('wallets.withdraw.crypto','USDT')},{label:'Invite friends',icon:'invite',href:this.route('reports.referral-transactions')},{label:'Discover',icon:'search'},{label:'Ecosystem',icon:'ecosystem',href:this.route('umi.portfolio')}]},
  marketKey(){return this.markets.map(m=>m.name).join('|')}
 },
 watch:{marketKey(){this.sparkCancel?.cancel();this.sparkSerial++;this.sparksLoading=false;this.loadSparks()},banners(){this.bannerIndex=0}},
 mounted(){
  this.auto=!window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  this.timer=setInterval(()=>{if(document.hidden)return;if(this.auto&&!this.paused&&this.displayBanners.length)this.bannerIndex=(this.bannerIndex+1)%this.displayBanners.length;if(this.auto&&!this.noticePaused)this.noticeIndex++},5000);
  this.tickerLoader=createHomeTickerLoader({
   request:()=>this.$store.dispatch('fetchMarkets',this.route('markets.api.ticker')),
   onState:state=>{this.ticker=state;this.now=Date.now()},
   canLoad:()=>this.alive&&!document.hidden&&window.navigator.onLine!==false
  });
  if(!this.markets.length||this.marketsStale)this.loadTicker('initial');
  document.addEventListener('visibilitychange',this.refreshTicker);window.addEventListener('online',this.refreshTicker);
  this.loadBalance();this.balanceTimer=setInterval(()=>{this.now=Date.now();this.refreshVisible()},30000);
  document.addEventListener('visibilitychange',this.refreshVisible);window.addEventListener('online',this.refreshVisible);this.loadSparks()
 },
 beforeDestroy(){
  this.alive=false;this.tickerLoader?.dispose();
  document.removeEventListener('visibilitychange',this.refreshTicker);window.removeEventListener('online',this.refreshTicker);
  this.balanceSerial++;this.sparkSerial++;document.removeEventListener('visibilitychange',this.refreshVisible);window.removeEventListener('online',this.refreshVisible);
  clearInterval(this.timer);clearInterval(this.balanceTimer);clearTimeout(this.messageTimer);this.cancel.cancel();this.sparkCancel?.cancel()
 },
 methods:{t(zh,en){return this.$t(en)},openDiscover(){window.dispatchEvent(new CustomEvent('deepro:hub',{detail:'discover'}))},paths(name){return sparkPaths(this.sparks[name]?.points||[])},number(v){const n=Number(v);return v==null||!Number.isFinite(n)?'—':n.toLocaleString(undefined,{maximumFractionDigits:8})},change(v){return v==null?'—':(Number(v)>0?'+':'')+Number(v).toFixed(2)+'%'},changeClass(v){return Number(v)>0?'is-up':Number(v)<0?'is-down':''},unavailable(){this.message=this.t('暂未开通此功能','This feature is not available yet');clearTimeout(this.messageTimer);this.messageTimer=setTimeout(()=>this.message='',2500)},swipe(e){const dx=e.changedTouches[0].clientX-this.touchX;if(Math.abs(dx)>40)this.bannerIndex=(this.bannerIndex+(dx<0?1:-1)+this.displayBanners.length)%this.displayBanners.length},updateTime(value){return new Date(value).toLocaleString(this.$i18n.locale,{month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'})},
  loadTicker(reason='manual'){return this.tickerLoader?.load(reason)},
  refreshTicker(){if(!this.alive||document.hidden)return;this.now=Date.now();if(!this.markets.length||this.marketsStale||this.ticker.error)this.loadTicker('event')},
  refreshVisible(){if(document.hidden)return;this.now=Date.now();this.loadBalance();if(this.marketsStale&&!this.ticker.error)this.loadTicker('event');if(this.sparkFailed)this.loadSparks()},
  async loadBalance(){
   if(!this.$page.props.user||document.hidden||this.balanceLoading||!this.alive)return;
   this.balanceLoading=true;const serial=++this.balanceSerial;
   try{const {data}=await axios.get(this.route('wallets.index'),{timeout:12000,cancelToken:this.cancel.token});
    if(this.alive&&serial===this.balanceSerial){const rows=data.data||[];const total=rows.length?rows[0].all_assets_total_usdt:0;
     if(total===undefined||total===null||total===''||!Number.isFinite(Number(total)))throw new Error('Invalid balance response');
     this.balance={rawUsd:Number(total),totatUsdBalance:this.number(total)};this.balanceError=false;this.balanceUpdatedAt=Date.now();this.now=Date.now();
    }
   }catch(error){if(this.alive&&serial===this.balanceSerial&&!axios.isCancel(error))this.balanceError=true}
   finally{if(this.alive&&serial===this.balanceSerial)this.balanceLoading=false}
  },
  async loadSparks(){
   if(this.sparksLoading||!this.alive||document.hidden)return;
   const names=this.markets.map(m=>m.name);if(!names.length)return;
   this.sparksLoading=true;const serial=++this.sparkSerial;this.sparkCancel=axios.CancelToken.source();
   names.forEach(n=>this.$set(this.sparkState,n,'loading'));
   // Limit each request and keep at most one batch in flight, without delaying the rest of the page.
   for(let i=0;i<names.length;i+=2){
    if(!this.alive||serial!==this.sparkSerial)break;
    const batch=names.slice(i,i+2);
    try{const {data}=await axios.get('/markets/data/sparklines',{params:{markets:batch},timeout:12000,cancelToken:this.sparkCancel.token});
     if(this.alive&&serial===this.sparkSerial){this.sparks={...this.sparks,...data.data};batch.forEach(n=>this.$set(this.sparkState,n,this.paths(n).length?'ready':'empty'))}
    }catch(error){if(this.alive&&serial===this.sparkSerial&&!axios.isCancel(error))batch.forEach(n=>this.$set(this.sparkState,n,'error'))}
   }
   if(this.alive&&serial===this.sparkSerial)this.sparksLoading=false;
  }
 }
};
</script>
<style scoped>
.dp-home-refresh{font-size:12px!important;line-height:1.6;display:block}.dp-home-refresh button{min-height:32px;padding:2px 4px;border:0 solid var(--ui-divider,#ddd);border-radius:6px;background:transparent;color:inherit;margin-left:6px}.dp-home-balance>div{min-width:0}.dp-home-refresh span{white-space:normal}
</style>
