<script>
import Template from '{Template}/Web/Pages/Market/Partials/OptionsOpenOrders.template'
import {math_formatter} from "@/Functions/Math";


export default Template({
    props: {
        market: Object,
    },
    data() {
        return {
            openOrdersInterval: null,
            limit: 100,
        }
    },
    mounted() {
        if(this.$page.props.user) {
            this.fetchOpenOrders();
            this.openOrdersInterval = setInterval(() => {
                this.fetchOpenOrders();
            }, 1000);
        }
    },
    beforeDestroy: function(){
        clearInterval(this.openOrdersInterval)
    },
    computed: {
        orders: function () {
            return _.take(_.orderBy(this.$store.getters.getOptionsOpenOrders(this.market.name), 'created_at', 'desc'), this.limit);
        },
    },
    methods: {
        humanizeCountdown(startAtStr) {
            // startAtStr format 'Y-m-d H:i:s' UTC server time assumed
            const start = new Date(startAtStr.replace(' ', 'T') + 'Z');
            const now = new Date();
            const diffMs = start.getTime() - now.getTime();
            if (diffMs <= 0) return this.$t('starting now');
            const sec = Math.floor(diffMs / 1000);
            const h = Math.floor(sec / 3600);
            const m = Math.floor((sec % 3600) / 60);
            const s = sec % 60;
            const pad = (n) => n.toString().padStart(2, '0');
            if (h > 0) return `${h}:${pad(m)}:${pad(s)}`;
            return `${m}:${pad(s)}`;
        },
        fetchOpenOrders() {
            this.$store.dispatch('fetchOptionsOpenOrders', {market: this.market.name, route: this.route('options.api.open')});
        },
        decimal_format(value, decimal, type = '') {

            let formatted = math_formatter(value, decimal);

            if(type == "fiat") {
                formatted = numeral(formatted).format('0,0.00');
            }

            return formatted;
        },
        parseServerDate(dateStr) {
            if (!dateStr) return null;
            try {
                return new Date(String(dateStr).replace(' ', 'T') + 'Z');
            } catch (e) { return null; }
        },
        formatLocal(dt) {
            if (!dt) return '—';
            const pad = (n) => n.toString().padStart(2, '0');
            return `${pad(dt.getHours())}:${pad(dt.getMinutes())}:${pad(dt.getSeconds())}`;
        },
        formatStart(order) {
            const start = this.parseServerDate(order.created_at);
            return this.formatLocal(start);
        },
        formatEnd(order) {
            const start = this.parseServerDate(order.created_at);
            if (!start) return '—';
            const seconds = Number(order.period_text ?? 0);
            if (!seconds) return '—';
            const end = new Date(start.getTime() + seconds * 1000);
            return this.formatLocal(end);
        }
    }
})
</script>
