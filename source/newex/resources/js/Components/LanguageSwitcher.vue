<script>
import Template from '{Template}/Web/Components/LanguageSwitcher.template'
export default Template({
    data() {
        return {
            defaultLanguage: window.LANGUAGE,
            switching: false
        }
    },
    methods: {
        switchLanguage(slug) {
            if (this.switching) return;
            this.switching = true;
            axios.get(this.route('language.set'), {
                params: {
                    language: slug
                }
            }).then((response) => {
                window.location.reload();
            }).catch(error => {
                this.$toast.error(this.$t('Unable to change language. Please try again.'));
                this.switching = false;
            });
        },
    }
})
</script>
