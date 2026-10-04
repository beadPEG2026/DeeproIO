<template><span class="dp-network-icon" aria-hidden="true"><img v-if="identity && !failed" :src="identity.logo" alt="" @error="failed=true"><svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9Z M4 7.5l8 5 8-5M12 12.5V21"/></svg></span></template>
<script>
import {networkIdentity} from '@/Functions/NetworkIdentity.mjs';
export default {props:{networkId:[String,Number],name:String},data:()=>({failed:false}),computed:{identity(){return networkIdentity(this.networkId,this.name)}},watch:{identity(){this.failed=false}}};
</script>
<style>.dp-network-icon{--network-icon-size:28px;display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;flex:0 0 var(--network-icon-size);width:var(--network-icon-size);height:var(--network-icon-size);aspect-ratio:1;border-radius:50%;overflow:hidden;margin-right:8px;background:#fff;color:#596579}.dp-network-icon img,.dp-network-icon svg{display:block;width:100%;height:100%;min-width:0;max-width:100%;object-fit:contain;aspect-ratio:1;flex:none}.dp-network-icon svg{padding:4px}.dp-select__trigger .dp-network-icon,.dp-select-sheet .dp-network-icon{margin:0}</style>
