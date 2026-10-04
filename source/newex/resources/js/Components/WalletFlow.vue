<template>
 <app-layout :wallet-flow="true">
  <section class="wallet-flow" :class="{'wallet-flow--deposit':variant==='deposit','wallet-flow--withdraw':variant==='withdraw'}">
   <header class="wallet-flow__header">
    <button v-if="step > 0" type="button" :aria-label="$t('Back')" @click="$emit('back')">‹</button>
    <Link v-else :href="backHref" :aria-label="$t('Back')">‹</Link>
    <h1>{{title}}</h1>
    <Link :href="historyHref" class="wallet-flow__history" :aria-label="$t('History')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 10a8 8 0 1 1 1 7M4 4v6h6M12 7v5l3 2"/></svg></Link>
   </header>
   <ol v-if="steps && steps.length" class="wallet-flow__steps" :aria-label="$t('Progress')"><li v-for="(name,index) in steps" :key="name" :class="{'is-active':index===step,'is-complete':index<step}" :aria-current="index===step?'step':null"><span>{{index+1}}</span>{{$t(name)}}</li></ol>
   <div class="wallet-flow__content"><slot/></div>
   <footer v-if="$slots.actions" class="wallet-flow__actions"><slot name="actions"/></footer>
  </section>
 </app-layout>
</template>
<script>
import AppLayout from '@/Layouts/AppLayout';
export default {components:{AppLayout},props:{title:String,variant:String,step:{type:Number,default:0},steps:Array,backHref:String,historyHref:String}};
</script>
<style src="../../css/wallet-flow.css"></style>
