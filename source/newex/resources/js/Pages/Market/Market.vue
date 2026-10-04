<script>
import MarketSession from "@/Mixins/Market/MarketSession";
import TradeCategoryNav from "@/Components/TradeCategoryNav.vue";
import { tradeViewport, positionTradeTicket, ticketIsVisible } from "@/Functions/TradeNavigation.mjs";
import MarketSessionNotice from "@/Components/MarketSessionNotice.vue";
import HongKongQuote from "@/Components/HongKongQuote.vue";
import RiskDialog from "@/Mixins/RiskDialog";
import MarketDepth from "@/Components/MarketDepth.vue";
import MarketChart from "@/Components/MarketChart.vue";
import MarketDirectory from "@/Components/MarketDirectory.vue";
import StockAssetInfo from "@/Components/StockAssetInfo.vue";
import Template from '{Template}/Web/Pages/Market/Market.template'
import AppLayout from '@/Layouts/AppLayout'
import OrderBook from "@/Pages/Market/Partials/OrderBook";
import TextInput from "@/Jetstream/TextInput";
import TButton from "@/Jetstream/Button";
import MarketStats from "@/Pages/Market/Partials/MarketStats";
import OrderForm from "@/Pages/Market/Partials/OrderForm";
import FuturesOrderForm from "@/Pages/Market/Partials/FuturesOrderForm";
import FuturesOpenOrders from "@/Pages/Market/Partials/FuturesOpenOrders";
import OpenOrders from "@/Pages/Market/Partials/OpenOrders";
import OrdersHistory from "@/Pages/Market/Partials/OrdersHistory";
import Trades from "@/Pages/Market/Partials/Trades";
import MarketTrades from "@/Pages/Market/Partials/MarketTrades";
import OrderbookChannel from "@/Store/Channels/Public/Market/OrderbookChannel";
import MarketMixin from '@/Mixins/Market/MarketMixin';
import BinanceSocket from "../../Jetstream/BinanceSocket"
import MarketChannel from "@/Store/Channels/Public/Market/MarketChannel";

export default Template({
    components: {
        TradeCategoryNav,
        MarketSessionNotice, HongKongQuote,
        MarketChart,
        MarketDepth,
        MarketDirectory,
        StockAssetInfo,
        OrderbookChannel,
        MarketTrades,
        OrderForm,
        FuturesOrderForm,
        MarketStats,
        TButton,
        TextInput,
        AppLayout,
        OrderBook,
        OpenOrders,
        Trades,
        OrdersHistory,
        FuturesOpenOrders,
        BinanceSocket,
        MarketChannel,
    },
    mixins: [MarketSession, RiskDialog, MarketMixin],
    props: {
        stockAsset: Object,
        market: Object,
        quotes: [Array, Object],
        futures: Boolean,
        isMobile: Boolean,
        fee: String,
        futuresTimeframeEnabled: { type: Boolean, default: false },
        fundingRate: { type: String, default: null },
        fundingIntervalHours: { type: Number, default: 8 },
        nextFundingTime: { type: String, default: null },
        chart: { type: String, default: null },
    },
    data() {
        return {
            chartLoaded: false,
            showRiskModal: false,
            mobileFirstTab: 'trade',
            ticketInView: false,
            showMarkets: false,
            bookView: 'orderbook',
            viewportWidth: window.innerWidth,
            activeTheme: document.body.classList.contains('dark') ? 'dark' : 'light',
            chartIteration: 0,
            orderType: 'limit',
            activeOrderTab: 'open',
            iframeHeight: '400'
        }
    },
    beforeDestroy() {
        this.stopTicketPositioning?.();
        this.stopKlineChangeWatcher();
        this.$worker.$off('themeChanged', this.onThemeChanged);

        if (typeof window !== 'undefined') {
            window.removeEventListener('resize', this.onResize, { passive: true });
            window.removeEventListener('scroll', this.queueTicketVisibility);
            cancelAnimationFrame(this.ticketVisibilityFrame);
            window.visualViewport?.removeEventListener('resize', this.onResize);
            this.chartResizeObserver?.disconnect();
        }
    },
    computed: {
        chartUrl() {
            let symbol = this.market.data.name;
            let route = false;
            let timezone = encodeURIComponent(this.$page.props.timezone || 'Etc/UTC');

            if (this.market.data.chart_enabled == "liquidity") {
                symbol = this.market.data.sanitized_name;
                route = true;
            } else if (this.market.data.chart_enabled == "custom") {
                return "https://s.tradingview.com/widgetembed/?frameElementId=tradingview_c2536&theme=" + (this.activeTheme) + "&symbol=" + (this.market.data.custom_market_path) + "&hide_legend=1&saveimage=0&hide_side_toolbar=false&interval=D&symboledit=1&saveimage=1&toolbarbg=F1F3F6&studies=%5B%5D&hideideas=1&style=1&timezone=" + timezone + "&studies_overrides=%7B%7D&overrides=%7B%7D&enabled_features=%5B%5D&disabled_features=%5B%5D&locale=en&utm_medium=widget&utm_campaign=chart&disabled_features=%5B%22use_localstorage_for_settings%22%2C%22hide_left_toolbar_by_default%22%2C%22header_symbol_search%22%2C%22header_saveload%22%2C%22header_undo_redo%22%5D";
            }

            return this.route('chart', { symbol: symbol, route: route, theme: this.activeTheme });
        },
        markets: function () {
            return this.$store.getters.getMarkets;
        },
        spotOpenOrdersCount: function () {
            if (this.futures) return 0;

            const orders = this.$store.getters.getOpenOrders(null);
            return orders && orders.length ? orders.length : 0;
        },
        futuresPositionsCount: function () {
            if (!this.futures) return 0;

            const positions = this.$store.getters.getFuturesOpenOrders(null);
            return positions && positions.length ? positions.length : 0;
        },
        futuresLimitOrdersCount: function () {
            if (!this.futures) return 0;

            const orders = this.$store.getters.getFuturesOpenLimitOrders(null);
            return orders && orders.length ? orders.length : 0;
        },
    },
    created() {
        this.$store.dispatch("updateMarket", {market: this.market.data});
    },
    mounted() {
        if (this.futures && !this.market.data.has_futures) {
            return this.$inertia.visit(this.route('markets'));
        }

        this.loadFavorites();

        if (_.isEmpty(this.markets)) {
            this.$store.dispatch('fetchMarkets', this.route('markets.api.ticker'));
        }

        this.$worker.$on('themeChanged', this.onThemeChanged);

        window.globalMarket = this.market.data;

        this.onResize();
        window.addEventListener('resize', this.onResize, { passive: true });
        window.addEventListener('scroll', this.queueTicketVisibility, { passive: true });
        window.visualViewport?.addEventListener('resize', this.onResize);
        this.$nextTick(() => {this.chartResizeObserver=new ResizeObserver(this.onResize); const summary=this.$el.querySelector('.dp-workbench__summary');if(summary)this.chartResizeObserver.observe(summary);this.onResize()});



        this.startKlineChangeWatcher();
        const request = new URLSearchParams(window.location.search);
        const requestedSide = request.get('side');
        if (!this.futures && ['buy', 'sell'].includes(requestedSide)) this.openTicket(requestedSide);
    },
    methods: {
        openTicket(side) {
            if (this.sessionBlocked) return;
            this.mobileFirstTab = 'trade';
            this.$nextTick(() => {
                const form = this.$refs.tradeForm;
                if (form && ['buy', 'sell'].includes(side)) { form.setTab(side); form.openForm = false; }
                window.dispatchEvent(new Event('resize'));
                this.$nextTick(this.scrollToTicket);
            });
        },
        onThemeChanged(mode) { this.activeTheme = mode; this.chartIteration++; },
        setFirstTab(type) {
            this.stopTicketPositioning?.();
            this.mobileFirstTab = type;
            this.$nextTick(() => {
                window.dispatchEvent(new Event('resize'));
                if (type === 'trade') this.$nextTick(this.scrollToTicket);
                else this.queueTicketVisibility();
            });
        },
        scrollToTicket() {
            const ticket = this.$refs.tradeTicket;
            if (!ticket) return;
            this.stopTicketPositioning?.();
            this.stopTicketPositioning = positionTradeTicket(ticket, document, window, this.queueTicketVisibility);
        },
        queueTicketVisibility() {
            if (this.ticketVisibilityFrame) return;
            this.ticketVisibilityFrame = requestAnimationFrame(() => {
                this.ticketVisibilityFrame = null;
                const ticket = this.$refs.tradeTicket;
                this.ticketInView = this.mobileFirstTab === 'trade' && !!ticket
                    && ticketIsVisible(ticket.getBoundingClientRect(), tradeViewport(document, window));
            });
        },
        setOrderType(type) {
            this.orderType = type;
        },
        onResize() {
            this.viewportWidth = window.innerWidth;
            this.queueTicketVisibility();
            if (this.viewportWidth >= 768) { this.iframeHeight = '480'; return; }
            const chart=this.$el.querySelector('.dp-workbench__chart');
            const top=chart ? chart.getBoundingClientRect().top + window.scrollY : 220;
            const viewport=window.visualViewport?.height || window.innerHeight;
            const nav=this.$el.querySelector('.dp-bottom-nav');
            const dock=this.$el.querySelector('.dp-trade-dock');
            const reserved=(nav?.offsetHeight || 64)+(dock?.offsetHeight || 60)+88;
            this.iframeHeight=String(Math.round(Math.max(viewport<650?160:220,Math.min(480,viewport-top-reserved))));
        },
        setActiveOrderTab(tab) {
            this.activeOrderTab = tab;
        },
        toggleIframe() {
            this.chartLoaded = true;
        },
        goTradeRecords() {
            const url = this.futures ? '/reports/futures-trades' : '/reports/trades';

            this.$inertia.visit(url);
        }
    }
})
</script>

<style>
.dp-trading-layout .dp-receive-usdt-hint {margin:0;padding:10px 12px;border-bottom:1px solid var(--ui-divider,#edf0f4);color:var(--ui-text,#151c26);background:var(--ui-highlight,#f5f6f8);font-size:13px;line-height:1.5}

@media (max-width: 767px) {
    .dp-trading-layout .dp-workbench__summary .dp-quote__metrics dt {
        min-width: 0;
        flex: 1 1 auto;
    }
    .dp-trading-layout .dp-workbench__summary .dp-quote__metrics dd {
        flex: 0 0 auto;
        white-space: nowrap;
        word-break: normal;
        overflow-wrap: normal;
        font-variant-numeric: tabular-nums;
    }
}
</style>
