<script>
import Template from '{Template}/Web/Pages/Market/Partials/FuturesOpenOrders.template'
import {math_formatter} from "@/Functions/Math";

export default Template({
    props: {
        market: Object,
        tab: { type: String, default: 'positions' },
    },
    data() {
        return {
            openOrdersInterval: null,
            openFuturesOrdersInterval: null,
            limitOrdersInterval: null,
            openOrdersLoading: false,
            limitOrdersLoading: false,
            refreshFuturesOrdersHandler: null,
            visibilityChangeHandler: null,
            limit: 20,
            activeTab: 'positions', // 'positions' or 'orders'
        }
    },
    mounted() {

        this.activeTab = this.tab;

        if(this.$page.props.user) {
            this.fetchFuturesOpenOrders();
            this.fetchFuturesOpenLimitOrders();

            this.openFuturesOrdersInterval = setInterval(() => {
                this.fetchFuturesOpenOrders();
            }, 5000);

            this.limitOrdersInterval = setInterval(() => {
                this.fetchFuturesOpenLimitOrders();
            }, 5000);

            this.refreshFuturesOrdersHandler = () => {
                this.fetchFuturesOpenOrders(true);
                this.fetchFuturesOpenLimitOrders(true);
            };

            if (this.$worker) {
                this.$worker.$on('refresh-futures-orders', this.refreshFuturesOrdersHandler);
            }

            this.visibilityChangeHandler = () => {
                if (!document.hidden) {
                    this.fetchFuturesOpenOrders(true);
                    this.fetchFuturesOpenLimitOrders(true);
                }
            };

            document.addEventListener('visibilitychange', this.visibilityChangeHandler);
        }
    },
    beforeDestroy: function(){
        clearInterval(this.openFuturesOrdersInterval);
        clearInterval(this.limitOrdersInterval);

        if (this.$worker && this.refreshFuturesOrdersHandler) {
            this.$worker.$off('refresh-futures-orders', this.refreshFuturesOrdersHandler);
        }

        if (this.visibilityChangeHandler) {
            document.removeEventListener('visibilitychange', this.visibilityChangeHandler);
        }
    },
    computed: {
        futuresOrders: function () {
            // Get all positions (null market means all pairs)
            const allPositions = this.$store.getters.getFuturesOpenOrders(null);
            if (!allPositions || !allPositions.length) return [];
            return _.take(_.orderBy(allPositions, 'created_at', 'desc'), this.limit);
        },
        futuresPositions: function () {
            // Get all positions (null market means all pairs)
            const allPositions = this.$store.getters.getFuturesOpenOrders(null);
            if (!allPositions || !allPositions.length) return [];
            return _.take(_.orderBy(allPositions, 'created_at', 'desc'), this.limit);
        },
        futuresLimitOrders: function () {
            // Get all limit orders (null market means all pairs)
            const allOrders = this.$store.getters.getFuturesOpenLimitOrders(null);
            if (!allOrders || !allOrders.length) return [];
            return _.take(_.orderBy(allOrders, 'created_at', 'desc'), this.limit);
        },
        positionsCount: function () {
            const positions = this.$store.getters.getFuturesOpenOrders(null);
            return positions && positions.length ? positions.length : 0;
        },
        limitOrdersCount: function () {
            const orders = this.$store.getters.getFuturesOpenLimitOrders(null);
            return orders && orders.length ? orders.length : 0;
        },
    },
    methods: {
        humanizeCountdown(startAtStr) {
            // startAtStr format 'Y-m-d H:i:s' UTC server time assumed
            const start = new Date(startAtStr.replace(' ', 'T') + 'Z');
            const now = new Date();
            const diffMs = start.getTime() - now.getTime();
            if (diffMs <= 0) return this.$t('starting now');
            const sec = Math.floor(diffMs / 1000);
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = sec % 60;
            const pad = (n) => n.toString().padStart(2, '0');
            if (h > 0) return `${h}:${pad(m)}:${pad(s)}`;
            return `${m}:${pad(s)}`;
        },
        isTimelineActive(order) {
            return order.status == 'active';
        },
        humanizeEndsCountdown(order) {

            // Compute remaining time until auto-close
            const enabled = !!(this.$page && this.$page.props && this.$page.props.futuresTimeframeEnabled);
            if (!enabled) return null;
            if (!order || order.status !== 'active') return null;
            const total = Number(order.timeframe_seconds ?? order.timeframeSeconds ?? 0);
            if (!total || total <= 0) return null;
            const activatedAt = order.start_at;
            if (!activatedAt) return null;
            const activated = new Date(String(activatedAt).replace(' ', 'T') + 'Z');
            const now = new Date();
            const elapsed = Math.floor((now.getTime() - activated.getTime()) / 1000);
            let remaining = total - elapsed;
            if (remaining <= 0) remaining = 0;
            const pad = (n) => n.toString().padStart(2, '0');
            if (remaining <= 60) {
                return `${remaining}`; // seconds only
            }
            const m = Math.floor(remaining / 60);
            const s = remaining % 60;
            return `${m}:${pad(s)}`; // minutes:seconds
        },
        cancelOrder(order) {

            let form = {
                'uuid': order.id
            };

            let formRoute = this.route('orders.api.cancel');

            formRoute = this.route('orders.api.futures.cancel');

            axios.post(formRoute, form).then((response) => {
                this.$toast.open(this.$t('Order cancelled'));

                this.fetchFuturesOpenOrders();
                this.fetchFuturesOpenLimitOrders();

                /**
                 * 通知合约下单框强制重新请求 /wallets?context=futures。
                 * 下单框余额使用本地 futures 钱包快照，不再吃 Vuex 普通钱包，
                 * 所以平仓 / 取消后需要主动通知它刷新。
                 */
                if (this.$worker) {
                    this.$worker.$emit('refresh-futures-wallets');
                }
            }).catch(error => {

            });
        },
        setActiveTab(tab) {
            this.activeTab = tab;
        },
        fetchFuturesOpenOrders(force = false) {
            if (!force && document.hidden) {
                return Promise.resolve();
            }

            if (this.openOrdersLoading) {
                return Promise.resolve();
            }

            this.openOrdersLoading = true;

            // Pass null to fetch all positions for all pairs
            return this.$store.dispatch('fetchFuturesOpenOrders', {market: null, route: this.route('orders.api.futures.open')})
                .catch(() => {})
                .finally(() => {
                    this.openOrdersLoading = false;
                });
        },
        fetchFuturesOpenLimitOrders(force = false) {
            if (!force && document.hidden) {
                return Promise.resolve();
            }

            if (this.limitOrdersLoading) {
                return Promise.resolve();
            }

            this.limitOrdersLoading = true;

            // Pass null to fetch all limit orders for all pairs
            return this.$store.dispatch('fetchFuturesOpenLimitOrders', {market: null, route: this.route('orders.api.futures.orders')})
                .catch(() => {})
                .finally(() => {
                    this.limitOrdersLoading = false;
                });
        },
        getOrderMarket(order) {
            const marketName = order && order.market ? order.market : (this.market ? this.market.name : null);

            if (!marketName) {
                return this.market || null;
            }

            return this.$store.getters.getMarket(marketName) || this.market || null;
        },
        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const normalized = String(value).replace(/,/g, '');
            const number = Number(normalized);

            return Number.isFinite(number) ? number : 0;
        },
        getOrderQuotePrecision(order) {
            const orderMarket = this.getOrderMarket(order);

            if (orderMarket && orderMarket.quote_precision !== undefined) {
                return Number(orderMarket.quote_precision);
            }

            return 8;
        },
        getLiveMarketPrice(order) {
            const orderMarket = this.getOrderMarket(order);
            const storeLast = orderMarket ? this.toNumber(orderMarket.last) : 0;

            if (storeLast > 0) {
                return storeLast;
            }

            const orderMarketPrice = this.toNumber(order.market_price);

            if (orderMarketPrice > 0) {
                return orderMarketPrice;
            }

            return this.toNumber(order.price);
        },
        calculateLivePnl(order) {
            if (!order || order.status !== 'active') {
                return this.toNumber(order ? order.pnl : 0);
            }

            const quantity = this.toNumber(order.quantity);
            const entryPrice = this.toNumber(order.price);
            const marketPrice = this.getLiveMarketPrice(order);
            const leverage = this.toNumber(order.leverage);

            if (quantity <= 0 || entryPrice <= 0 || marketPrice <= 0 || leverage <= 0) {
                return this.toNumber(order.pnl);
            }

            const quotePnl = order.is_long
                ? quantity * (marketPrice - entryPrice)
                : quantity * (entryPrice - marketPrice);
            const margin = (quantity * entryPrice) / leverage;

            if (!margin) {
                return this.toNumber(order.pnl);
            }

            return Math.max((quotePnl / margin) * 100, -100);
        },
        getLivePnlAmount(order) {
            const balance = this.toNumber(order.balance);
            const pnl = this.calculateLivePnl(order);

            if (!balance) {
                return this.toNumber(order.pnlAmount);
            }

            return (balance / 100) * pnl;
        },
        formatLivePnlAmount(order) {
            return this.decimal_format(this.getLivePnlAmount(order), this.getOrderQuotePrecision(order));
        },
        formatLivePnl(order) {
            return this.decimal_format(this.calculateLivePnl(order), 3);
        },
        isLivePnlProfitable(order) {
            return this.calculateLivePnl(order) >= 0;
        },
        decimal_format(value, decimal, type = '') {

            let formatted = math_formatter(value, decimal);

            if(type == "fiat") {
                formatted = numeral(formatted).format('0,0.00');
            }

            return formatted;
        },
    },
    watch: {
        tab: function (type) {
            this.activeTab = type;
        },
    }
})
</script>
