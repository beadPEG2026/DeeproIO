<script>
import {localTradeTime} from '@/Functions/UserDisplay.mjs';
import Template from '{Template}/Web/Pages/MarketLite/Partials/MarketTrades.template'
import {math_formatter} from "@/Functions/Math";
import BinanceTradesSocket from "../../../Jetstream/BinanceTradesSocket";
import StockTradePolling from '@/Mixins/StockTradePolling.mjs';

export default Template({
    mixins: [StockTradePolling],
    components: {
        BinanceTradesSocket
    },
    props: {
        market: Object,
    },
    data() {
        return {
            limit: 30,
            fetchInterval: null,
            historicalTradesFailed: false,
        }
    },
    mounted() {
        if (this.market.stock_token) return;
        if(this.market.s) {
            this.fetchHistoricalTrades();
        } else {
            this.$store.dispatch('fetchMarketTrades', { market: this.market.name, route: this.route('markets.api.trades') });
        }
    },
    beforeDestroy() {
        clearInterval(this.fetchInterval);
        this._historicalTradesRequest?.cancel();
    },
    computed: {
        trades: function () {
            if (this.market.stock_token) return this.stockTrades;
            return _.take(this.$store.getters.getMarketTrades(this.market.name).slice().reverse(), this.limit);
        },
    },
    methods: {
        fetchHistoricalTrades() {
            this._historicalTradesRequest?.cancel();
            const request = axios.CancelToken.source();
            this._historicalTradesRequest = request;
            this.historicalTradesFailed = false;
            return axios.get(this.route('markets.api.historical.trades'), {
                cancelToken: request.token,
                params: {
                    market: this.market.name
                }
            }).then((response) => {
                if(response.data.success) {
                    this.$store.dispatch('setMarketTrades', {market: this.market.name, trades: response.data.trades});
                }
            }).catch(error => {
                if (!axios.isCancel(error) && !this._isBeingDestroyed && !this._isDestroyed) this.historicalTradesFailed = true;
            });
        },
        //markets.api.historical.trades
        decimal_format(value, decimal, type = '') {

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
        parseTime(date) {
            return localTradeTime(date, this.$page.props.timezone);

        },

    },
})
</script>
