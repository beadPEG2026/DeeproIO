<script>
import Template from '{Template}/Web/Components/BottomMenu.template'
export default Template({
    data() {
        return {
            profileVisible: false,
        }
    },
    computed: {
        marketLabel() { return String(this.$i18n.locale).startsWith('zh') ? '行情' : this.$t('Markets'); },
        stockLabel() { return String(this.$i18n.locale).startsWith('zh') ? '股票' : this.$t('Stocks'); },
        profileMenuOpened: function () {
            return this.$store.getters.getProfileState;
        },
        defaultTradePair() {
            return this.$page.props.defaultTradePair || 'BTC-USDT';
        },
        defaultFuturesPair() {
            return this.$page.props.defaultFuturesPair || 'BTC-USDT';
        },
        lastTradeMarket() {
            if (typeof window !== 'undefined' && window.localStorage) {
                return localStorage.getItem('lastTradeMarket') || this.defaultTradePair;
            }
            return this.defaultTradePair;
        },
        lastFuturesMarket() {
            if (typeof window !== 'undefined' && window.localStorage) {
                return localStorage.getItem('lastFuturesMarket') || this.defaultFuturesPair;
            }
            return this.defaultFuturesPair;
        },
    },
    methods: {
        isUrl(urls) {
            let currentUrl = this.$page.url.substr(1).split('/');
            if(currentUrl[0]) {
                return currentUrl[0].startsWith(urls)
            }
            return false;
        },
        setPage(page) {

            if(page == "profile") {
                this.$store.dispatch('setProfileState', true);
            } else {
                this.$inertia.visit(this.route(page));

                setTimeout(() => {
                    this.$store.dispatch('setProfileState', false);
                }, 1000);
            }

        },
        navigateToTrade() {
            const market = this.lastTradeMarket;
            this.$inertia.visit(this.route('market.lite', market));
        },
        navigateToFutures() {
            const market = this.lastFuturesMarket;
            this.$inertia.visit(this.route('futures-market.lite', market));
        },
        updateLastMarkets() {
            // Store current market in localStorage when on market pages
            if (typeof window !== 'undefined' && window.localStorage) {
                const currentUrl = this.$page.url;

                // Check if we're on a spot market page (but not futures)
                if ((currentUrl.includes('/market/lite/') || currentUrl.includes('/market/')) && !currentUrl.includes('futures-market')) {
                    const marketMatch = currentUrl.match(/\/(?:market\/lite\/|market\/)([^\/\?]+)/);
                    if (marketMatch && marketMatch[1]) {
                        localStorage.setItem('lastTradeMarket', marketMatch[1]);
                    }
                }

                // Check if we're on a futures market page
                if (currentUrl.includes('/futures-market/lite/') || currentUrl.includes('/futures-market/')) {
                    const marketMatch = currentUrl.match(/\/(?:futures-market\/lite\/|futures-market\/)([^\/\?]+)/);
                    if (marketMatch && marketMatch[1]) {
                        localStorage.setItem('lastFuturesMarket', marketMatch[1]);
                    }
                }
            }
        },
    },
    mounted() {
        this.updateLastMarkets();
    },
    watch: {
        '$page.url': {
            handler() {
                this.updateLastMarkets();
            },
            immediate: true
        }
    }
})
</script>
