<template>
<div class="dp-market-directory">
  <div class="dp-market-directory__heading"><h2>{{ $t('Markets') }}</h2><button type="button" class="dp-market-directory__close" :aria-label="$t('Close')" @click="$emit('close')">×</button></div>
  <input v-model.trim="query" type="search" :aria-label="$t('Search Coin Name')" :placeholder="$t('Search Coin Name')">
  <p v-if="$store.getters.getMarketsLoadFailed" role="status">{{ $t('Market list could not be updated.') }} <button type="button" @click="$store.dispatch('fetchMarkets', route('markets.api.ticker'))">{{ $t('Retry') }}</button></p>
  <div class="dp-market-directory__labels"><span>{{ $t('Market') }}</span><span>{{ $t('Last Price') }}</span><span>{{ $t('Change') }}</span></div>
  <div class="dp-market-directory__list">
    <Link v-for="item in filtered" :key="item.name" :href="route(futures ? 'futures-market' : 'market', item.name)" :class="{'is-active': current === item.name}" class="dp-market-directory__row">
      <span>{{ item.base_currency || item.name.split('-')[0] }}<small>/{{ item.quote_currency || item.name.split('-').slice(1).join('-') }}</small></span>
      <span>{{ Number(item.last) > 0 ? (item.referenceQuote ? Number(item.last).toFixed(Math.min(4, item.quote_precision)) : item.last) : '—' }}</span>
      <span :class="Number(item.change) >= 0 ? 'color-buy' : 'color-sell'">{{ item.change == null ? '—' : Number(item.change).toFixed(2) + '%' }}</span>
    </Link>
    <p v-if="!filtered.length">{{ $t('No markets found.') }}</p>
  </div>
</div>
</template>
<script>
import {state, subscribeStockQuotes, unsubscribeStockQuotes} from '@/Functions/StockQuotes';
import {resolveEstimateMarket} from '@/Functions/OrderEstimate.mjs';
export default {
  props: {current: String, futures: Boolean},
  data: () => ({query: ''}),
  computed: {
    filtered() {
      const data = this.$store.getters.getMarkets || [];
      return (Array.isArray(data) ? data : Object.values(data)).filter(item =>
        (!this.futures || item.has_futures) && (item.name || '').toLowerCase().includes(this.query.toLowerCase())
      ).map(item => !this.futures && item.stock_token
        ? resolveEstimateMarket(item, state.quotes[item.base_currency], state.now) : item);
    }
  },
  mounted() {
    // The parent seeds the current market before mounting, so a nonempty store
    // does not prove the directory's metadata has been loaded on a direct visit.
    this.$store.dispatch('fetchMarkets', this.route('markets.api.ticker'));
    if (!this.futures) { this._stockListSubscribed = true; subscribeStockQuotes(); }
  },
  beforeDestroy() { if (this._stockListSubscribed) unsubscribeStockQuotes(); }
};
</script>
