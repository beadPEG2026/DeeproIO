<script>
import RiskDialog from "@/Mixins/RiskDialog";
import Template from '{Template}/Web/Pages/MarketLite/Options.template'
import AppLayout from '@/Layouts/AppLayout'
import OrderBook from "@/Pages/Market/Partials/OrderBook";
import TextInput from "@/Jetstream/TextInput";
import TButton from "@/Jetstream/Button";
import MarketsOptionsStats from "@/Pages/Market/Partials/MarketsOptionsStats";
import OrderForm from "@/Pages/Market/Partials/OrderForm";
import OptionsOrderForm from "@/Pages/Market/Partials/OptionsOrderForm";
import OptionsOpenOrders from "@/Pages/Market/Partials/OptionsOpenOrders";
import OpenOrders from "@/Pages/Market/Partials/OpenOrders";
import OptionsTrades from "@/Pages/Market/Partials/OptionsTrades";
import OrderbookChannel from "@/Store/Channels/Public/Market/OrderbookChannel";
import MarketMixin from '@/Mixins/Market/MarketMixin';
import BinanceSocket from "../../Jetstream/BinanceSocket"
import MarketChannel from "@/Store/Channels/Public/Market/MarketChannel";

export default Template({
    components: {
        OrderbookChannel,
        OptionsTrades,
        OrderForm,
        OptionsOrderForm,
        MarketsOptionsStats,
        TButton,
        TextInput,
        AppLayout,
        OrderBook,
        OpenOrders,
        OptionsOpenOrders,
        BinanceSocket,
        MarketChannel
    },
    mixins: [RiskDialog, MarketMixin],
    props: {
        market: Object,
        quotes: [Array, Object],
        futures: Boolean,
        isMobile: Boolean,
        fee: String,
    },
    data() {
        return {
            chartLoaded: false,
            mobileFirstTab: 'chart',
            activeTheme: '',
            chartIteration: 0,
            orderType: 'limit',
            showRiskModal: false,
        }
    },
    beforeDestroy () {
        this.stopKlineChangeWatcher();
        this.$worker.$off('themeChanged', this.themeChangedHandler);

        if (typeof window !== 'undefined') {
            window.removeEventListener('resize', this.onResize, { passive: true })
        }
    },
    computed:{
        chartUrl() {
            let theme = document.getElementById("body").getAttribute("class");

            if(!theme || theme == '') {
                theme = "light";
            }

            this.activeTheme = theme;
            let symbol = this.market.data.name;
            let route = false;

            if (this.market.data.chart_enabled == "liquidity") {
                symbol = this.market.data.sanitized_name;
                route = true;
            }

            if (
                this.market.data.chart_enabled == "custom" &&
                this.market.data.custom_market_path
            ) {
                symbol = this.market.data.custom_market_path;
                route = false;
            }

            return this.route('chart', {
                symbol: symbol,
                route: route,
                theme: this.activeTheme
            });
        },
        markets: function () {
            return this.$store.getters.getMarkets;
        },
    },
    mounted() {

        this.loadFavorites();

        if(_.isEmpty(this.markets)) {
            this.$store.dispatch('fetchMarkets', this.route('markets.api.ticker'));
        }

        this.themeChangedHandler = (data) => {
            this.chartIteration++;
            this.activeTheme = data;
        };
        this.$worker.$on('themeChanged', this.themeChangedHandler);

        window.globalMarket = this.market.data;

        this.onResize();
        window.addEventListener('resize', this.onResize, { passive: true })

        this.startKlineChangeWatcher();
    },
    methods: {
        setFirstTab(type) {
            this.mobileFirstTab = type;
        },
        setOrderType(type) {
            this.orderType = type;
        },
        onResize () {
            if(window.innerWidth >= 986) {
                this.$inertia.visit(this.route('options-market', window.globalMarket.name));
            } else {
                //this.$inertia.visit(this.route('market.lite', window.globalMarket.name));
            }
        },
        toggleIframe() {
            this.chartLoaded = true;
        },
        goTradeRecords() {
            this.$inertia.visit('/reports/options');
        }
    }
})
</script>
