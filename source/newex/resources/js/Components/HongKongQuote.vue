<template>
  <div class="dp-hk-quote">
    <span>{{ $t('Stock price') }} <b>HKD</b></span>
    <strong>{{ quote ? Number(quote.underlyingPrice).toFixed(3) : '—' }}</strong>
    <small v-if="equivalent" class="dp-usdt-equivalent">（≈ {{ equivalent }} USDT）</small>
    <time v-if="quote" :datetime="quote.sourceTime">{{ timeLabel }} · {{ $t('Hong Kong time') }}</time>
    <span v-else>{{ $t('No data') }}</span>
  </div>
</template>
<script>
import {state,subscribeStockQuotes,unsubscribeStockQuotes} from '@/Functions/StockQuotes';
import {displayMarket,formatUsdt} from '@/Functions/MarketDisplay.mjs';
export default {
  props:{symbol:String},
  computed:{
    display(){return displayMarket({base_currency:this.symbol,price_reference_product:true},state.quotes,state.now,state.fx)},
    quote(){return this.display.displayCurrency==='HKD'?state.quotes[this.symbol]:null},
    equivalent(){return formatUsdt(this.display.approximateUsdt)},
    timeLabel(){return new Intl.DateTimeFormat(this.$i18n.locale,{timeZone:'Asia/Hong_Kong',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date(this.quote.sourceTime));}
  },mounted(){subscribeStockQuotes()},beforeDestroy(){unsubscribeStockQuotes()}
};
</script>
