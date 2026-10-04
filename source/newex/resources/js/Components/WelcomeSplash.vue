<template>
    <dialog ref="dialog" class="dp-welcome" :aria-label="t('欢迎来到 Deepro','Welcome to Deepro')" @cancel.prevent="dismiss">
        <div class="dp-welcome__art" aria-hidden="true"></div>
        <header><img src="/images/deepro-icon.png" alt=""><strong>Deepro</strong><details><summary>{{ t('简体中文','Language') }} ⌄</summary><language-switcher /></details></header>
        <section class="dp-welcome__heading"><small>{{ t('数字生活，自在掌握','Digital life, in your hands') }}</small><h1>{{ t('开启','Enter the') }}<br>{{ t('数字时代','digital era') }}</h1><p>{{ t('从容管理资产','Manage your assets with ease') }}<br>{{ t('轻盈开启每一天。','A lighter start to every day.') }}</p></section>
        <section class="dp-welcome__entry"><p>{{ t('你的数字世界，从 Deepro 开始。','Your digital world starts with Deepro.') }}</p><div><Link :href="route('login')" @click="remember">{{ $t('Login') }}</Link><Link :href="route('register')" @click="remember">{{ $t('Register') }} ↗</Link></div><footer><small>{{ t('Deepro · 数字资产交易平台','Deepro · Digital asset exchange') }}</small><button type="button" @click="dismiss">{{ t('探索','Explore') }} →</button></footer></section>
    </dialog>
</template>
<script>
import LanguageSwitcher from '@/Components/LanguageSwitcher';
export default {components:{LanguageSwitcher},mounted(){this.$refs.dialog.showModal()},beforeDestroy(){this.$refs.dialog?.close()},methods:{t(zh,en){return this.$t(en)},remember(){try{sessionStorage.setItem('deepro-welcome-seen','1')}catch(_){}},dismiss(){this.remember();this.$emit('close')}}};
</script>
