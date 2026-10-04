<script>
import '@/Components/site-refinement.css';
import SiteFooter from "@/Components/SiteFooter.vue";
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import Template from '{Template}/Web/Layout/AppBasic.template'
import ThemeMode from "@/Components/ThemeMode";
import BottomMenu from '@/Components/BottomMenu';

export default Template({
    components: {
        BottomMenu,
        SiteFooter,
        LanguageSwitcher,
        ThemeMode,
    },
    data() {
        return {
            isMobile: false,
        }
    },
    created() {
        window.addEventListener('resize', this.mq)
    },
    beforeDestroy: function(){
        window.removeEventListener('resize', this.mq)
    },
    computed: {
        logo() {
            return '/images/deepro-logo.svg'
        }
    },
    mounted() {
        this.mq();
        // Store user in vuex store
        this.setUser();
    },
    methods: {
        mq () {
            if (typeof window.matchMedia !== "undefined") {
                this.isMobile = window.matchMedia('(max-width: 767px)').matches;
            }
        },
        setUser() {
            if(this.$store && this.$page.props.user && !this.$store.getters.getUser) {
                this.$store.dispatch('setUser', {user: this.$page.props.user});
            }
        },
        isUrl(urls) {
            let currentUrl = this.$page.url.substr(1).split('/');
            if(currentUrl[0]) {
                return currentUrl[0].startsWith(urls)
            }
        },
    },
})
</script>
