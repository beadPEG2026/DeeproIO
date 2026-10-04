<template>
 <div class="dp-discovery">
  <nav class="dp-discovery__services" :aria-label="t('常用服务','Services')">
   <component v-for="item in services" :key="item.path || item.key" :is="item.path ? 'Link' : 'button'" :href="item.path || null" :type="item.path ? null : 'button'" @click="activateService(item)"><span class="dp-discovery__icon"><action-icon :name="item.icon"/></span><span>{{ item.label }}</span></component>
  </nav>
  <p v-if="serviceMessage" class="dp-discovery__status" role="status">{{ serviceMessage }}</p>
  <section class="dp-discovery__section" :aria-busy="marketsLoading">
   <header><div><h2>{{ t('本站热门行情','Popular markets') }}</h2><small>{{ t('按 USDT 交易对 24 小时成交额排序','Ranked by USDT pair 24h turnover') }}</small></div><Link :href="route('markets')" @click="$emit('close')">{{ t('全部','All') }} ›</Link></header>
   <p v-if="marketsError" class="dp-discovery__status" role="status">{{ t('行情更新失败，已有报价可能过期。','Market refresh failed. Existing quotes may be stale.') }} <button :disabled="marketsLoading" @click="loadMarkets">{{ t('重试','Retry') }}</button></p>
   <div v-if="popular.length" class="dp-discovery__markets">
    <Link v-for="market in popular" :key="market.name" :href="route('market',market.name)" @click="$emit('close')">
     <span class="dp-discovery__pair"><currency-avatar :symbol="market.base_currency" :src="market.base_currency_logo"/><strong>{{ market.base_currency }}</strong><small>/ {{ market.quote_currency }}</small></span>
     <b class="dp-discovery__price">{{ number(market.last,market.quote_precision) }}</b><span :class="changeClass(market.change)">{{ change(market.change) }}</span>
     <small>{{ t('24h 成交额','24h turnover') }}<br>{{ turnover(market.qVolume) }} {{ market.quote_currency }}</small>
     <small v-if="quoteStale(market)" class="dp-discovery__quote-stale">{{ t('报价待更新','Quote awaiting update') }}</small>
    </Link>
   </div>
   <p v-else class="dp-discovery__empty" role="status">{{ marketsLoading?t('正在加载行情…','Loading markets…'):t('暂无可用热门行情','No popular markets available') }}</p>
  </section>
  <section class="dp-discovery__section" :aria-busy="newsLoading">
   <header><div><h2>{{ t('币圈资讯','Crypto news') }}</h2><small>{{ news.source?.name || '' }}</small></div><a v-if="newsSourceUrl" :href="newsSourceUrl" target="_blank" rel="noopener noreferrer" :aria-label="$t('News source')">RSS ↗</a></header>
   <p v-if="newsError || newsStale" class="dp-discovery__status" role="status">{{ newsItems.length?t('资讯更新延迟，以下为上次获取的内容。','News refresh is delayed. Showing the last available headlines.'):t('资讯暂不可用，请稍后重试。','News is unavailable. Please try again later.') }} <button :disabled="newsLoading" @click="loadNews">{{ t('重试','Retry') }}</button></p>
   <p v-if="news.fetchedAt" class="dp-discovery__fetched">{{ t('抓取于','Fetched') }} <time :datetime="news.fetchedAt">{{ time(news.fetchedAt) }}</time></p>
   <div class="dp-discovery__news"><a v-for="item in newsItems" :key="item.url" :href="item.url" target="_blank" rel="noopener noreferrer"><h3>{{ item.title }}</h3><p><span>{{ item.source }}</span><span>{{ t('发布于','Published') }} <time :datetime="item.publishedAt">{{ time(item.publishedAt) }}</time></span><b aria-hidden="true">↗</b></p></a></div>
   <p v-if="newsLoading && !newsItems.length" class="dp-discovery__empty" role="status">{{ t('正在加载资讯…','Loading news…') }}</p>
  </section>
 </div>
</template>
<script>
import axios from 'axios';
import ActionIcon from './ActionIcon.vue';
import {publicMarketRequest} from '@/Functions/PublicMarketRequests';
import {MARKET_LIST} from '@/Store/Mutations/Market';
import {numeric} from '@/Functions/MarketRanking.mjs';
import {popularMarkets,newsIsStale,safeNewsItems} from '@/Functions/DiscoveryFeed.mjs';
export default {
 components:{ActionIcon},
 data:()=>({serviceMessage:'',serviceTimer:null,alive:true,marketsLoading:false,marketsError:false,newsLoading:false,newsError:false,news:{items:[],fetchedAt:null,state:'loading'},now:Date.now(),lastMarketsStarted:0,lastNewsStarted:0,newsCancel:null,newsSequence:0}),
 watch:{'$i18n.locale'(){this.newsCancel?.cancel();this.newsLoading=false;this.news={items:[],state:'loading'};this.loadNews()}},
 computed:{
  services(){return [
   {label:this.t('现货交易','Spot trading'),icon:'trade',path:this.route('market',{market:this.$page.props.defaultTradePair||'BTC-USDT'})},
   {label:this.t('股票行情','Stocks'),icon:'pay',path:this.route('stocks')},
   {label:this.t('行情','Markets'),icon:'search',path:this.route('markets')},
   {label:'UMI',icon:'ecosystem',path:this.route('umi.portfolio')},
   {label:this.t('理财','Earn'),icon:'earn',path:this.route('stakings',{staking_type:0})},
   {label:this.t('充值 USDT','Deposit USDT'),icon:'deposit',path:this.route('wallets.deposit.crypto',{symbol:'USDT'})},
   {label:this.t('提现 USDT','Withdraw USDT'),icon:'withdraw',path:this.route('wallets.withdraw.crypto',{symbol:'USDT'})},
   {label:this.t('划转','Transfer'),icon:'transfer',path:this.route('wallets.transfer')},
   {label:this.t('邀请返佣','Referrals'),icon:'invite',path:this.route('reports.referral-transactions')},
   {label:this.t('公告','Announcements'),icon:'bell',path:this.route('articles')},
   {label:this.t('帮助中心','Help'),icon:'info',path:'/faq'},
   {label:this.t('在线客服','Support'),icon:'support',path:this.route('support')},
   {key:'pay',label:this.$t('Pay'),icon:'pay'},
   {key:'alerts',label:this.$t('Price alerts'),icon:'bell'},
   {key:'remittance',label:this.$t('Remittance'),icon:'transfer'},
   {key:'bots',label:this.$t('Trading bots'),icon:'settings'}
  ]},
  popular(){return popularMarkets(this.$store.getters.getMarkets)},
  newsSourceUrl(){const url=this.news.source?.url||'';return /^https:\/\/(cointelegraph\.com\/rss|www\.panewslab\.com\/rss\.xml\?lang=(zh|zh-hant|ja)&type=NEWS)$/.test(url)?url:null},
  newsItems(){return safeNewsItems(this.news.items,this.$i18n.locale)},
  newsStale(){return this.news.state!=='loading' && newsIsStale(this.news,this.now)}
 },
 mounted(){this.loadMarkets();this.loadNews();this.pollTimer=setInterval(this.refreshVisible,60000);document.addEventListener('visibilitychange',this.refreshVisible);window.addEventListener('focus',this.refreshVisible);window.addEventListener('online',this.refreshVisible)},
 beforeDestroy(){clearTimeout(this.serviceTimer);this.alive=false;clearInterval(this.pollTimer);this.newsCancel?.cancel();document.removeEventListener('visibilitychange',this.refreshVisible);window.removeEventListener('focus',this.refreshVisible);window.removeEventListener('online',this.refreshVisible)},
 methods:{
  activateService(item){if(item.path){this.$emit('close');return}this.serviceMessage=this.$t('This feature is not available yet');clearTimeout(this.serviceTimer);this.serviceTimer=setTimeout(()=>this.serviceMessage='',3000)},
  t(zh,en){return this.$t(en)},
  refreshVisible(){if(document.hidden||!this.alive)return;this.now=Date.now();if(this.now-this.lastMarketsStarted>=30000)this.loadMarkets();if(this.now-this.lastNewsStarted>=60000)this.loadNews()},
  async loadMarkets(){if(this.marketsLoading||!this.alive)return;this.marketsLoading=true;this.lastMarketsStarted=Date.now();try{const response=await publicMarketRequest(this.route('markets.api.ticker'));if(this.alive){if(!Array.isArray(response.data.data))throw new Error('invalid_ticker');this.$store.commit(MARKET_LIST,{markets:response.data.data});this.marketsError=false;this.now=Date.now()}}catch(_){if(this.alive)this.marketsError=true}finally{if(this.alive)this.marketsLoading=false}},
  async loadNews(){
   if(this.newsLoading||!this.alive)return;
   const sequence=++this.newsSequence,locale=this.$i18n.locale;
   this.newsLoading=true;this.lastNewsStarted=Date.now();this.newsCancel=axios.CancelToken.source();
   const current=()=>this.alive&&sequence===this.newsSequence&&locale===this.$i18n.locale;
   try{const response=await axios.get('/markets/data/crypto-news',{timeout:10000,cancelToken:this.newsCancel.token});if(current()){if(!Array.isArray(response.data.items)||!['fresh','stale','unavailable'].includes(response.data.state))throw new Error('invalid_news');this.news=response.data;this.newsError=false;this.now=Date.now()}}
   catch(error){if(current()&&!axios.isCancel(error))this.newsError=true}
   finally{if(current()){this.newsLoading=false;this.newsCancel=null}}
  },
  number(value,precision=8){const n=numeric(value);return n===null?'—':n.toLocaleString('en-US',{maximumFractionDigits:Math.max(2,Math.min(12,Number(precision)||8))})},
  turnover(value){return new Intl.NumberFormat(this.$i18n.locale,{notation:'compact',maximumFractionDigits:2}).format(Number(value))},
  change(value){const n=numeric(value);return n===null?'—':(n>0?'+':'')+n.toFixed(2)+'%'},
  changeClass(value){const n=numeric(value);return n>0?'is-up':n<0?'is-down':''},
  quoteStale(market){const stamp=Date.parse(market.updated_at||'');return market.price_stale===true||!Number.isFinite(stamp)||this.now-stamp>90000},
  time(value){const date=new Date(value);return Number.isFinite(date.getTime())?date.toLocaleString(this.$i18n.locale,{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',timeZoneName:'short'}):'—'}
 }
}
</script>
<style scoped>
.dp-discovery{padding:4px 0 22px;color:var(--ui-text,#161b24)}.dp-discovery a{color:inherit;text-decoration:none}.dp-discovery__services{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:22px 8px;padding:14px 0 28px}.dp-discovery__services>a,.dp-discovery__services>button{border:0;background:none;color:inherit;display:flex;flex-direction:column;align-items:center;gap:8px;min-height:64px;font-size:12px;text-align:center;overflow-wrap:anywhere}.dp-discovery__icon{width:48px;height:48px;border-radius:16px;background:var(--ui-highlight,#f6f7fa);display:flex;align-items:center;justify-content:center}.dp-discovery__icon svg{width:24px;height:24px}.dp-discovery__section{border-top:1px solid var(--ui-divider,#e9edf2);padding:22px 0 0;margin-bottom:22px;min-width:0}.dp-discovery__section>header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.dp-discovery h2{margin:0 0 6px;font-size:19px;font-weight:750}.dp-discovery header small,.dp-discovery__fetched{font-size:11px;color:var(--ui-muted,#747d8b)}.dp-discovery header>a{display:flex;align-items:center;min-height:44px;font-size:12px;white-space:nowrap}.dp-discovery__markets{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.dp-discovery__markets>a{display:flex;flex-direction:column;gap:8px;min-width:0;padding:14px 10px;border:1px solid var(--ui-divider,#e9edf2);border-radius:14px}.dp-discovery__pair{display:flex;align-items:center;flex-wrap:wrap;gap:4px}.dp-discovery__pair>span{width:20px;height:20px}.dp-discovery__pair strong{font-size:13px}.dp-discovery__pair small{font-size:10px;color:var(--ui-muted,#747d8b)}.dp-discovery__price{font-size:17px;overflow-wrap:anywhere}.dp-discovery__markets>a>span:not(.dp-discovery__pair){font-size:12px;font-weight:650}.dp-discovery__markets>a>small{font-size:10px;line-height:1.6;color:var(--ui-muted,#747d8b)}.dp-discovery .is-up{color:var(--dp-up,#0f9d83)}.dp-discovery .is-down{color:var(--dp-down,#e85065)}.dp-discovery__news>a{display:block;padding:16px 0;border-bottom:1px solid var(--ui-divider,#e9edf2)}.dp-discovery h3{font-size:14px;font-weight:650;line-height:1.65;margin:0;overflow-wrap:anywhere}.dp-discovery__news p{display:flex;flex-wrap:wrap;align-items:center;gap:5px 12px;font-size:11px;color:var(--ui-muted,#747d8b);margin:8px 0 0}.dp-discovery__news p>b{margin-left:auto}.dp-discovery__status,.dp-discovery__empty{font-size:12px;line-height:1.7;color:var(--ui-muted,#747d8b)}.dp-discovery__status button{background:transparent;border:0;color:var(--ui-text,#161b24);min-height:44px;padding:4px 8px;font-size:12px}.dp-discovery a:focus-visible,.dp-discovery button:focus-visible{outline:2px solid var(--ui-accent,#dab523);outline-offset:3px}.dp-discovery__fetched{line-height:1.6;margin:0 0 4px}@media(max-width:360px){.dp-discovery__markets{gap:6px}.dp-discovery__markets>a{padding:12px 7px}.dp-discovery__price{font-size:15px}.dp-discovery__pair{gap:3px}.dp-discovery__pair strong{font-size:12px}.dp-discovery__pair small{font-size:9px}}
</style>
