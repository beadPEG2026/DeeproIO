<template>
  <div v-if="market.price_reference_product" class="dp-market-session" :class="{'is-open':!sessionBlocked}" role="status">
    <div class="dp-market-session__line"><strong>{{ $t(sessionLabel) }}</strong><span v-if="sessionBlocked && nextSession">{{ $t('Next session') }} {{ nextSession }}</span></div>
    <small>{{ $t('Hong Kong time') }} · {{ sessionData && sessionData.half_day ? '09:30–12:00' : '09:30–12:00 / 13:00–16:00' }}<span v-if="sessionData && sessionData.half_day"> · {{ $t('Half-day session') }}</span></small>
  </div>
</template>
<script>
import MarketSession from '@/Mixins/Market/MarketSession';
export default {props:{market:Object},mixins:[MarketSession]};
</script>
