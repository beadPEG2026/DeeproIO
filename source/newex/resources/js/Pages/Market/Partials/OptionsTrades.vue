<script>
import Template from '{Template}/Web/Pages/Market/Partials/OptionsTrades.template'
import {math_formatter} from "@/Functions/Math";

export default Template({
    components: {

    },
    props: {
        market: Object,
    },
    data() {
        return {
            limit: 30,
            fetchInterval: null,
        }
    },
    mounted() {
        if(this.$page.props.user) {
            this.fetchOptionsTrades();
            this.fetchInterval = setInterval(() => {
                this.fetchOptionsTrades();
            }, 3000);
        }
    },
    beforeDestroy() {
        clearInterval(this.fetchInterval);
    },
    computed: {
        options: function () {
            return _.take(this.$store.getters.getOptionsTrades(this.market.name), this.limit);
        },
    },
    methods: {
        fetchOptionsTrades() {
            this.$store.dispatch('fetchOptionsTrades', { market: this.market.name, route: this.route('options.api.trades') });
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
        parseTime(date) {
            const time = String(date || '').match(/\d{2}:\d{2}:\d{2}/);

            if (time) {
                return time[0];
            }

            return moment(date).format('HH:mm:ss');
        },
    },
})
</script>
