<script>
import OrderEstimate from '@/Mixins/Market/OrderEstimate';
import Template from '{Template}/Web/Pages/Market/Partials/OrderBook.template'
import {math_formatter, math_percentage_of_number, abbrNum} from "@/Functions/Math";

export default Template({
    mixins: [OrderEstimate],
    props: {
        market: Object,
    },
    data() {
        return {
            limit: 14,
            bookBusy: false, bookFailed: false,
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
            buyPressure: 50,
            sellPressure: 50,
            groupingStep: null,
            showGroupingDropdown: false,
        }
    },
    mounted() {
        if(!this.market.s) {
            this.fetchOrderbook();
        }
        this.initializeGrouping();
        document.addEventListener('click', this.closeGroupingDropdown);
    },
    beforeDestroy() {
        clearInterval(this.fetchInterval);
        document.removeEventListener('click', this.closeGroupingDropdown);
    },
    computed: {
        minStep() {
            const precision = this.market.quote_precision || 2;
            return 1 / Math.pow(10, precision);
        },
        groupingOptions() {
            const precision = this.market.quote_precision || 2;
            const options = [];
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
        currentGroupingLabel() {
            const step = this.groupingStep !== null ? this.groupingStep : this.minStep;
            if (step >= 1) {
                return step.toString();
            }
            const decimals = Math.ceil(-Math.log10(step));
            return step.toFixed(decimals);
        },
        effectivePricePrecision() {
            const step = this.groupingStep !== null ? this.groupingStep : this.minStep;
            if (step >= 1) {
                return 0;
            }
            return Math.ceil(-Math.log10(step));
        },
        rawBids: function () {
            return _.orderBy(this.$store.getters.getOrderbook(this.market.name, 'bids'), (item) => {
                return parseFloat(item.price);
            }, 'desc');
        },
        rawAsks: function () {
            return _.orderBy(this.$store.getters.getOrderbook(this.market.name, 'asks'), (item) => {
                return parseFloat(item.price);
            }, 'asc');
        },
        bids: function () {
            const grouped = this.groupOrders(this.rawBids, 'bids');
            return _.take(grouped, this.limit);
        },
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
                let groupedPrice;
                if (side === 'bids') {
                    groupedPrice = Math.floor(price / step) * step;
                } else {
                    groupedPrice = Math.ceil(price / step) * step;
                }
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
        fetchOrderbook() {
            this.$store.dispatch('fetchOrders', { market: this.market.name, route: this.route('markets.api.orderbook') });
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
            return abbrNum(value);
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
            this.$worker.$emit('place-order', {
                order: order,
                side: side,
            });
        },
        isMyOrderPrice(price, side) {
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
