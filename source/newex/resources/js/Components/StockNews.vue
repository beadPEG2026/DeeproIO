<template>
 <section class="dp-stock-news" :aria-label="zh?'股票资讯':'Stock news'" :aria-busy="loading">
  <header><h2>{{ zh?'股票资讯':'Stock news' }}</h2><span>{{ region==='HK'?(zh?'港股':'Hong Kong'):(zh?'美股':'US stocks') }}</span><small v-if="updatedAt">{{ zh?'更新于':'Updated' }} {{ clock(updatedAt) }}</small></header>
  <nav v-if="selectable" :aria-label="zh?'资讯市场':'News market'"><button v-for="r in ['US','HK']" :key="r" :class="{active:region===r}" :aria-pressed="region===r" type="button" @click="choose(r)">{{ r==='HK'?(zh?'港股':'Hong Kong'):(zh?'美股':'US stocks') }}</button></nav>
  <p v-if="error || stale" class="dp-stock-news__status" role="status">{{ error ? (zh?'资讯更新失败。':'News refresh failed.') : (zh?'资讯尚未更新。':'News has not refreshed yet.') }} {{ items.length ? (zh?'以下为上次获取的资讯。':'Showing the last available news.') : '' }} <button type="button" :disabled="loading" @click="load">{{ loading?(zh?'更新中…':'Refreshing…'):(zh?'重试':'Retry') }}</button></p>
  <a v-for="item in items" :key="item.url" :href="item.url" target="_blank" rel="noopener noreferrer"><h3>{{ item.title }}</h3><p>{{ item.source }}<time :datetime="item.publishedAt">{{ formatted(item.publishedAt) }}</time><span aria-hidden="true">↗</span></p></a>
  <p v-if="!items.length && !error" class="dp-stock-news__empty">{{ loading?(zh?'正在加载资讯…':'Loading news…'):(zh?'暂时没有资讯':'No news available') }}<button v-if="!loading" @click="load">{{ zh?'刷新':'Refresh' }}</button></p>
 </section>
</template>
<script>
import axios from 'axios';
// The server refreshes RSS every five minutes; allow one polling interval for
// scheduler latency and the upstream request before marking the cache stale.
const SOURCE_REFRESH_MS = 300000;
const CACHE_POLL_MS = 60000;
const STALE_AFTER_MS = SOURCE_REFRESH_MS + CACHE_POLL_MS;
export default {
 props:{marketRegion:{type:String,default:'all'}},data:()=>({region:'US',items:[],updatedAt:null,loading:false,error:false,now:Date.now(),serial:0,alive:true}),
 computed:{stale(){return !!this.updatedAt && this.now-new Date(this.updatedAt).getTime()>STALE_AFTER_MS},zh(){return String(this.$i18n?.locale||'zh').startsWith('zh')},selectable(){return !['US','HK'].includes(this.marketRegion)}},
 watch:{marketRegion(){this.choose(this.marketRegion==='HK'?'HK':'US')}},
 mounted(){
  this.region=this.marketRegion==='HK'?'HK':'US';this.load();
  this.pollTimer=setInterval(()=>{this.now=Date.now();this.refreshVisible()},CACHE_POLL_MS);
  document.addEventListener('visibilitychange',this.refreshVisible);window.addEventListener('focus',this.refreshVisible);
 },
 beforeDestroy(){this.alive=false;this.serial++;clearInterval(this.pollTimer);this.cancel?.();document.removeEventListener('visibilitychange',this.refreshVisible);window.removeEventListener('focus',this.refreshVisible)},
 methods:{
  choose(region){if(region===this.region)return;this.cancel?.();this.serial++;this.loading=false;this.region=region;this.items=[];this.updatedAt=null;this.error=false;this.load()},
  refreshVisible(){if(!document.hidden && Date.now()-(this.lastStarted||0)>5000)this.load()},
  async load(){
   if(this.loading||!this.alive)return;
   const serial=++this.serial;this.loading=true;this.lastStarted=Date.now();
   const cancel=axios.CancelToken.source();this.cancel=cancel.cancel;
   try{const response=await axios.get('/markets/data/news',{params:{region:this.region},timeout:15000,cancelToken:cancel.token});
    if(this.alive&&serial===this.serial){this.items=response.data.items||[];this.updatedAt=response.data.updatedAt||new Date().toISOString();this.error=false;this.now=Date.now()}
   }catch(error){if(this.alive&&serial===this.serial&&!axios.isCancel(error))this.error=true}finally{if(this.alive&&serial===this.serial){this.loading=false;this.cancel=null}}
  },
  clock(value){return new Date(value).toLocaleString(this.$i18n.locale||'en',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',timeZoneName:'short'})},
  formatted(value){return new Date(value).toLocaleString(this.zh?'zh-CN':'en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}
 }
}
</script>
<style scoped>
.dp-stock-news__status{font-size:13px;line-height:1.6;color:var(--ui-text,#111820)}.dp-stock-news__status button{border:1px solid var(--ui-divider,#ddd);border-radius:6px;background:transparent;color:inherit;min-height:36px;padding:4px 12px;margin-left:8px}.dp-stock-news header{flex-wrap:wrap}.dp-stock-news header small{margin-left:auto;color:var(--ui-muted,#808793);font-size:11px;font-weight:400}
.dp-stock-news{padding:22px 0 8px;margin:18px 0;border-top:1px solid var(--ui-divider,#edf0f4)}.dp-stock-news header{display:flex;align-items:center;gap:10px;margin-bottom:14px}.dp-stock-news h2{font-size:19px;font-weight:800;margin:0;color:var(--ui-text,#111820)}.dp-stock-news header>span{font-size:12px;color:var(--ui-muted,#808793)}.dp-stock-news nav{display:flex;gap:8px;margin-bottom:8px}.dp-stock-news nav button{border:0;border-radius:6px;padding:7px 14px;background:var(--ui-highlight,#f5f7fa);color:var(--ui-muted,#808793)}.dp-stock-news nav button.active{background:#fff2ba;color:#372f09;font-weight:700}.dp-stock-news>a{display:block;padding:16px 0;border-bottom:1px solid var(--ui-divider,#edf0f4)}.dp-stock-news h3{margin:0;font-size:14px;font-weight:650;line-height:1.65;color:var(--ui-text,#111820)}.dp-stock-news a p{display:flex;gap:12px;margin:7px 0 0;font-size:11px;color:var(--ui-muted,#808793)}.dp-stock-news a p>span{margin-left:auto}.dp-stock-news__empty{padding:24px 0;color:var(--ui-muted,#808793);font-size:13px}.dp-stock-news__empty button{background:none;border:0;color:var(--ui-text,#111820);margin-left:10px}
@media(max-width:600px){.dp-stock-news header small{flex-basis:100%;margin-left:0;white-space:normal;overflow-wrap:anywhere}.dp-stock-news a p{flex-wrap:wrap}.dp-stock-news h3{overflow-wrap:anywhere}}
</style>
