<template><Link v-if="market" :href="route('market',{market,side})" class="dp-trade-funding-return">‹ {{$t('Return to trading')}} · {{market.replace('-', '/')}}</Link></template>
<script>
import {tradeReturnMarket} from '@/Functions/TradeFormDraft.mjs';
export default {computed:{market(){return tradeReturnMarket(this.$page.url.split('?')[1]||'')},side(){return new URLSearchParams(this.$page.url.split('?')[1]||'').get('side')==='sell'?'sell':'buy'}}};
</script>
