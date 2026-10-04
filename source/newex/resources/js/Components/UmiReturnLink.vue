<template><Link v-if="target" :href="target" class="umi-return">‹ {{ $t('返回 UMI') }}</Link></template>
<script>
import {umiReturnPath} from '@/Functions/UmiNavigation.mjs';
import UmiLocale from '@/Functions/UmiLocale';
export default {mixins:[UmiLocale],computed:{target(){return umiReturnPath(this.$page.url)}}};
</script>
<style scoped>.umi-return{display:block;margin:8px auto;padding:10px 18px;max-width:1100px;color:#27853c;background:#edf7ef;border-radius:8px;min-height:42px;font-size:13px}body.dark .umi-return{background:#223d2a;color:#a0e8b0}</style>
