<script>
import {localDateTime} from '@/Functions/UserDisplay.mjs';
import Template from '{Template}/Web/Pages/Market/Partials/OpenOrders.template'
import {math_formatter} from "@/Functions/Math";
import Vue from "vue";

export default Template({
    props: {
        market: Object,
    },
    data() {
        return {
            openOrdersInterval: null,
            limit: 20,
        }
    },
    mounted() {
        if(this.$page.props.user) {
            this.fetchOpenOrders();

            this.openOrdersInterval = setInterval(() => {
                this.fetchOpenOrders();
            }, 2000);
        }
    },
    watch: {
        market: {
            handler() {
                if(this.$page.props.user) {
                    this.fetchOpenOrders();
                }
            },
            deep: true
        }
    },
    beforeDestroy: function(){
        clearInterval(this.openOrdersInterval)
    },
    computed: {
        orders: function () {
            // Get all orders (null market means all pairs)
            const allOrders = this.$store.getters.getOpenOrders(null);
            if (!allOrders || !allOrders.length) return [];
            return _.take(_.orderBy(allOrders, 'created_at', 'desc'), this.limit);
        },
        ordersCount: function () {
            const orders = this.$store.getters.getOpenOrders(null);
            return orders && orders.length ? orders.length : 0;
        },
    },
    methods: {
        localDateTime(value) { return localDateTime(value, this.$page.props.timezone); },
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
