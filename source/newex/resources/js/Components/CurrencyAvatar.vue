<template><span class="currency-avatar" :class="{'currency-avatar--umi':symbol === 'UMI'}"><img v-if="url && !failed" :src="url" :alt="symbol" @error="failed=true"><span v-else :aria-label="symbol">{{ label }}</span></span></template>
<script>
export default {
 props: {symbol:{type:String,default:''},src:{type:String,default:''}},
 data(){return {failed:false}},
 computed:{url(){return this.src && !/deepro-icon|okr|favicon|currency-placeholder/.test(this.src)?this.src:''}, label(){return (this.symbol.replace(/on$/,'')||'·').slice(0,3)}},
 watch:{url(){this.failed=false}}
};
</script>
<style>.currency-avatar{width:28px;height:28px;min-width:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#eef4f0;color:#42644e;font-size:10px;font-weight:700;vertical-align:middle;overflow:hidden}.currency-avatar img{width:100%!important;height:100%!important;object-fit:contain}.currency-avatar--umi{background:#e4f5e8}</style>
