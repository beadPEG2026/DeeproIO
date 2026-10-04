<script>
import Template from '{Template}/Web/Pages/Market/Partials/MarketsOptionsWidget.template'
import MarketMixin from '@/Mixins/Market/MarketMixin';
import TableFilter from "@/Mixins/Filter/TableFilter";
import IconFilter from "@/Components/Table/IconFilter";
import {math_formatter} from "@/Functions/Math";

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
                market['favorite'] = this.isFavorite(market.name);
                return market;
            });

            if(markets && markets.length) {

                let orderMarkets = _.orderBy(markets, (market) => {

                    if (this.filter.filterBy == 'name') {
                        return market[this.filter.filterBy];
                    } else {
                        return parseFloat(market[this.filter.filterBy]);
                    }

                }, this.filter.filterDirection == 'desc' ? 'desc' : 'asc');

                return _.filter(orderMarkets, (market) => {

                    let sortedBy = true;

                    sortedBy = market.has_options == true;

                    return market.name.toLowerCase().includes(this.filter.search.toLowerCase()) && sortedBy;
                });

            }

        },
    },
    mixins: [MarketMixin, TableFilter],
    mounted() {

        this.setFilter('name', 'asc', true);

        if(_.isEmpty(this.markets)) {
            this.$store.dispatch('fetchMarkets', this.route('markets.api.ticker'));
        }
    },
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
