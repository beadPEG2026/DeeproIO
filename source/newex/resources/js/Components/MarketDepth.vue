<template>
<div class="dp-depth">
  <template v-if="bids.length || asks.length">
    <div class="dp-depth__legend"><span class="color-buy">{{ $t('Buy') }} · {{ market.base_currency }}</span><span class="color-sell">{{ $t('Sell') }} · {{ market.base_currency }}</span></div>
    <svg viewBox="0 0 600 220" role="img" :aria-label="$t('Depth')">
      <path d="M12 40H588M12 95H588M12 150H588M12 205H588" class="dp-depth__grid" />
      <path v-if="bids.length" :d="area(bids)" class="dp-depth__bid" />
      <path v-if="asks.length" :d="area(asks)" class="dp-depth__ask" />
    </svg>
    <div class="dp-depth__axis"><span>{{ price(minPrice) }}</span><span>{{ market.quote_currency }}</span><span>{{ price(maxPrice) }}</span></div>
    <p>{{ $t('Cumulative quantity') }} · {{ market.base_currency }}</p>
  </template>
  <p v-else class="dp-empty">{{ $t('No data') }}</p>
</div>
</template>
<script>
import {depthSeries} from '@/Functions/TradingDisplay.mjs';
export default {
 props: {market: Object},
 computed: {
  bids() {return depthSeries(this.$store.getters.getOrderbook(this.market.name, 'bids'), 'bids');},
  asks() {return depthSeries(this.$store.getters.getOrderbook(this.market.name, 'asks'), 'asks');},
  points() {return [...this.bids, ...this.asks];},
  minPrice() {return Math.min(...this.points.map(p => p.price));},
  maxPrice() {return Math.max(...this.points.map(p => p.price));},
  maxQuantity() {return Math.max(1e-12, ...this.points.map(p => p.cumulative));}
 },
 methods: {
  price(value) {return value.toLocaleString(this.$i18n.locale, {maximumFractionDigits: Math.min(12, this.market.quote_precision)});},
  area(rows) {
   const x = p => this.maxPrice === this.minPrice ? 300 : 12 + (p-this.minPrice)/(this.maxPrice-this.minPrice)*576;
   const y = q => 205-q/this.maxQuantity*185;
   return `M${x(rows[0].price)},205 L${x(rows[0].price)},${y(rows[0].cumulative)} ` + rows.slice(1).map(r => `H${x(r.price)} V${y(r.cumulative)}`).join(' ') + ` L${x(rows[rows.length-1].price)},205 Z`;
  }
 }
};
</script>
