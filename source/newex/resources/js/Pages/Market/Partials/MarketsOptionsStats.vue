<script>
import Template from '{Template}/Web/Pages/Market/Partials/MarketOptionsStats.template'
import MarketsOptionsWidget from "@/Pages/Market/Partials/MarketsOptionsWidget";
import {math_formatter} from "@/Functions/Math";
import vClickOutside from 'v-click-outside'

export default Template({
    components: {
        MarketsOptionsWidget,
    },
    props: {
        market: Object,
        quotes: [Array, Object],
        futures: Boolean,
    },
    created() {
        if(parseFloat(this.market.last) > 0) {
            setTimeout(() => {
                if(!this.stopTick) {
                    document.title = this.market.last + ' | ' + this.getTitle();
                }
            }, 3000);
        }
    },
    beforeDestroy () {
        this.stopTick = true;
    },
    computed: {
        market_stats: function () {
            return this.$store.getters.getMarket(this.market.name) ?? this.market;
        },
    },
    data() {
        return {
            marketWidgetVisible: false,
            prevPageTitle: '',
            reloadPageTitle: false,
        }
    },
    directives: {
        clickOutside: vClickOutside.directive
    },
    methods: {
    	getTitle() {
            return this.market.name + ' ' + this.$t('Market');
        },
        onClickOutside (event) {
            if(event.target.className !== "market-label") {
                this.marketWidgetVisible = false;
            }
        },
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
        toggleMarketWidget() {
            this.marketWidgetVisible = !this.marketWidgetVisible;
        }
    },
    watch: {
        market_stats(market) {
            document.title = market.last + ' | ' + this.getTitle();
        },
    }
});
</script>
