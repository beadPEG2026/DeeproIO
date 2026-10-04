<script>
import Template from '{Template}/Web/Pages/MarketLite/Partials/MarketSelector.template'
import MarketMixin from '@/Mixins/Market/MarketMixin'

export default Template({
    name: 'MarketSelector',
    mixins: [MarketMixin],
    props: {
        currentMarket: {
            type: Object,
            required: true
        },
        futures: {
            type: Boolean,
            default: false
        }
    },
    data() {
        return {
            keyword: '',
            tab: 'all',
            refreshKey: 0,
        }
    },
    computed: {
        markets() {
            return this.$store.getters.getMarkets || [];
        },
        currentTabText() {
            return this.futures ? this.$t('Futures') : this.$t('Spot');
        },
        filteredMarkets() {
            let list = this.markets || [];

            if (this.tab === 'favorites') {
                list = list.filter(item => this.isFavorite(item.name));
            }

            if (this.keyword) {
                const keyword = this.keyword.toLowerCase();
                list = list.filter(item => {
                    const name = (item.name || '').toLowerCase();
                    const base = (item.base_currency || '').toLowerCase();
                    const quote = (item.quote_currency || '').toLowerCase();

                    return (
                        name.includes(keyword) ||
                        base.includes(keyword) ||
                        quote.includes(keyword)
                    );
                });
            }

            return list;
        }
    },
    mounted() {
        this.loadFavorites();

        if (_.isEmpty(this.markets)) {
            this.$store.dispatch('fetchMarkets', this.route('markets.api.ticker'));
        }
    },
    methods: {
        selectMarket(market) {
            if (this.futures) {
                this.$inertia.visit(this.route('futures-market', market.name));
            } else {
                this.$inertia.visit(this.route('market', market.name));
            }

            this.$emit('close');
        },
        toggleSelectorFavorite(market) {
            this.favoriteToggle(market);
            this.refreshKey++;
            this.$forceUpdate();
        }
    }
})
</script>