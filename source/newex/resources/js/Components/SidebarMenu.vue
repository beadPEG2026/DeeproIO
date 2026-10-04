<script>
import Template from '{Template}/Web/Components/SidebarMenu.template'
import LanguageSwitcher from "@/Components/LanguageSwitcher";

export default Template({
    components: {
        LanguageSwitcher,
    },
    data() {
        return {

        }
    },
    computed: {
        profileMenuOpened: function () {
            return this.$store.getters.getProfileState;
        },
    },
    methods: {
        isUrl(urls) {
            let currentUrl = this.$page.url.substr(1).split('/');
            if(currentUrl[0]) {
                return currentUrl[0].startsWith(urls)
            }
        },
        setPage(page) {
            this.$inertia.visit(this.route(page));

            setTimeout(() => {
                this.$store.dispatch('setProfileState', false);
            }, 1000);
        }
    }
})
</script>
