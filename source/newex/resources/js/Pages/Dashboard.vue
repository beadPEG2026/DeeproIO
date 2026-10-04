<script>
    import Template from '{Template}/Web/Pages/Dashboard.template'
    import AppLayout from '@/Layouts/AppLayout'
    import Welcome from '@/Jetstream/Welcome'
    import TableFilter from "@/Mixins/Filter/TableFilter";
    import LanguageSwitcher from '@/Components/LanguageSwitcher'
    import MarketMixin from "@/Mixins/Market/MarketMixin";
    import { Hooper, Slide } from 'hooper';
    import 'hooper/dist/hooper.css';
    import MarketChannel from "@/Store/Channels/Public/Market/MarketChannel";
    import MarqueeSlider from '@/Components/MarqueeSlider'
    export default Template({
        components: {
            AppLayout,
            Welcome,
            LanguageSwitcher,
            Hooper,
            Slide,
            MarketChannel,
            MarqueeSlider
        },

        data() {

            return {
                numAbbr: '',
                marketOrderBy: 'gainers',
                sliderElements: 4,
                isMobile: false,
                hooperSettings: {
                    itemsToShow: 4,
                    vertical: false,
                    autoPlay: true,
                    touchDrag: true,
                    mouseDrag: true,
                    wheelControl: false,
                    playSpeed: 3000,
                    infiniteScroll: true,
                    breakpoints: {
                        270: {
                            itemsToShow: 1,
                        },
                        700: {
                            itemsToShow: 1,
                        },
                        900: {
                            itemsToShow: 4,
                        },
                    }
                }
            }
        },
        props: {
            articles: Object,
        },
        created() {
            window.addEventListener("resize", this.sliderListener);
        },
        destroyed() {
            window.removeEventListener("resize", this.sliderListener);
        },
        methods: {
            sliderListener() {
                if(window.innerWidth >= 670) {
                    this.sliderElements = 3;
                    this.isMobile = false;
                } else {
                    this.sliderElements = 2;
                    this.isMobile = false;
                }
            },
            setArticle(article) {
                this.$inertia.visit(this.route('article', article.id));
            },
            formatNumber(volume, digits) {
                return numAbbr(volume, digits);
            }
        },
        computed: {
            sliderMarkets: function () {

                let markets = this.$store.getters.getMarkets;

                if(markets && markets.length) {
                    return _.orderBy(markets, (market) => {
                        return parseFloat(market['qVolume']);
                    }, 'desc');
                }
            },
            topMarkets: function () {

                let markets = this.$store.getters.getMarkets;

                if(markets && markets.length) {
                    return _.take(_.orderBy(markets, (market) => {
                        if(this.filter.filterBy == 'name') {
                            return market[this.filter.filterBy];
                        } else {
                            return parseFloat(market[this.filter.filterBy]);
                        }
                    }, this.filter.filterDirection == 'desc' ? 'desc' : 'asc'), 10);
                }
            },
        },
        mixins: [MarketMixin, TableFilter],
        mounted() {

            this.numAbbr = require('number-abbreviate');
            //this.sliderListener();


            if(_.isEmpty(this.markets)) {
                this.$store.dispatch('fetchMarkets', this.route('markets.api.ticker'));
            }

            this.$worker.$on('updateMarketWorker', (market) => {
                this.marketChangeWatcher(market);
            });

            if(!this.$page.props.alt) {
                window.TyperSetup();
            }

            this.setFilter('qVolume', 'desc', true);
        }
    });
</script>
