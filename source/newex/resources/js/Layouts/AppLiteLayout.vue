<script>
import SiteFooter from "@/Components/SiteFooter.vue";
    import Template from '{Template}/Web/Layout/AppLite.template'
    import Socket from "@/Jetstream/Socket";
    import ThemeMode from "@/Components/ThemeMode";
    import LanguageSwitcher from "@/Components/LanguageSwitcher";
    import BottomMenu from "@/Components/BottomMenu";
    import SidebarMenu from "@/Components/SidebarMenu";

    export default Template({
        components: {
            SiteFooter,
            Socket,
            ThemeMode,
            LanguageSwitcher,
            BottomMenu,
            SidebarMenu
        },

        computed: {
            logo() {
                return '/images/deepro-logo.svg'
            },
            isHomePage() {
                return this.$page.props.isHome;
            },
            isMarketPage() {
                return this.$page.props.isMarket;
            },
            time() {
                //return this.currentTime;
            },
        },
        beforeDestroy: function(){

            window.removeEventListener('resize', this.mq)

            clearInterval( this.timeInterval )
        },
        created() {

            window.addEventListener('resize', this.mq)

            let format = 'YYYY-MM-DD HH:mm:ss Z';

            this.currentTime = moment().format(format);

            this.timeInterval = setInterval(() => {
                this.currentTime = moment().format(format);
            }, 1000)
        },
        data() {
            return {
                profileVisible: false,
                timeInterval: null,
                currentTime: null,
                showingUserProfileDropdown: false,
                showingLanguageDropdown: false,
                showingNavigationDropdown: false,
                showingSettingsDropdown: false,
                menuToggled: false,
                isMobile: false
            }
        },
        mounted() {
          this.mq();
          this.setUser();
        },
        methods: {
            mq () {
                if (typeof window.matchMedia !== "undefined") {
                    this.isMobile = window.matchMedia('(max-width: 1000px)').matches;
                }
            },
            setUser() {
                if(this.$page && this.$page.props.user && !this.$store.getters.getUser) {
                    this.$store.dispatch('setUser', {user: this.$page.props.user});
                }
            },
            isUrl(urls) {
                let currentUrl = this.$page.url.substr(1).split('/');
                if(currentUrl[0]) {
                    return currentUrl[0].startsWith(urls)
                }
            },
            setProfile() {
                this.$inertia.visit(this.route('login'));
            },
        },
    })
</script>
