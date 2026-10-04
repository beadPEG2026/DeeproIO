<template>
<span class="deepro-brand" :class="{ 'deepro-brand-symbol': symbolOnly }">
    <img :src="customLogo || '/images/deepro-mark-small.png'" :alt="symbolOnly || customLogo ? siteName : ''" width="60" height="32" />
    <b v-if="!symbolOnly && !customLogo">{{ siteName }}</b>
</span>
</template>
<script>
export default { props: { symbolOnly: Boolean }, computed: { siteName() { return this.$page.props.siteName || 'Deepro'; }, customLogo() { const logo = this.$page.props.siteLogo; return logo && logo !== '/images/deepro-logo.svg' ? logo : null; } } };
</script>
