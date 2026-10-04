<script>
import MarketDisplay from "@/Mixins/Market/MarketDisplay.vue";
import Template from '{Template}/Web/Pages/Market/Partials/MarketsTrends.template'
import MarketMixin from '@/Mixins/Market/MarketMixin';
import TableFilter from "@/Mixins/Filter/TableFilter";

export default Template({
    components: {

    },
    props: {

    },
    mixins: [MarketDisplay, MarketMixin, TableFilter],
    created() {

    },
    mounted() {
        this.$worker.$on('updateMarketWorker', (market) => {
            this.marketChangeWatcher(market);
        });
    },
    beforeDestroy () {

    },
    computed: {
        hotMarkets: function () {
            return _.take(_.orderBy(this.displayMarkets, (market) => {
                return parseFloat(market['qVolume']);
            }, 'desc'), 3);
        },
        gainersMarkets: function () {
            return _.take(_.orderBy(this.displayMarkets.filter(m=>m.last != null), (market) => {
                return parseFloat(market['change']);
            }, 'desc'), 3);
        },
        losersMarkets: function () {
            return _.take(_.orderBy(this.displayMarkets.filter(m=>m.last != null), (market) => {
                return parseFloat(market['change']);
            }, 'asc'), 3);
        },
        newMarkets: function () {
            return _.take(_.orderBy(this.displayMarkets, (market) => {
                return parseFloat(market['listed_at']);
            }, 'desc'), 3);
        },
    },
    data() {
        return {

        }
    },
    methods: {

    },
});
</script>
