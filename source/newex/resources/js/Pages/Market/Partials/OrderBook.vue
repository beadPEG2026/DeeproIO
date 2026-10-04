<script>
import OrderEstimate from '@/Mixins/Market/OrderEstimate';
import Template from '{Template}/Web/Pages/Market/Partials/OrderBook.template'
import {math_formatter, math_percentage_of_number, abbrNum} from "@/Functions/Math";
import MarketMixin from '@/Mixins/Market/MarketMixin';

export default Template({
    props: {
        market: Object,
    },
    data() {
        return {
            limit: 50,
            bookBusy: false,
            bookLoaded: false,
            clock: Date.now(),
            pressureAt: 0,
            bookFailed: false,
            fetchInterval: null,
            orderBookMode: 'full',
            scrollOps: {
                vuescroll: {},
                scrollPanel: {
                    initialScrollY: '100%'
                },
                rail: {},
                bar: {}
            },
            buyPressure: null,
            sellPressure: null,
            groupingStep: null, // The step size for grouping (e.g., 0.01, 0.1, 1, 10, 100)
            showGroupingDropdown: false,
        }
    },
    mixins: [MarketMixin, OrderEstimate],
    mounted() {

        this.fetchOrderbook();
        this.clockTimer = setInterval(() => {this.clock=Date.now()},1000);
         this.fetchInterval = setInterval(() => {
            this.fetchOrderbook();
        }, 10000);

        this.marketUpdateHandler = (market) => {
            if(market.name == this.market.name) {
                this.marketChangeWatcher(market);
            }
        };
        this.$worker.$on('updateMarketWorker', this.marketUpdateHandler);

        this.pressureHandler = (data) => {
            if (data.market !== this.market.name || !Number.isFinite(Number(data.buy)) || !Number.isFinite(Number(data.sell)) || Number(data.buy)<0 || Number(data.sell)<0 || Math.abs(Number(data.buy)+Number(data.sell)-100)>0.1) return;
            this.pressureAt=Date.now();
            this.buyPressure = data.buy;
            this.sellPressure = data.sell;
        };
        this.$worker.$on('marketTradePressure', this.pressureHandler);

        // Initialize grouping based on market precision
        this.initializeGrouping();

        // Close dropdown when clicking outside
        document.addEventListener('click', this.closeGroupingDropdown);
    },
    beforeDestroy() {
        clearInterval(this.fetchInterval);
        clearInterval(this.clockTimer);
        this.$worker.$off('updateMarketWorker', this.marketUpdateHandler);
        this.$worker.$off('marketTradePressure', this.pressureHandler);
        document.removeEventListener('click', this.closeGroupingDropdown);
    },
    computed: {
        bookStatus(){return this.$store.getters.getOrderbookStatus(this.market.name)},
        bookStale(){return this.bookStatus.state==='stale' || (this.bookStatus.received && this.clock-this.bookStatus.received>30000)},
        pressureReady(){return !this.bookStale && !this.bookFailed && (this.bids.length || this.asks.length) && this.pressureAt>0 && this.clock-this.pressureAt<30000},
        emptyMessage(){return !this.bookLoaded && this.bookBusy ? this.$t('Loading…') : this.bookStale ? (this.$i18n.locale.startsWith('zh')?'深度数据更新中':'Waiting for fresh depth') : null},
        // Minimum step based on market quote precision (e.g., precision=2 means 0.01)
        minStep() {
            if (this.market.stock_token) return Number(this.market.quote_ticker_size)>0 ? Number(this.market.quote_ticker_size) : 0.01;
            const precision = this.market.quote_precision || 2;
            return 1 / Math.pow(10, precision);
        },
        
        // Available grouping options: from minStep up to larger values (0.01, 0.1, 1, 10, 100, etc.)
        groupingOptions() {
            const precision = this.market.quote_precision || 2;
            const options = [];
            
            // Start from market precision and go UP (less precise, larger steps)
            // e.g., for precision=2: 0.01, 0.1, 1, 10, 100
            // e.g., for precision=5: 0.00001, 0.0001, 0.001, 0.01, 0.1, 1
            for (let i = precision; i >= -2; i--) {
                const step = 1 / Math.pow(10, i);
                const decimals = Math.max(0, i);
                options.push({
                    value: step,
                    label: step >= 1 ? step.toString() : step.toFixed(decimals)
                });
            }
            
            return options;
        },
        
        // Current grouping label for display
        currentGroupingLabel() {
            const step = this.groupingStep !== null ? this.groupingStep : this.minStep;
            if (step >= 1) {
                return step.toString();
            }
            // Calculate decimal places needed
            const decimals = Math.ceil(-Math.log10(step));
            return step.toFixed(decimals);
        },
        
        // Effective price precision (decimal places) based on grouping step
        effectivePricePrecision() {
            const step = this.groupingStep !== null ? this.groupingStep : this.minStep;
            if (step >= 1) {
                return 0;
            }
            return Math.ceil(-Math.log10(step));
        },
        
        // Raw bids from store
        rawBids: function () {
            if (this.bookStale || this.bookFailed) return [];
            return _.orderBy(this.$store.getters.getOrderbook(this.market.name, 'bids'), (item) => {
                return parseFloat(item.price);
            }, 'desc');
        },
        
        // Raw asks from store
        rawAsks: function () {
            if (this.bookStale || this.bookFailed) return [];
            return _.orderBy(this.$store.getters.getOrderbook(this.market.name, 'asks'), (item) => {
                return parseFloat(item.price);
            }, 'asc');
        },
        
        // Grouped/aggregated bids
        bids: function () {
            const grouped = this.groupOrders(this.rawBids, 'bids');
            return _.take(grouped, this.limit);
        },
        
        // Grouped/aggregated asks
        asks: function () {
            const grouped = this.groupOrders(this.rawAsks, 'asks');
            return _.take(grouped, this.limit).reverse();
        },
        
        market_stats: function () {
            return this.displayMarketStats;
        },
        sumBuyQuantity: function () {
            return _.sumBy(_.take(this.bids, this.limit), function(order) { return parseFloat(order.quantity * order.price); });
        },
        sumSellQuantity: function () {
            return _.sumBy(_.take(this.asks, this.limit), function(order) { return parseFloat(order.quantity); });
        },
        myOrders: function () {
            return _.take(_.orderBy(this.$store.getters.getOpenOrders(this.market.name), 'created_at', 'desc'), this.limit);
        },
    },
    methods: {
        initializeGrouping() {
            // Set default grouping to market's minimum step (most precise)
            this.groupingStep = this.minStep;
        },
        
        groupOrders(orders, side) {
            const step = this.groupingStep !== null ? this.groupingStep : this.minStep;
            
            if (!orders || orders.length === 0) {
                return [];
            }
            
            const groupedMap = new Map();
            
            orders.forEach(order => {
                const price = parseFloat(order.price);
                const quantity = parseFloat(order.quantity);
                
                // Round price to grouping step level
                // For bids (buy orders): round DOWN to nearest step
                // For asks (sell orders): round UP to nearest step
                let groupedPrice;
                if (side === 'bids') {
                    groupedPrice = Math.floor(price / step + 1e-8) * step;
                } else {
                    groupedPrice = Math.ceil(price / step - 1e-8) * step;
                }
                
                // Create a consistent key with appropriate precision
                const priceKey = groupedPrice.toFixed(this.effectivePricePrecision);
                
                if (groupedMap.has(priceKey)) {
                    const existing = groupedMap.get(priceKey);
                    existing.quantity = parseFloat(existing.quantity) + quantity;
                    existing.orderCount = (existing.orderCount || 1) + 1;
                } else {
                    groupedMap.set(priceKey, {
                        price: groupedPrice,
                        quantity: quantity,
                        orderCount: 1
                    });
                }
            });
            
            // Convert map to array and sort
            let result = Array.from(groupedMap.values());
            
            if (side === 'bids') {
                result = _.orderBy(result, item => parseFloat(item.price), 'desc');
            } else {
                result = _.orderBy(result, item => parseFloat(item.price), 'asc');
            }
            
            return result;
        },
        
        setGrouping(step) {
            this.groupingStep = step;
            this.showGroupingDropdown = false;
        },
        
        toggleGroupingDropdown(event) {
            event.stopPropagation();
            this.showGroupingDropdown = !this.showGroupingDropdown;
        },
        
        closeGroupingDropdown(event) {
            if (!event.target.closest('.grouping-dropdown')) {
                this.showGroupingDropdown = false;
            }
        },
        
        async fetchOrderbook() {
            if (this.bookBusy || document.hidden) return;
            this.bookBusy=true;
            try { this.bookFailed = !await this.$store.dispatch('fetchOrders', { market: this.market.name, route: this.route('markets.api.orderbook') }); }
            finally { this.bookBusy=false; this.bookLoaded=true; }
        },
        decimal_format(value, decimal, type = '') {
            if (value === null || value === undefined) return '—';

            let formatted = math_formatter(value, decimal);

            if(type == "fiat") {
                if(decimal == 3) {
                    formatted = numeral(formatted).format('0,0.000');
                } else {
                    formatted = numeral(formatted).format('0,0.00');
                }
            }

            return formatted;
        },
        abbr_num(value) {
            return String(abbrNum(value)).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');
        },
        calculateAmountBar(order, side) {

            let total = '';
            let amount = '';

            if(side == 'buy') {
                total = this.sumBuyQuantity;
                amount = parseFloat(order.quantity * order.price);
            } else {
                total = this.sumSellQuantity;
                amount = order.quantity;
            }

            return math_percentage_of_number(amount, total) + '%';
        },
        handleOrder(order, side) {
            if (this.bookStale || this.bookFailed) return;
            this.$worker.$emit('place-order', {
                order: order,
                side: side,
            });
        },
        isMyOrderPrice(price, side) {
            // Check if any of my orders fall within this grouped price level
            const step = this.groupingStep !== null ? this.groupingStep : this.minStep;
            const groupedPrice = side === 'buy' 
                ? Math.floor(parseFloat(price) / step) * step
                : Math.ceil(parseFloat(price) / step) * step;
            
            return this.myOrders.find(x => {
                const orderGroupedPrice = x.side === 'buy'
                    ? Math.floor(parseFloat(x.price) / step) * step
                    : Math.ceil(parseFloat(x.price) / step) * step;
                return Math.abs(orderGroupedPrice - groupedPrice) < step * 0.001 && x.side === side;
            });
        },
        setOrderbookMode(mode) {
            this.orderBookMode = mode;
        }
    }
})
</script>
