<template>
<div class="hidden-in-desktop account-overview-tab">
  <div class="item">
    <div class="sub-tabs">
      <Link as="div" :href="route('wallets.new')" class="sub-tabs-nav__item" :class="{ 'sub-tabs-nav__active': page == 'overview' }">
        <h4>{{ $t('Overview') }}</h4>
      </Link>

      <Link as="div" :href="route('wallets')" class="sub-tabs-nav__item" :class="{ 'sub-tabs-nav__active': page == 'funding' }">
        <h4>{{ $t('Funding') }}</h4>
      </Link>

      <Link as="div" :href="route('wallets.trading')" class="sub-tabs-nav__item" :class="{ 'sub-tabs-nav__active': page == 'trading' }">
        <h4>{{ $t('Trading') }}</h4>
      </Link>
      
      <Link as="div" :href="route('wallets.transfer')" class="sub-tabs-nav__item" :class="{ 'sub-tabs-nav__active': page == 'transfer' }">
        <h4>{{ $t('Transfer') }}</h4>
      </Link>
    </div>
  </div>
</div>
</template>

<script>
export default {
  name: "WalletOverview",
  props: {
    page: {
      type: String,
      default: 'overview'
    },
  }
};
</script>