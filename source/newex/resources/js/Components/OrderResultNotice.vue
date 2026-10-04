<template>
  <div v-if="sides.buy || sides.sell" class="dp-order-result-notice" role="alert" aria-live="assertive">
    <p>{{ $t('Order result is unknown. Check open orders and order history before submitting again.') }}</p>
    <div v-for="side in ['buy', 'sell']" :key="side">
      <button v-if="sides[side]" type="button" @click="$emit('reviewed', side)">
        {{ $t(side === 'buy' ? 'Buy' : 'Sell') }} · {{ $t('I have checked my order records') }}
      </button>
    </div>
  </div>
</template>
<script>
export default {props: {sides: {type: Object, required: true}}};
</script>
<style scoped>
.dp-order-result-notice {margin: 12px; padding: 12px; border: 1px solid #b88723; border-radius: 8px; background: #fff6d6; color: #654400; font-size: 13px; line-height: 1.6; overflow-wrap: anywhere;}
.dp-order-result-notice button {min-height: 44px; padding: 6px 10px; text-decoration: underline; text-align: left;}
.dp-order-result-notice button:focus-visible {outline: 2px solid currentColor; outline-offset: 2px;}
.dark .dp-order-result-notice {background: #302919; color: #ffe099; border-color: #b88723;}
</style>
