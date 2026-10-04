<template>
 <section class="product-preview" aria-labelledby="product-preview-title">
  <h2 id="product-preview-title">{{ $t('Configuration preview') }}</h2>
  <p>{{ $t('Review the draft changes before saving. This preview does not publish the product or calculate returns for existing positions.') }}</p>
  <div class="product-preview-table"><table><thead><tr><th>{{ $t('Field') }}</th><th v-if="isEdit">{{ $t('Saved value') }}</th><th>{{ $t('Draft value') }}</th></tr></thead>
   <tbody><tr v-for="field in changes" :key="field.key"><th scope="row">{{ $t(field.label) }}</th><td v-if="isEdit">{{display(field,original[field.key])}}</td><td>{{display(field,form[field.key])}}</td></tr>
    <tr v-if="!changes.length"><td :colspan="isEdit?3:2">{{ $t('No configuration changes') }}</td></tr></tbody>
  </table></div>
 </section>
</template>
<script>
export default {
 props:{form:Object,original:{type:Object,default:()=>({})},fields:Array,isEdit:Boolean,currencies:Array,networks:Array},
 computed:{changes(){return this.fields.filter(f=>!this.isEdit || String(this.form[f.key]??'')!==String(this.original[f.key]??''))}},
 methods:{display(field,value){
  if(value===null || value===undefined || value==='')return '—';
  if(field.kind==='currency')return this.currencies?.find(c=>String(c.id)===String(value))?.name||String(value);
  if(field.kind==='network')return this.networks?.find(n=>String(n.id)===String(value))?.name||String(value);
  if(field.kind==='enabled')return this.$t([true,1,'1'].includes(value)?'Enabled':'Disabled');
  if(field.kind==='status')return this.$t({active:'Active',sold:'Sold out',hidden:'Hidden'}[value]||String(value));
  return String(value);
 }}
}
</script>
<style scoped>
.product-preview{margin:24px 32px;padding:20px;border:1px solid #c9ae64;border-radius:8px;color:inherit}.product-preview h2{font-weight:600;font-size:18px}.product-preview p{margin:10px 0;line-height:1.6}.product-preview-table{overflow:auto}.product-preview table{width:100%;border-collapse:collapse;text-align:left}.product-preview th,.product-preview td{padding:10px;border-bottom:1px solid #9ca3af44;vertical-align:top;overflow-wrap:anywhere;min-width:90px;max-width:420px}.product-preview th{font-weight:500}@media(max-width:600px){.product-preview{margin:16px;padding:12px}}
</style>
