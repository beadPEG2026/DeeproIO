<script>
import Template from '{Template}/Web/Pages/Market/Partials/MarketStats.template'
import MarketsWidget from "@/Pages/Market/Partials/MarketsWidget";
import {math_formatter} from "@/Functions/Math";
import vClickOutside from 'v-click-outside'
import MarketMixin from '@/Mixins/Market/MarketMixin';
import OrderEstimate from '@/Mixins/Market/OrderEstimate';
import {finiteMetric} from '@/Functions/TradingDisplay.mjs';

export default Template({
    components: {
        MarketsWidget,
    },
    props: {
        market: Object,
        quotes: [Array, Object],
        futures: Boolean,
        fundingRate: {
            type: String,
            default: null
        },
        fundingIntervalHours: {
            type: Number,
            default: 8
        },
        nextFundingTime: {
            type: String,
            default: null
        }
    },
    mixins: [MarketMixin, OrderEstimate],
    created() {
        if(parseFloat(this.market.last) > 0) {
            setTimeout(() => {
                if(!this.stopTick) {
                    document.title = this.market.last + ' | ' + this.getTitle();
                }
            }, 3000);
        }
    },
    mounted() {
        this.loadFavorites();
        this.favorite = this.isFavorite(this.market.name);
        this.marketUpdateHandler = (market) => {
            if(market.name == this.market.name) {
                this.marketChangeWatcher(market);
            }
        };
        this.$worker.$on('updateMarketWorker', this.marketUpdateHandler);
        if (this.futures && this.fundingRate) {
            this.updateTimer();
            this.timer = setInterval(() => {
                this.updateTimer();
            }, 1000);
        }
    },
    beforeDestroy () {
        this.stopTick = true;
        this.$worker.$off('updateMarketWorker', this.marketUpdateHandler);
        if (this.timer) {
            clearInterval(this.timer);
        }
    },
    computed: {
        categories() {return [['is_layer_one','Layer 1'],['is_layer_two','Layer 2'],['is_defi','DeFi'],['is_ai','AI'],['is_meme','Meme'],['stock_token','Stock tokens']].filter(([key]) => this.market[key] === true || Number(this.market[key]) === 1).map(([,label]) => label);},
        market_stats: function () {
            return this.displayMarketStats;
        },
        fundingRatePercent() {
            const rate = parseFloat(this.fundingRate || 0) * 100;
            return rate.toFixed(4);
        },
        fundingRateDisplay() {
            const rate = parseFloat(this.fundingRate || 0);
            if (rate > 0) {
                return `+${this.fundingRatePercent}%`;
            }
            return `${this.fundingRatePercent}%`;
        }
    },
    data() {
        return {
            favorite: false,
            marketWidgetVisible: false,
            prevPageTitle: '',
            reloadPageTitle: false,
            timeRemaining: '',
            timer: null
        }
    },
    directives: {
        clickOutside: vClickOutside.directive
    },
    methods: {
        hasMetric: finiteMetric,
        safeLink(value) {return typeof value === 'string' && /^https?:\/\//i.test(value);},
        compact(value) {return Number(value).toLocaleString(this.$i18n.locale, {maximumFractionDigits: 2});},
        toggleFavorite() {this.favoriteToggle({...this.market}); this.favorite = this.isFavorite(this.market.name);},
    	getTitle() {
            return this.market.name + ' ' + this.$t('Market') + ' · Deepro';
        },
        onClickOutside (event) {
            if(event.target.className !== "market-label") {
                this.marketWidgetVisible = false;
            }
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
        toggleMarketWidget() {
            this.marketWidgetVisible = !this.marketWidgetVisible;
        },
        updateTimer() {
            if (!this.nextFundingTime) {
                this.timeRemaining = '--:--:--';
                return;
            }

            const now = new Date();
            const next = new Date(this.nextFundingTime);
            const diff = next - now;

            if (diff <= 0) {
                this.timeRemaining = '00:00:00';
                return;
            }

            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            this.timeRemaining = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }
    },
    watch: {
        market_stats(market) {
            document.title = (Number(market.last) > 0 ? this.decimal_format(market.last, this.market.quote_precision) + ' | ' : '') + this.getTitle();
        },
    }
});
</script>
