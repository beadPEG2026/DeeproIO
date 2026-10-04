<template>
 <div class="dp-select">
  <select ref="native" class="dp-select__native" v-bind="nativeAttrs" :multiple="multiple" :disabled="disabled" tabindex="-1" aria-hidden="true" @change="changed" @invalid.prevent="invalid"><slot/></select>
  <button ref="trigger" type="button" class="dp-select__trigger" :id="id" :disabled="disabled" :aria-label="label" :aria-invalid="validation ? 'true' : null" aria-haspopup="dialog" :aria-expanded="open" @click.stop="show" @keydown.down.prevent="show" @keydown.up.prevent="show">
   <network-icon v-if="network && selected && selected.value!==null && selected.value!==''" :network-id="selected.value" :name="selected.text"/>
   <span class="dp-select__label">{{ selectedText || placeholder || $t('Select') }}</span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
  </button>
  <small v-if="validation" class="dp-select__error" role="alert">{{validation}}</small>
  <mounting-portal v-if="open" mount-to="body" append>
   <div class="dp-select-overlay" @click.self="close">
    <section ref="dialog" class="dp-select-sheet" role="dialog" aria-modal="true" :aria-label="label" @keydown="keydown">
     <header><h2>{{label}}</h2><button type="button" :aria-label="$t('Close')" @click="close">×</button></header>
     <input v-if="options.length>8" ref="search" v-model="search" type="search" :placeholder="$t('Search')" :aria-label="$t('Search')" autocomplete="off">
     <div class="dp-select-sheet__list" role="listbox" :aria-label="label" :aria-multiselectable="multiple ? 'true' : null">
      <button v-for="option in filtered" :key="option.index" type="button" role="option" :aria-selected="option.selected ? 'true' : 'false'" :disabled="option.disabled" @click="choose(option)">
       <network-icon v-if="network && option.value!==null && option.value!==''" :network-id="option.value" :name="option.text"/>
       <span><small v-if="option.group">{{option.group}}</small>{{option.text}}<small v-if="networkDescription(option)">{{ $t(networkDescription(option)) }}</small></span><b v-if="option.selected" aria-hidden="true">✓</b>
      </button>
      <p v-if="!filtered.length" role="status">{{$t('No results found')}}</p>
     </div>
     <button v-if="multiple" type="button" class="dp-select-sheet__done" @click="close">{{$t('Done')}}</button>
    </section>
   </div>
  </mounting-portal>
 </div>
</template>
<script>
import NetworkIcon from './NetworkIcon.vue';
import {networkOptionDescription} from '@/Functions/NetworkIdentity.mjs';
const equal=(a,b)=>{if(a===b)return true;if(a==null||b==null)return false;if(typeof a==='object'||typeof b==='object')return JSON.stringify(a)===JSON.stringify(b);return String(a)===String(b)};
export default {
 inheritAttrs:false,components:{NetworkIcon},
 props:{value:{},id:String,disabled:Boolean,multiple:Boolean,network:Boolean,placeholder:String},
 data:()=>({open:false,search:'',options:[],validation:'',contextLabel:'',previousOverflow:null,observer:null}),
 computed:{
  nativeAttrs(){const attrs={...this.$attrs};delete attrs.id;return attrs},
  label(){return this.$attrs['aria-label'] || this.contextLabel || this.placeholder || this.$t(this.network?'Select network':'Select')},
  selected(){return this.options.find(o=>o.selected)},
  selectedText(){return this.options.filter(o=>o.selected).map(o=>o.text).join(', ')},
  filtered(){const q=this.search.trim().toLowerCase();return this.options.filter(o=>!(o.disabled&&(o.value===''||o.value===null))&&(!q||`${o.text} ${o.group}`.toLowerCase().includes(q)))}
 },
 mounted(){this.sync();this.readLabel();this.observer=new MutationObserver(()=>this.sync());this.observer.observe(this.$refs.native,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['disabled','label','value']})},
 updated(){this.sync()},
 beforeDestroy(){this.observer?.disconnect();this.restoreScroll()},
 methods:{
  networkDescription(option){return this.network ? networkOptionDescription(option,this.options) : ''},
  readLabel(){const label=(this.id && document.querySelector(`label[for="${CSS.escape(this.id)}"]`)) || this.$el.closest('label') || this.$el.previousElementSibling?.closest('label');if(label){const copy=label.cloneNode(true);copy.querySelectorAll('.dp-select,select,input').forEach(n=>n.remove());this.contextLabel=copy.textContent.trim()}},
  sync(){const el=this.$refs.native;if(!el)return;const values=this.multiple?(Array.isArray(this.value)?this.value:[]):[this.value];const rows=[...el.options].map((o,index)=>{const value=Object.prototype.hasOwnProperty.call(o,'_value')?o._value:o.value;const selected=values.some(v=>equal(v,value));o.selected=selected;return {index,value,text:o.label.trim(),selected,disabled:o.disabled||o.parentElement.disabled===true,group:o.parentElement.tagName==='OPTGROUP'?o.parentElement.label:''}});if(!rows.some(o=>o.selected))el.selectedIndex=-1;if(JSON.stringify(rows)!==JSON.stringify(this.options))this.options=rows; if(el.matches(':disabled')&&this.open)this.close()},
  show(){if(this.open||this.disabled||this.$refs.native.matches(':disabled'))return;this.sync();this.readLabel();this.search='';this.open=true;this.previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';this.$nextTick(()=>{const selected=this.$refs.dialog?.querySelector('[role=option][aria-selected=true]:not(:disabled)');(selected||this.$refs.dialog?.querySelector('[role=option]:not(:disabled)')||this.$refs.dialog?.querySelector('button'))?.focus()})},
  restoreScroll(){if(this.previousOverflow!==null){document.body.style.overflow=this.previousOverflow;this.previousOverflow=null}},
  close(){this.open=false;this.restoreScroll();this.$nextTick(()=>this.focus())},
  focus(){this.$refs.trigger?.focus()},select(){this.show()},
  invalid(){this.validation=this.$refs.native.validationMessage;this.focus()},
  choose(option){const el=this.$refs.native;if(option.disabled||el.matches(':disabled'))return;if(this.multiple)el.options[option.index].selected=!el.options[option.index].selected;else el.selectedIndex=option.index;el.dispatchEvent(new Event('change',{bubbles:true}));if(!this.multiple)this.close()},
  changed(event){const selected=[...event.target.selectedOptions].map(o=>Object.prototype.hasOwnProperty.call(o,'_value')?o._value:o.value);this.validation='';this.$emit('input',this.multiple?selected:selected[0]);this.$emit('change',event);this.$nextTick(this.sync)},
  keydown(event){if(event.key==='Escape'){event.preventDefault();this.close();return}const items=[...this.$refs.dialog.querySelectorAll('button:not(:disabled),input')];const index=items.indexOf(document.activeElement);if(event.key==='Tab'){if(event.shiftKey&&index===0){event.preventDefault();items[items.length-1]?.focus()}else if(!event.shiftKey&&index===items.length-1){event.preventDefault();items[0]?.focus()}return}if(document.activeElement?.tagName==='INPUT')return;const choices=[...this.$refs.dialog.querySelectorAll('[role=option]:not(:disabled)')];const i=choices.indexOf(document.activeElement);let next;if(event.key==='ArrowDown')next=(i+1)%choices.length;else if(event.key==='ArrowUp')next=(i-1+choices.length)%choices.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=choices.length-1;if(next!==undefined){event.preventDefault();choices[next]?.focus()}}
 }
};
</script>
<style>
.dp-select{position:relative;min-width:0;border:0!important;padding:0!important;background:transparent!important;box-shadow:none!important;color:var(--ui-text,#202630)}
.dp-select .dp-select__native{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:0!important;opacity:0!important;pointer-events:none!important;clip-path:inset(50%)}
.dp-select .dp-select__trigger{display:flex;align-items:center;gap:10px;width:100%;min-width:0;min-height:44px;padding:10px 12px;border:1px solid var(--ui-line,#d6dae0);border-radius:8px;background:var(--ui-field,#f7f8fa);color:var(--ui-text,#202630);font:inherit;line-height:1.45;text-align:left;box-shadow:none}
.dp-select__trigger>.dp-select__label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dp-select__trigger>svg{width:18px;height:18px;flex:0 0 18px}.dp-select__trigger:disabled{opacity:.5;cursor:not-allowed}.dp-select__trigger:focus-visible,.dp-select-sheet button:focus-visible{outline:2px solid var(--ui-accent,#e4ba20);outline-offset:-2px}.dp-select__error{color:#e05268}
.dp-select-overlay{position:fixed;inset:0;z-index:110000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.48)}
.dp-select-sheet{display:flex;flex-direction:column;width:min(100%,480px);max-height:calc(100vh - 40px);max-height:calc(100dvh - 40px);overflow:hidden;border:1px solid var(--ui-line,#d6dae0);border-radius:18px;background:var(--ui-surface,#fff);color:var(--ui-text,#202630);box-shadow:0 16px 64px #0003}
.dp-select-sheet>header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 16px;flex-shrink:0;border-bottom:1px solid var(--ui-divider,#e9eaee)}.dp-select-sheet h2{font-size:16px;font-weight:600;margin:0}.dp-select-sheet header button{width:40px;height:40px;font-size:26px;background:none;color:inherit;border:0;border-radius:8px}
.dp-select-sheet>input{margin:12px 16px;width:calc(100% - 32px);min-height:44px;padding:10px 12px;border:1px solid var(--ui-line,#d6dae0);border-radius:8px;background:var(--ui-field,#f7f8fa);color:inherit;font-size:16px;flex-shrink:0}
.dp-select-sheet__list{overflow:auto;overscroll-behavior:contain;min-height:0;padding:4px 12px 12px}.dp-select-sheet__list>button{display:flex;align-items:center;gap:12px;width:100%;min-height:52px;padding:12px 8px;background:none;color:inherit;text-align:left;font-size:14px;line-height:1.5;border:0;border-bottom:1px solid var(--ui-divider,#eceef0)}.dp-select-sheet__list>button>span:not(.dp-network-icon){flex:1;overflow-wrap:anywhere}.dp-select-sheet__list>button small{display:block;font-size:11px;opacity:.6}.dp-select-sheet__list>button b{font-size:18px;color:var(--ui-accent-text,#967200)}.dp-select-sheet__list>button[aria-selected=true]{background:var(--ui-field,#f7f8fa);border-radius:8px}.dp-select-sheet__list>button:disabled{opacity:.4;cursor:not-allowed}.dp-select-sheet__list>p{padding:20px}.dp-select-sheet__done{padding:12px;background:#f4ca28;color:#181b20;font-weight:600}
body.dark .dp-select-sheet,body.dark .dp-select__trigger{background:var(--ui-surface,#222a35);color:var(--ui-text,#f1f3f5);border-color:var(--ui-line,#414957)}body.dark .dp-select-sheet>input,body.dark .dp-select-sheet__list>button[aria-selected=true]{background:var(--ui-field,#18212b);color:var(--ui-text,#f1f3f5)}
@media(max-width:640px){.dp-select-overlay{align-items:flex-end;padding:0}.dp-select-sheet{width:100%;max-height:82vh;max-height:82dvh;border-radius:20px 20px 0 0;padding-bottom:env(safe-area-inset-bottom)}.dp-select-sheet__list>button{min-height:58px;font-size:15px}}

.dp-rich-select__trigger,.dp-rich-select__menu,.dp-rich-select__search{background:var(--ui-field,#fff)!important;color:var(--ui-text,#202630)!important;border-color:var(--ui-line,#d6dae0)!important}.dp-rich-select__menu{z-index:60!important;max-height:min(55vh,420px);overflow:auto}.dp-rich-select__menu ul{max-height:inherit}.dp-rich-select__selected,.dp-rich-select__highlight{background:var(--ui-line,#eee7bd)!important;color:var(--ui-text,#202630)!important}body.dark .dp-rich-select__trigger,body.dark .dp-rich-select__menu,body.dark .dp-rich-select__search{background:var(--ui-field,#222a35)!important;color:var(--ui-text,#f1f3f5)!important;border-color:var(--ui-line,#414957)!important}body.dark .dp-rich-select__selected,body.dark .dp-rich-select__highlight{background:var(--ui-line,#39414d)!important;color:var(--ui-text,#f1f3f5)!important}
.dp-chart-periods .dp-select{flex:0 0 auto;max-width:78px}.dp-chart-periods .dp-select__trigger{min-height:32px;padding:4px;border:0;background:none;font-size:12px;gap:2px}.dp-chart-periods .dp-select__trigger>svg{width:12px;flex-basis:12px}
.dp-stablecoin-entry{display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:12px 16px;border-bottom:1px solid var(--ui-divider,#eceef0);font-size:12px}.dp-stablecoin-entry>span{display:flex;align-items:center;gap:6px;margin-right:auto}.dp-stablecoin-entry .currency-avatar{width:20px;height:20px;min-width:20px}.dp-stablecoin-entry>span .currency-avatar+ .currency-avatar{margin-left:-10px;box-shadow:0 0 0 2px var(--ui-surface,#fff)}.dp-stablecoin-entry a{padding:8px 10px;border-radius:8px;background:var(--ui-field,#f5f6f8);color:var(--ui-text,#202630);font-size:12px;white-space:nowrap}.dp-stablecoin-entry a:hover{color:var(--ui-accent-text,#967200)}
@media(max-width:640px){.dp-stablecoin-entry{gap:6px;padding:10px 12px}.dp-stablecoin-entry>span{gap:4px;font-size:11px}.dp-stablecoin-entry a{padding:7px 6px;font-size:11px}.dp-stablecoin-entry .currency-avatar{width:18px;height:18px;min-width:18px}}@media(max-width:359px){.dp-stablecoin-entry{display:grid;grid-template-columns:1fr 1fr}.dp-stablecoin-entry>span{grid-column:1 / -1}.dp-stablecoin-entry a{text-align:center}}
body.dark .grouping-dropdown__menu,body.dark .phone-input__country-list,body.dark .phone-input__search,body.dark .phone-input__search-input,body.dark .datepicker-dropdown,body.dark .admin-filter-dropdown-secondary{background:var(--ui-surface,#222a35)!important;color:var(--ui-text,#f1f3f5)!important;border-color:var(--ui-line,#414957)!important}body.dark .phone-input__country-item:hover{background:var(--ui-field,#18212b)!important}
.dp-select.error .dp-select__trigger,.dp-select.border-red-500 .dp-select__trigger,.dp-select__trigger[aria-invalid=true]{border-color:#e05268}
</style>
