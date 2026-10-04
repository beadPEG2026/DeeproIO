<template>
<section class="dp-market-overview" :class="{'is-compact':compact,'dp-market-hub':!compact}" :aria-label="$t('Markets')">
 <template v-if="!compact">
  <header class="dp-hub-header">
   <nav :aria-label="$t('Category')"><button v-for="tab in categories" :key="tab.key" :aria-pressed="category===tab.key" @click="chooseCategory(tab.key)">{{ $t(tab.label) }}</button></nav>
   <button class="dp-hub-search-toggle" :aria-label="$t('Search')" :aria-expanded="toolsOpen" @click="toolsOpen=!toolsOpen">⌕</button>
  </header>
  <global-market-cards v-if="category==='crypto' && presentation.firstScreen!==false" :enabled="presentation"/>
  <nav class="dp-hub-tabs" :aria-label="$t('Market rankings')">
   <template v-if="category==='crypto'"><button :aria-pressed="!overview && activeProduct==='spot' && rank==='hot'" @click="chooseProduct('spot')">{{ $t('Spot') }}</button><button :aria-pressed="!overview && activeProduct==='futures' && rank==='hot'" @click="chooseProduct('futures')">{{ $t('Futures') }}</button><button v-for="tab in [{key:'new',label:'Newly listed'},{key:'gainers',label:'Top Gainers'},{key:'losers',label:'Top Losers'}]" :key="tab.key" :aria-pressed="!overview && rank===tab.key" @click="chooseRank(tab.key)">{{ $t(tab.label) }}</button></template>
   <template v-else-if="category==='stocks'"><button v-for="tab in [{key:'all',label:'全部',en:'All'},{key:'US',label:'美股',en:'US'},{key:'HK',label:'港股',en:'Hong Kong'},{key:'favorites',label:'自选',en:'Watchlist'}]" :key="tab.key" :aria-pressed="stockRegion===tab.key" @click="stockRegion=tab.key;chooseRank('hot')">{{ $t(tab.en) }}</button></template>
   <template v-else><button v-for="tab in [{key:'all',label:'All'},{key:'crypto',label:'Digital currencies'},{key:'stocks',label:'Stock Tokens'}]" :key="tab.key" :aria-pressed="favoriteScope===tab.key" @click="favoriteScope=tab.key">{{ $t(tab.label) }}</button><button class="dp-watch-edit" :aria-pressed="editing" @click="editing=!editing">{{ $t(editing?'Done':'Edit watchlist') }}</button></template>
  </nav>
  <div v-show="toolsOpen" class="dp-hub-tools">
   <input v-model.trim="search" type="search" :aria-label="$t('Search Coin Name')" :placeholder="$t('Search name or symbol')">
   <button :aria-expanded="filtersOpen" @click="filtersOpen=!filtersOpen">{{ $t('Market filters') }}</button>
  </div>
  <div v-if="filtersOpen && toolsOpen" class="dp-ranking-filters">
   <label v-if="category==='stocks'">{{ $t('Category') }}<themed-select v-model="stockType"><option value="all">{{ $t('All') }}</option><option value="stock">{{ $t('Stock Tokens') }}</option><option value="etf">{{ $t('ETF Tokens') }}</option></themed-select></label>
   <label>{{ $t('Quote currency') }}<themed-select v-model="quote"><option value="">{{ $t('All') }}</option><option v-for="q in quotes" :key="q">{{ q }}</option></themed-select></label>
   <label>{{ $t('Sort') }}<themed-select v-model="rank" @change="overview=false;refreshOrder()"><option value="hot">{{ $t('Hot markets') }}</option><option value="new">{{ $t('Newly listed') }}</option><option value="gainers">{{ $t('Top Gainers') }}</option><option value="losers">{{ $t('Top Losers') }}</option></themed-select></label>
   <button @click="resetFilters">{{ $t('Reset filters') }}</button>
  </div>
  <div v-if="category==='stocks' && stockRegion!=='HK' && presentation.benchmarks!==false" class="dp-etf-benchmarks">
   <Link v-for="m in benchmarks" :key="m.name" :href="marketUrl(m)" @click="rememberState"><span>{{ $t(m.base_currency==='SPYon'?'S&P 500 ETF token':'Nasdaq 100 ETF token') }}</span><b>{{ number(m.last,m.quote_precision) }} <em>{{ m.quote_currency }}</em></b><small :class="changeClass(m.change)">{{ change(m.change) }} <span>· {{ m.base_currency }}</span></small></Link>

  </div>

 </template>
 <header v-if="compact" class="dp-ranking-toolbar"><nav class="dp-ranking-tabs" :aria-label="$t('Market rankings')"><button v-for="tab in tabs" :key="tab.key" :aria-pressed="rank===tab.key" @click="chooseRank(tab.key)">{{ $t(tab.label) }}</button></nav></header>
 <template>
  <div v-if="sectorGroup" class="dp-hub-active-filter">{{ sectorGroup }} <button @click="sectorGroup=''">{{ $t('Reset filters') }} ×</button></div>
  <div class="dp-ranking-table-wrap"><table class="dp-ranking-table">
   <thead><tr><th><button v-if="!compact" @click="sortBy(stockOnly?'name':'qVolume')">{{ $t(stockOnly?'Name / symbol':'Pair') }}<small v-if="!stockOnly">{{ $t('24h Turnover') }} ↕</small></button><template v-else>{{ $t('Pair') }}</template></th><th v-if="!compact" class="dp-hub-spark" :aria-label="$t('Chart')"></th><th><button v-if="!compact" @click="sortBy('last')">{{ $t('Last Price') }}<small>{{ stockOnly ? (stockRegion === 'HK' ? 'HKD' : $t('Currency')) : (quote || $t('Quote currency')) }} ↕</small></button><template v-else>{{ $t('Last Price') }}</template></th><th><button v-if="!compact" @click="sortBy('change')">{{ $t(stockOnly && stockRegion==='HK'?'Session change':'Market 24h change') }} ↕</button><template v-else>{{ $t('24 Change') }}</template></th><th class="dp-ranking-extra">{{ $t('24 High') }}</th><th class="dp-ranking-extra">{{ $t('24 Low') }}</th><th v-if="!stockOnly" class="dp-ranking-extra">{{ $t('24h Turnover') }}<small>{{ quote || $t('Quote currency') }}</small></th><th class="dp-ranking-extra">{{ $t('Actions') }}</th></tr></thead>
   <tbody><tr v-for="(market,index) in rows" :key="market.name" :data-market="market.name" @click="openMarket($event,market)">
    <td><div class="dp-ranking-pair"><span v-if="!compact && ['gainers','losers'].includes(rank)" :class="['dp-rank-number',{'is-medal':index<3}]">{{ index+1 }}</span><button v-if="compact || category!=='favorites' || editing" class="dp-ranking-star" :aria-label="$t(favorites.includes(market.name)?'Remove from watchlist':'Add to watchlist')+' '+market.name" :aria-pressed="favorites.includes(market.name)" @click.stop="toggleFavorite(market.name)">{{ favorites.includes(market.name)?'★':'☆' }}</button><Link :href="marketUrl(market)" class="dp-ranking-identity" @click="rememberState"><currency-avatar :symbol="market.base_currency" :src="market.base_currency_logo"/><span><strong>{{ assetName(market) }}<small v-if="!market.asset && !compact"> / {{ market.quote_currency }}</small></strong><small v-if="market.asset">{{ market.base_currency }} · {{ $t(market.asset.assetType==='etf'?'ETF Tokens':'Stock Tokens') }}</small><small v-else-if="!compact && numeric(market.qVolume)!==null">{{ compactNumber(market.qVolume) }} {{ market.quote_currency }}</small><small v-else>{{ market.base_currency_name }}</small><small v-if="!isEnabled(market.trade_status)" class="dp-ranking-state">{{ $t('Trading paused') }}</small></span></Link></div></td>
    <td v-if="!compact" class="dp-hub-spark"><svg v-if="!compact && paths(market.name).length" class="dp-sparkline" viewBox="0 0 92 36" aria-hidden="true"><polyline v-for="(path,i) in paths(market.name)" :key="i" :points="path" fill="none" stroke="currentColor" stroke-width="1.4" :class="changeClass(market.change)"/></svg></td>
    <td class="dp-hub-price-cell"><Link :href="marketUrl(market)" class="dp-hub-price" :class="{'is-hk':market.displayCurrency==='HKD'}" @click="rememberState"><span>{{ number(market.last,market.quote_precision) }}<small v-if="!market.displayCurrency" class="dp-pair-quote">{{market.quote_currency}}</small><small v-if="market.displayCurrency" class="dp-hk-price-currency">{{ market.displayCurrency }} · {{ $t(market.quoteClosed ? 'Closing price' : 'Last Price') }}</small><small v-if="market.approximateUsdt" class="dp-usdt-equivalent">（≈ {{ formatUsdt(market.approximateUsdt) }} USDT）</small></span></Link></td>
    <td><Link :href="marketUrl(market)" class="dp-ranking-change" :class="changeClass(market.change)" @click="rememberState">{{ change(market.change) }}</Link></td>
    <td class="dp-ranking-extra">{{ number(market.high) }}</td><td class="dp-ranking-extra">{{ number(market.low) }}</td><td v-if="!stockOnly" class="dp-ranking-extra">{{ market.stock_token?'—':compactNumber(market.qVolume) }} {{market.quote_currency}}</td><td class="dp-ranking-extra"><Link :href="marketUrl(market)" class="dp-ranking-trade" @click="rememberState">{{ $t('Trade') }} →</Link></td>
   </tr></tbody>
  </table></div>
  <div v-if="!rows.length" class="dp-ranking-empty" role="status"><template v-if="loading">{{ $t('Loading markets') }}</template><template v-else-if="loadError">{{ $t('Unable to load markets') }} <button @click="loadMarkets">{{ $t('Retry') }}</button></template><template v-else-if="category==='favorites' || rank==='favorites'">{{ $t('Your watchlist is empty') }} <button @click="chooseCategory('crypto');editing=true">{{ $t('Add to watchlist') }}</button></template><template v-else>{{ $t(rank==='gainers'?'No gaining markets':rank==='losers'?'No declining markets':'No markets found.') }}</template></div>
 </template>
 <stock-news v-if="!compact && category==='stocks'" :market-region="stockRegion"/>
 <footer class="dp-ranking-footer"><span v-if="!compact">{{ $t('Data updates in real time · See Spot / Futures / Stocks for details') }}</span><Link v-else :href="route('markets')">{{ $t('View all markets') }} →</Link></footer>
 <details v-if="!compact && category==='stocks'" class="dp-hub-disclosure"><summary>{{ $t('Asset information') }}</summary><Link v-for="m in rows" :key="m.name" :href="marketUrl(m)+'?asset=info'">{{ m.base_currency }} · {{ m.asset && m.asset.issuer }} ↗</Link></details>
 <p v-if="storageError" class="dp-ranking-feedback" role="status">{{ $t('Watchlist saved for this visit only') }}</p>
</section>
</template>
<script>
import axios from 'axios';
import {publicMarketRequest} from '@/Functions/PublicMarketRequests';
import {formatUsdt} from '@/Functions/MarketDisplay.mjs';
import {MARKET_LIST} from '@/Store/Mutations/Market';
import MarketDisplay from '@/Mixins/Market/MarketDisplay.vue';
import GlobalMarketCards from '@/Components/GlobalMarketCards.vue';
import StockNews from '@/Components/StockNews.vue';
import {pinHongKongMarket} from '@/Functions/DisplayPreferences.mjs';
import {numeric,enabled,rankedMarkets,stableMarketOrder,visibleMarkets} from '@/Functions/MarketRanking.mjs';
import {loadWatchlist,saveWatchlist,filterDiscovery,assetRows,sortRows,sparkPaths,GROUPS} from '@/Functions/MarketDiscovery.mjs';
export default {
 components:{GlobalMarketCards,StockNews},mixins:[MarketDisplay],
 props:{compact:Boolean,product:{type:String,default:'spot'},initialCategory:{type:String,default:'crypto'},stockAssets:{type:Array,default:()=>[]}},
 data:()=>({category:'crypto',activeProduct:'spot',rank:'hot',quote:'',stockType:'all',stockRegion:'all',favoriteScope:'all',search:'',toolsOpen:false,filtersOpen:false,editing:false,overview:false,sectorGroup:'',sortField:'',sortDirection:-1,favorites:[],order:[],assets:[],loading:false,loadError:false,storageError:false,alive:true,sparks:{},observer:null,sparkQueue:new Set(),sparkBusy:false,sparkTimer:null,sparkRefresh:null,restoreScroll:null,requestSource:axios.CancelToken.source(),ready:false,presentation:{},hadStoredState:false}),
 computed:{
  tabs:()=>[{key:'favorites',label:'My watchlist'},{key:'hot',label:'Hot markets'},{key:'gainers',label:'Top Gainers'},{key:'losers',label:'Top Losers'},{key:'new',label:'Newly listed'}],
  categories(){return [{key:'crypto',label:'Digital currencies'},{key:'stocks',label:'Stocks'},{key:'favorites',label:'My watchlist'}]},
  stockOnly(){return !this.compact&&(this.category==='stocks'||(this.category==='favorites'&&this.favoriteScope==='stocks'))},
  enriched(){return assetRows(this.displayMarkets,this.assets)},
  available(){return visibleMarkets(this.enriched,this.activeProduct)},
  quotes(){return [...new Set(this.available.map(m=>m.quote_currency))].sort()},
  benchmarks(){return this.enriched.filter(m=>['SPYon','QQQon'].includes(m.base_currency)&&m.status)},
  candidates(){let rows=this.compact?this.available:filterDiscovery(this.available,{category:this.category==='favorites'?this.favoriteScope:this.category,stockType:this.stockType,stockRegion:this.stockRegion,search:this.search}); if(this.category==='stocks'&&this.stockRegion==='favorites')rows=rows.filter(m=>this.favorites.includes(m.name));if(this.sectorGroup){const g=(this.presentation.sectors||GROUPS).find(g=>g.name===this.sectorGroup);if(g)rows=rows.filter(m=>g.symbols.includes(m.base_currency))}rows=rankedMarkets(rows,{rank:this.category==='favorites'?'favorites':this.rank,product:this.activeProduct,quote:this.quote,search:this.compact?this.search:'',favorites:this.favorites});return this.sortField?sortRows(rows,this.sortField,this.sortDirection):rows},
  rows(){const rows=pinHongKongMarket(stableMarketOrder(this.candidates,this.order),this.category==='stocks'?this.stockRegion:null);return this.compact?rows.slice(0,6):rows},
  rowKeys(){return this.rows.map(m=>m.name).join('|')},
  metadataKey(){return this.available.map(m=>m.name).sort().join('|')},
  stateKey(){return JSON.stringify([this.category,this.activeProduct,this.rank,this.quote,this.stockType,this.stockRegion,this.favoriteScope,this.search,this.sortField,this.sortDirection,this.overview,this.sectorGroup])}
 },
 watch:{metadataKey(){if(this.quotes.length&&!this.quotes.includes(this.quote))this.quote=this.quotes.includes('USDT')?'USDT':this.quotes[0];this.refreshOrder()},product(v){this.activeProduct=v},stateKey(){this.refreshOrder();if(this.ready)this.rememberState(false)},rowKeys(){this.$nextTick(this.observeRows)}},
 mounted(){this.activeProduct=this.product;this.category=this.initialCategory;this.assets=this.stockAssets;this.restoreState();this.syncFavorites();this.loadMarkets();window.addEventListener('storage',this.syncFavorites);window.addEventListener('deepro:watchlist',this.syncFavorites);if(!this.compact)document.documentElement.classList.add('dp-market-view');window.addEventListener('beforeunload',this.cancelRequests);window.addEventListener('pageshow',this.resumePage);this.ready=true;if(!this.compact)this.sparkRefresh=setInterval(()=>{if(!document.hidden){this.sparks={};this.observeRows()}},300000)},
 beforeDestroy(){this.cancelRequests();window.removeEventListener('beforeunload',this.cancelRequests);window.removeEventListener('pageshow',this.resumePage);window.removeEventListener('storage',this.syncFavorites);window.removeEventListener('deepro:watchlist',this.syncFavorites);clearTimeout(this.sparkTimer);clearInterval(this.sparkRefresh);this.observer?.disconnect();if(!this.compact)document.documentElement.classList.remove('dp-market-view')},
 methods:{numeric,formatUsdt,isEnabled:enabled,
  cancelRequests(){this.alive=false;this.requestSource.cancel()},
  resumePage(e){if(e.persisted){this.alive=true;this.requestSource=axios.CancelToken.source();this.loadMarkets();this.observeRows()}},
  syncFavorites(){try{this.favorites=loadWatchlist(localStorage);window.marketFavorites=[...this.favorites]}catch(_){this.storageError=true}},
  toggleFavorite(name){this.favorites=this.favorites.includes(name)?this.favorites.filter(n=>n!==name):[...this.favorites,name];window.marketFavorites=[...this.favorites];try{saveWatchlist(localStorage,this.favorites,this.assets.map(a=>a.symbol));this.storageError=false;window.dispatchEvent(new Event('deepro:watchlist'))}catch(_){this.storageError=true}if(this.category==='favorites'||this.rank==='favorites')this.refreshOrder()},
  async loadMarkets(){if(this.loading)return;this.loading=true;this.loadError=false;try{const [ticker,catalog]=await Promise.allSettled([publicMarketRequest(this.route('markets.api.ticker')),publicMarketRequest('/markets/data/catalog',30000)]);if(!this.alive)return;if(catalog.status==='fulfilled'){this.assets=catalog.value.data.assets||[];this.presentation=catalog.value.data.presentation||{};if(!this.compact&&!this.hadStoredState&&this.initialCategory==='crypto'){this.category=this.presentation.defaultCategory||'crypto';this.rank=this.presentation.defaultList||'hot';}}if(ticker.status!=='fulfilled')throw new Error('ticker_unavailable');this.$store.commit(MARKET_LIST,{markets:ticker.value.data.data});this.refreshOrder();this.$nextTick(()=>{if(this.restoreScroll!==null){window.scrollTo(0,this.restoreScroll);this.restoreScroll=null}this.observeRows()})}catch(_){this.loadError=true}finally{this.loading=false}},
  chooseCategory(k){this.category=k;this.overview=false;this.activeProduct='spot';this.rank='hot';this.sectorGroup='';this.sortField='';this.stockType='all';this.stockRegion='all';this.editing=false},
  chooseProduct(p){this.activeProduct=p;this.chooseRank('hot')},chooseRank(k){this.rank=k;this.overview=false;this.sortField='';this.refreshOrder()},
  refreshOrder(){this.order=this.candidates.map(m=>m.name)},sortBy(field){this.sortDirection=this.sortField===field?-this.sortDirection:-1;this.sortField=field;this.refreshOrder()},
  resetFilters(){this.search='';this.quote='';this.sectorGroup='';this.stockType='all';this.sortField='';this.rank='hot'},
  assetName(m){if(!m.asset)return m.base_currency;if(this.$i18n.locale.startsWith('zh'))return this.$t(m.asset.name);return {AAPLon:'Apple',GOOGLon:'Alphabet',TSLAon:'Tesla',NVDAon:'NVIDIA',QQQon:'Invesco QQQ',CRCLon:'Circle',METAon:'Meta',AMZNon:'Amazon',SPYon:'SPDR S&P 500',MSFTon:'Microsoft'}[m.base_currency]||m.base_currency},
  marketUrl(m){if(m.asset&&!m.asset.tradeEnabled&&m.asset.instrumentType!=='equity_price_reference')return this.route('stocks.show',m.asset.id);return this.route(this.activeProduct==='futures'?'futures-market':this.activeProduct==='options'?'options-market':'market',m.name)},
  openMarket(e,m){if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||e.target.closest('a,button,input,select,textarea')||window.getSelection()?.toString())return;this.rememberState();this.$inertia.visit(this.marketUrl(m))},
  number(v,p=8){const n=numeric(v);return n===null?'—':n.toLocaleString('en-US',{maximumFractionDigits:Math.max(2,Math.min(12,Number(p)||8))})},
  compactNumber(v){const n=numeric(v);return n===null?'—':new Intl.NumberFormat(this.$i18n.locale,{notation:'compact',maximumFractionDigits:2}).format(n)},
  change(v){const n=numeric(v);return n===null?'—':(n>0?'+':'')+n.toFixed(2)+'%'},changeClass(v){const n=numeric(v);return n>0?'is-up':n<0?'is-down':''},paths(name){return sparkPaths(this.sparks[name]?.points||[],this.sparks[name]?.resolution==='1D')},
  observeRows(){if(this.compact||!this.alive||this.presentation.sparklines===false)return;this.observer?.disconnect();this.observer=new IntersectionObserver(entries=>{for(const e of entries)if(e.isIntersecting){const name=e.target.dataset.market;if(!this.sparks[name])this.sparkQueue.add(name)}clearTimeout(this.sparkTimer);this.sparkTimer=setTimeout(this.fetchSparks,150)},{rootMargin:'80px'});this.$el.querySelectorAll('tr[data-market]').forEach(el=>this.observer.observe(el))},
  async fetchSparks(){if(this.sparkBusy||!this.alive||!this.sparkQueue.size)return;const names=[...this.sparkQueue].slice(0,2);names.forEach(n=>{this.sparkQueue.delete(n);this.$set(this.sparks,n,{points:[],loading:true})});this.sparkBusy=true;try{const r=await axios.get('/markets/data/sparklines',{params:{markets:names},timeout:55000,cancelToken:this.requestSource.token});if(this.alive)for(const name of names)this.$set(this.sparks,name,r.data.data[name]||{points:[]})}catch(_){names.forEach(n=>this.$set(this.sparks,n,{points:[]}))}finally{this.sparkBusy=false;if(this.alive&&this.sparkQueue.size)this.sparkTimer=setTimeout(this.fetchSparks,200)}},
  rememberState(scroll=true){if(this.compact)return;try{sessionStorage.setItem('deepro.marketView.v2:'+location.pathname,JSON.stringify({category:this.category,activeProduct:this.activeProduct,rank:this.rank,quote:this.quote,stockType:this.stockType,stockRegion:this.stockRegion,favoriteScope:this.favoriteScope,search:this.search,sortField:this.sortField,sortDirection:this.sortDirection,overview:this.overview,sectorGroup:this.sectorGroup,toolsOpen:this.toolsOpen,scroll:scroll?window.scrollY:0}))}catch(_){}},
  restoreState(){if(this.compact)return;try{const s=JSON.parse(sessionStorage.getItem('deepro.marketView.v2:'+location.pathname)||'null');if(!s)return;this.hadStoredState=true;for(const k of ['category','activeProduct','rank','quote','stockType','stockRegion','favoriteScope','search','sortField','sortDirection','sectorGroup','toolsOpen'])if(k in s)this[k]=s[k];this.restoreScroll=Math.max(0,Number(s.scroll)||0)}catch(_){}}
 }
}
</script>
<style scoped>
.dp-pair-quote{display:block;font-size:10px;color:var(--ui-text-muted,#68716c)}
.dp-ranking-identity strong{white-space:normal!important;overflow:visible!important;text-overflow:clip!important;overflow-wrap:anywhere}

.dp-ranking-footer{display:flex;justify-content:center;text-align:center;width:100%;padding:18px 8px;line-height:1.6}

@media (max-width:360px) {
 .dp-market-overview.dp-market-hub .dp-ranking-table td:first-child {padding-left:4px;padding-right:4px}
 .dp-market-overview.dp-market-hub .dp-ranking-identity {gap:3px}
 .dp-market-overview.dp-market-hub .dp-ranking-identity>.currency-avatar {width:20px;height:20px;min-width:20px;flex:0 0 20px}
 .dp-market-overview.dp-market-hub .dp-rank-number {width:12px;flex:0 0 12px;font-size:9px}
}
</style>
