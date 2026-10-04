<template><admin-layout><Head title="UMI 运营中心"/><legacy-accounts v-bind="$props"/></admin-layout></template>
<script>
import AdminLayout from '@/Layouts/AdminLayout';
import LegacyAccounts from '@/Components/UmiLegacyAccounts';
export default {components:{AdminLayout,LegacyAccounts},props:{accounts:Object,reviews:Array,recoveryRequests:Array,batches:Array,search:String,asset:Object}};
</script>
