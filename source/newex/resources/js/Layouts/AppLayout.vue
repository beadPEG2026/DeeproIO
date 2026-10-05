<script>
    import {watchKeyboard} from '@/Functions/KeyboardViewport.mjs';
    import Template from '{Template}/Web/Layout/App.template'
    import TradeFundingReturn from '@/Components/TradeFundingReturn.vue';
    import UmiReturnLink from '@/Components/UmiReturnLink.vue';
    import Socket from "@/Jetstream/Socket";
    import ThemeMode from "@/Components/ThemeMode";
    import LanguageSwitcher from "@/Components/LanguageSwitcher";
    import BottomMenu from "@/Components/BottomMenu";
    import SidebarMenu from "@/Components/SidebarMenu";
    import SiteFooter from '@/Components/SiteFooter.vue';
    import HomeHeader from '@/Components/HomeHeader.vue';
    import SiteHub from '@/Components/SiteHub.vue';
    import WelcomeSplash from '@/Components/WelcomeSplash.vue';
    import BrandCaption from '@/Components/BrandCaption.vue';
    import '@/Components/site-refinement.css';
    import '@/Components/interface-refinement.css';
    import '@/Mixins/DisplayPreferences';

    export default Template({
        props: { walletFlow: {type:Boolean, default:false}, umiSection: {type:Boolean, default:false} },
        components: {
            HomeHeader, SiteHub, WelcomeSplash, BrandCaption, UmiReturnLink, TradeFundingReturn,
            Socket,
            ThemeMode,
            LanguageSwitcher,
            BottomMenu,
            SidebarMenu,
            SiteFooter,
            OnRampSwap: () => import("@/Components/OnRampSwap.vue")
        },

        computed: {
            marketNavigationTitle() {
                return String(this.$i18n.locale).startsWith('zh') ? '行情' : this.$t('Markets');
            },
            headerTitle() {
                const path=this.$page.url.split('?')[0];
                if (path.startsWith('/markets') && String(this.$i18n.locale).startsWith('zh')) return '行情';
                const label=path.startsWith('/stocks')?'Stocks':path.startsWith('/market/')?'Trade':path.startsWith('/markets')?'Markets':path.startsWith('/wallets')?'Assets':path.startsWith('/staking')?'Staking':path.startsWith('/umi')?'UMI Ecosystem':path.startsWith('/user')?'Profile':'Deepro';
                return this.$t(label);
            },
            showBottomMenu() {
                return !this.umiSection;
            },
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
                return this.currentTime;
            },
            currentYear() {
                return new Date().getFullYear();
            }
        },
        beforeDestroy: function(){
            this.stopKeyboard?.();

            window.removeEventListener('resize', this.mq)
            window.removeEventListener('deepro:hub', this.openHub)

            clearInterval( this.timeInterval )
        },
        created() {
            this.setUser();
            window.addEventListener('resize', this.mq)

            this.currentTime = this.serverTime();

            this.timeInterval = setInterval(() => {
                this.currentTime = this.serverTime();
            }, 1000);
        },
        data() {
            return {
                stopKeyboard: null,
                hub: '',
                navOpen: '',
                showWelcome: false,
                profileVisible: false,
                timeInterval: null,
                currentTime: null,
                showingUserProfileDropdown: false,
                showingLanguageDropdown: false,
                showingNavigationDropdown: false,
                showingSettingsDropdown: false,
                menuToggled: false,
                isMobile: false,
                isCompactNav: false
            }
        },
        mounted() {
          this.stopKeyboard=watchKeyboard(this.$el);
          window.addEventListener('deepro:hub', this.openHub);
          const panel = new URLSearchParams(window.location.search).get('panel');
          if (['about','discover','profile'].includes(panel)) this.hub = panel;
          if (!panel && !this.$page.props.user && this.$page.url.split('?')[0] === '/') {
              try { this.showWelcome = !sessionStorage.getItem('deepro-welcome-seen'); } catch (_) { this.showWelcome = false; }
          }
          this.mq();
        },
        watch: { '$page.props.user.id'() { this.setUser(); }, '$page.url'() { this.menuToggled = false; this.navOpen = ''; this.showingUserProfileDropdown = false; const panel = new URLSearchParams(this.$page.url.split('?')[1] || '').get('panel'); if (['about','discover','profile'].includes(panel)) this.hub = panel; } },
        methods: {
            leaveNav(event) { const menu=event.currentTarget; if(event.type==='focusout'){ if(!menu.contains(event.relatedTarget))this.navOpen=''; return; } if(!menu.contains(document.activeElement))this.navOpen=''; },
            closeNav(event) { this.navOpen=''; event.currentTarget.querySelector('button')?.focus(); },
            openHub(event) { this.hub = ['about','discover','profile'].includes(event.detail) ? event.detail : 'profile'; },
            mq () {
                if (typeof window.matchMedia !== "undefined") {
                    this.isMobile = window.matchMedia('(max-width: 767px)').matches;
                    this.isCompactNav = window.matchMedia('(max-width: 1399px)').matches;
                }
            },
            setUser() {
                if(String(this.$page?.props.user?.id || '') !== String(this.$store.getters.getUser?.id || '')) {
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
            showBuyCryptoModal() {
                this.$worker.$emit("openBuyCryptoModal");
            },
            serverTime() {

                let timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

                return new Intl.DateTimeFormat('en-US', {
                    timeZone: timezone,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false,
                    timeZoneName: 'short'
                }).format(new Date());
            }
        },
    })
</script>
