<script>
import Template from '{Template}/Web/Pages/Market/Partials/FundingRateInfo.template'

export default Template({
    props: {
        fundingRate: {
            type: String,
            default: '0.01'
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
    data() {
        return {
            timeRemaining: '',
            timer: null
        }
    },
    computed: {
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
        },
        fundingRateColor() {
            const rate = parseFloat(this.fundingRate || 0);
            if (rate > 0) {
                return 'positive'; // Longs pay
            } else if (rate < 0) {
                return 'negative'; // Shorts pay
            }
            return 'neutral';
        }
    },
    mounted() {
        this.updateTimer();
        this.timer = setInterval(() => {
            this.updateTimer();
        }, 1000);
    },
    beforeDestroy() {
        if (this.timer) {
            clearInterval(this.timer);
        }
    },
    methods: {
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
                // Timer reached zero - next funding fee processing will update the time
                return;
            }

            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            this.timeRemaining = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }
    }
});
</script>
