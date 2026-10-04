<script>
import Template from '{Template}/Web/Pages/MarketLite/Partials/OpenOrders.template'
import {math_formatter} from "@/Functions/Math";
import Vue from "vue";

export default Template({
    props: {
        market: Object,
        futures: Boolean,
    },
    data() {
        return {
            openOrdersInterval: null,
            limit: 20,
        }
    },
    mounted() {
        if(this.$page.props.user) {
            if(this.futures) {
                this.fetchFuturesOpenOrders();
                // this.openFuturesOrdersInterval = setInterval(() => {
                //     this.fetchFuturesOpenOrders();
                // }, 2000);
            } else {
                this.fetchOpenOrders();
                // this.openOrdersInterval = setInterval(() => {
                //     this.fetchOpenOrders();
                // }, 5000);
            }
        }
    },
    // beforeDestroy: function(){
    //     clearInterval(this.openOrdersInterval)
    //     clearInterval(this.openFuturesOrdersInterval)
    // },
    computed: {
        orders: function () {
            // Get all orders (null market means all pairs)
            const allOrders = this.$store.getters.getOpenOrders(null);
            if (!allOrders || !allOrders.length) return [];
            return _.take(_.orderBy(allOrders, 'created_at', 'desc'), this.limit);
        },
        futuresOrders: function () {
            // Get all positions (null market means all pairs)
            const allPositions = this.$store.getters.getFuturesOpenOrders(null);
            if (!allPositions || !allPositions.length) return [];
            return _.take(_.orderBy(allPositions, 'created_at', 'desc'), this.limit);
        },
    },
    methods: {
        cancelOrder(order) {

            let form = {
                'uuid': order.id
            };

            let formRoute = this.route('orders.api.cancel');

            let orders = this.$store.getters.getOpenOrders(null);

            let findCancelledOrder = orders.map(function (x) {
                return x.id;
            }).indexOf(order.id);

            if (findCancelledOrder > -1) {
                Vue.delete(orders, findCancelledOrder);
            }

            axios.post(formRoute, form).then((response) => {
                this.$toast.open(this.$t('Order cancelled'));
            }).catch(error => {

            });
        },
        fetchOpenOrders() {
            // Pass null to fetch all orders for all pairs
            this.$store.dispatch('fetchOpenOrders', {market: null, route: this.route('orders.api.open')});
        },
        fetchFuturesOpenOrders() {
            // Pass null to fetch all positions for all pairs
            this.$store.dispatch('fetchFuturesOpenOrders', {market: null, route: this.route('orders.api.futures.open')});
        },
        decimal_format(value, decimal, type = '') {

            let formatted = math_formatter(value, decimal);

            if(type == "fiat") {
                formatted = numeral(formatted).format('0,0.00');
            }

            return formatted;
        },
    }
})
</script>
