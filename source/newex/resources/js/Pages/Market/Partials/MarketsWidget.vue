<script>
import Template from '{Template}/Web/Pages/Market/Partials/MarketsWidget.template'
import MarketMixin from '@/Mixins/Market/MarketMixin';
import TableFilter from "@/Mixins/Filter/TableFilter";
import IconFilter from "@/Components/Table/IconFilter";
import {math_formatter} from "@/Functions/Math";
import {state, subscribeStockQuotes, unsubscribeStockQuotes} from '@/Functions/StockQuotes';
import {resolveEstimateMarket} from '@/Functions/OrderEstimate.mjs';

export default Template({
    props: {
        quotes: [Array, Object],
        futures: Boolean
    },
    components: {
        IconFilter
    },
    data() {
        return {
            openOrdersInterval: null,
        }
    },
    computed: {
        markets: function () {

            let markets = _.map(this.$store.getters.getMarkets, (market) => {
                const display = !this.futures && market.stock_token
                    ? resolveEstimateMarket(market, state.quotes[market.base_currency], state.now)
                    : market;
                return {...display, favorite: this.isFavorite(market.name)};
            });

            if(markets && markets.length) {

                let orderMarkets = _.orderBy(markets, (market) => {

                    if (this.filter.filterBy == 'name') {
                        return market[this.filter.filterBy];
                    } else {
                        return parseFloat(market[this.filter.filterBy]);
                    }

                }, this.filter.filterDirection == 'desc' ? 'desc' : 'asc');

                let m = _.filter(orderMarkets, (market) => {
                    let sortedBy = true;

                    if (this.filter.sortBy == "favorites") {
                        sortedBy = market.favorite == true;
                    } else if (this.filter.sortBy) {
                        sortedBy = market.quote_currency == this.filter.sortBy;
                    }

                    return market.name.toLowerCase().includes(this.filter.search.toLowerCase()) && sortedBy;
                });

                return _.filter(m, (market) => {

                    let sortedBy = true;

                    if (this.futures) {
                        sortedBy = market.has_futures == true;
                    }

                    return market.name.toLowerCase().includes(this.filter.search.toLowerCase()) && sortedBy;
                });

            }

        },
    },
    mixins: [MarketMixin, TableFilter],
    mounted() {

        if (!this.futures) { this._stockListSubscribed = true; subscribeStockQuotes(); }
        this.setFilter('name', 'asc', true);

        if(_.isEmpty(this.markets)) {
            this.$store.dispatch('fetchMarkets', this.route('markets.api.ticker'));
        }
    },
    beforeDestroy() { if (this._stockListSubscribed) unsubscribeStockQuotes(); },
    methods: {
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
    },
})
</script>
