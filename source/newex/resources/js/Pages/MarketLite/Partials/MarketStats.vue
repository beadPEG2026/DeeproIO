<script>
import OrderEstimate from '@/Mixins/Market/OrderEstimate';
import Template from '{Template}/Web/Pages/Market/Partials/MarketStats.template'
import MarketsWidget from "@/Pages/Market/Partials/MarketsWidget";
import {math_formatter} from "@/Functions/Math";
import vClickOutside from 'v-click-outside'

export default Template({
    mixins: [OrderEstimate],
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
    computed: {
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
            marketWidgetVisible: false,
            timeRemaining: '',
            timer: null
        }
    },
    directives: {
        clickOutside: vClickOutside.directive
    },
    methods: {
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
    mounted() {
        if (this.futures && this.fundingRate) {
            this.updateTimer();
            this.timer = setInterval(() => {
                this.updateTimer();
            }, 1000);
        }
    },
    beforeDestroy() {
        if (this.timer) {
            clearInterval(this.timer);
        }
    }
});
</script>
