<template>
    <div class="site-layout dp-auth-single" :class="'dp-auth-single--' + screen">
        <div class="dp-auth-single__page">
            <header class="dp-auth-single__topbar">
                <Link :href="route('home')" class="dp-auth-single__back" :aria-label="$t('Back to Deepro')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6" /></svg>
                </Link>
                <span class="dp-auth-single__platform">{{ $t('Deepro · Digital asset exchange') }}</span>
                <div class="dp-auth-single__actions">
                    <details v-if="$page.props.lang_mode_enabled" class="dp-auth-single__language">
                        <summary :aria-label="$t('Language')">{{ languageLabel }}</summary>
                        <language-switcher />
                    </details>
                    <theme-mode />
                </div>
            </header>
            <main class="dp-auth-single__main">
                <section class="dp-auth-single__identity" aria-labelledby="auth-title">
                    <img class="dp-auth-single__mark" src="/images/deepro-icon.png" alt="Deepro" width="62" height="62">
                    <h1 id="auth-title">{{ $t(screen === 'register' ? 'Create Deepro account' : 'Sign in to Deepro') }}</h1>
                    <p>{{ $t(screen === 'register' ? 'Start trading spot and futures with your account.' : 'Your trusted crypto companion.') }}</p>
                </section>
                <slot />
            </main>
            <footer class="dp-auth-single__footer">
                <p>{{ $t('Deepro · Global digital asset exchange') }}</p><span aria-hidden="true"></span>
            </footer>
        </div>
        <bottom-menu class="dp-auth-bottom" />
        <portal-target name="modal" multiple />
    </div>
</template>
<script>
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import ThemeMode from '@/Components/ThemeMode';
import BottomMenu from '@/Components/BottomMenu';
export default {
    components: { LanguageSwitcher, ThemeMode, BottomMenu },
    props: { screen: { type: String, default: 'login' } },
    computed: {
        languageLabel() {
            const locale = String(this.$i18n.locale || 'en');
            return locale === 'zh-cn' ? '简' : locale === 'zh-tw' ? '繁' : locale.split('-')[0].toUpperCase();
        },
    },
};
</script>
