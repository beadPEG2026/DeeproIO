<template>
<details v-if="asset" class="dp-stock-info" :open="expanded" @toggle="loadQuote">
  <summary><span>{{ isPriceProduct ? asset.ticker : asset.id }} · {{ $t('Asset information') }}</span><span>{{ isPriceProduct ? $t('Equity price product') : asset.issuer + ' · BSC' }} <span aria-hidden="true">＋</span></span></summary>
  <div v-if="isPriceProduct" class="dp-stock-info__body">
    <dl><div><dt>{{ $t('Underlying symbol') }}</dt><dd>{{ asset.ticker }}</dd></div><div><dt>{{ $t('Product type') }}</dt><dd>{{ $t('Equity price product') }}</dd></div><div><dt>{{ $t('Quote currency') }}</dt><dd>HKD</dd></div><div><dt>{{ $t('Price') }}</dt><dd>{{ quote && quote.underlyingPrice ? quote.underlyingPrice : '—' }} HKD</dd></div><div><dt>{{ $t('Quote time') }}</dt><dd>{{ quote && quote.sourceTime ? new Date(quote.sourceTime).toLocaleString($i18n.locale, {timeZone:'Asia/Hong_Kong',hour12:false}) : '—' }} <small v-if="quote && quote.sourceTime">HKT</small></dd></div><div><dt>{{ $t('Settlement currency') }}</dt><dd>USDT</dd></div></dl>
  </div>
  <div v-else class="dp-stock-info__body">
    <dl><div><dt>{{ $t('Issuer') }}</dt><dd>{{ asset.issuer }}</dd></div><div><dt>{{ $t('Network') }}</dt><dd><network-icon :network-id="6" name="BSC"/>{{ $t("BSC (BEP-20)") }}</dd></div><div><dt>{{ $t('Underlying symbol') }}</dt><dd>{{ asset.ticker }}</dd></div><div><dt>{{ $t('Decimals') }}</dt><dd>{{ asset.decimals }}</dd></div><div><dt>{{ $t('Shares per token') }}</dt><dd>{{ quote && quote.sharesMultiplier != null ? quote.sharesMultiplier : '—' }}</dd></div><div><dt>{{ $t('Underlying price (USD)') }}</dt><dd>{{ quote && quote.underlyingPrice != null ? quote.underlyingPrice : '—' }}</dd></div></dl>
    <div class="dp-stock-info__contract"><a :href="asset.explorerUrl" target="_blank" rel="noopener noreferrer">{{ asset.contract }} ↗</a><button type="button" @click="copy">{{ $t(copied ? 'Copied' : 'Copy address') }}</button></div>

    <div><p><a :href="asset.issuerUrl" target="_blank" rel="noopener noreferrer">{{ $t('Issuer') }} ↗</a></p><p>{{ $t('Updated') }} · {{ quote ? updatedAt : $t('No data') }}</p></div>
    <div class="dp-stock-info__actions"><Link v-if="asset.depositEnabled" :href="'/wallets/deposit/crypto/' + asset.id">{{ $t('Deposit') }} ↗</Link><Link v-if="asset.withdrawEnabled" :href="'/wallets/withdraw/crypto/' + asset.id">{{ $t('Withdraw') }} ↗</Link></div>
  </div>
</details>
</template>
<script>
import axios from 'axios';
export default {
  props: {asset: Object, defaultOpen: Boolean},
  data: () => ({copied: false, quote: null, loading: false, receivedAt: null}),
  computed: {
    isPriceProduct() {return this.asset.instrumentType === 'equity_price_reference';},
    expanded() {return this.defaultOpen || new URL(this.$page.url, location.origin).searchParams.get('asset') === 'info';},
    updatedAt() {return this.receivedAt ? new Date(this.receivedAt).toLocaleString(this.$i18n.locale) : '—';}
  },
  methods: {
    copy() {this.$copyText(this.asset.contract).then(() => {this.copied = true;}).catch(() => {this.copied = false;});},
    async loadQuote(event) {
      if (!event.target.open || this.loading) return;
      this.loading = true;
      try {const {data} = await axios.get(this.route('stocks.quotes'), {timeout: 12000, params: {symbol: this.asset.symbol}}); const row = data.data.find(r => r.symbol === this.asset.symbol); this.quote = row && (this.isPriceProduct || !row.unavailable) ? row : null; this.receivedAt = row && (row.receivedAt || data.receivedAt);}
      catch (_) {this.quote = null;}
      finally {this.loading = false;}
    }
  }
}
</script>
