<script>
import Template from '{Template}/Web/Components/ThemeMode.template';
import axios from 'axios';
// All mounted switches share a single save, so rapid toggles cannot race on the server.
let savePending = false;
export default Template({
    data: () => ({isDarkTheme:false,saving:savePending}),
    mounted() {
        this.isDarkTheme=document.body.classList.contains('dark');
        this.$worker.$on('themeChanged',this.syncTheme);
        this.$worker.$on('themeSaving',this.syncSaving);
    },
    beforeDestroy() {this.$worker.$off('themeChanged',this.syncTheme);this.$worker.$off('themeSaving',this.syncSaving)},
    methods: {
        syncTheme(mode){this.isDarkTheme=mode==='dark'},
        syncSaving(value){this.saving=value},
        applyTheme(mode){document.body.classList.toggle('dark',mode==='dark');this.$worker.$emit('themeChanged',mode)},
        async setThemeMode(mode) {
            if(savePending)return;
            const previous=document.body.classList.contains('dark')?'dark':'light';
            savePending=true;this.$worker.$emit('themeSaving',true);this.applyTheme(mode);
            try {
                await axios.get(this.route('settings.theme.mode'),{params:{mode},timeout:10000});
                this.$toast.open(this.$i18n.locale.startsWith('zh')?'主题偏好已保存':'Theme preference saved.');
            } catch (_) {
                this.applyTheme(previous);
                this.$toast.error(this.$i18n.locale.startsWith('zh')?'保存主题失败，已恢复原主题，请重试。':'Unable to save your preference. The previous theme has been restored. Please retry.');
            } finally {savePending=false;this.$worker.$emit('themeSaving',false)}
        }
    }
});
</script>
