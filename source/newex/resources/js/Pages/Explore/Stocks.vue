<template>
<app-layout>
 <Head :title="stock ? stock.symbol+' · Deepro' : $t('Stock Tokens')+' · Deepro'" />
 <div v-if="!stock" class="dp-markets-page"><market-overview initial-category="stocks" :stock-assets="stocks"/><market-channel/></div>
 <div v-else class="deepro-stocks umi-markets"><Link :href="route('stocks')">← {{ $t('Stock Tokens') }}</Link><stock-asset-info :asset="stock" default-open/><p>{{ $t('Trading is currently paused for this pair.') }}</p></div>
</app-layout>
</template>
<script>
import AppLayout from '@/Layouts/AppLayout';
import StockAssetInfo from '@/Components/StockAssetInfo.vue';
import MarketOverview from '@/Components/MarketOverview.vue';
import MarketChannel from '@/Store/Channels/Public/Market/MarketChannel';
export default {
 components:{AppLayout,StockAssetInfo,MarketOverview,MarketChannel},
 props:{stocks:{type:Array,default:()=>[]},selectedSymbol:String},
 computed:{stock(){return this.stocks.find(s=>s.id===this.selectedSymbol)}}
}
</script>
