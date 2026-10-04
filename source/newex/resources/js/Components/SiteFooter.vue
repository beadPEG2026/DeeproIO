<template>
<footer class="dp-footer" :class="{'dp-footer--compact': compact}">
  <div class="dp-footer__inner">
    <div class="dp-footer__bottom"><Link href="/?panel=about">{{ $t('About') }} Deepro</Link><span>{{ $t("Deepro ©") }} {{ new Date().getFullYear() }}</span><span v-if="time" class="dp-footer__time">{{ time }}</span><span v-if="connection !== null && connection !== undefined" class="dp-footer__status" :class="{'is-online': connection}">{{ $t(connection ? 'Live connection active' : 'Live connection interrupted; reconnecting') }}</span></div>
  </div>
</footer>
</template>
<script>
export default {props:{compact:Boolean,time:String,connection:{default:null}}};
</script>
