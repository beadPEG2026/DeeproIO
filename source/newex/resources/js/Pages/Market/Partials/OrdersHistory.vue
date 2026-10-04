<script>
import {localDateTime} from '@/Functions/UserDisplay.mjs';
import Template from '{Template}/Web/Pages/Market/Partials/OrdersHistory.template'
import {math_formatter} from "@/Functions/Math";
import Vue from "vue";

export default Template({
    props: {
        market: Object,
    },
    data() {
        return {
            orders: null,
            ordersInterval: null,
            limit: 20,
        }
    },
    mounted() {
        if(this.$page.props.user) {
            this.fetchOrders();

            this.ordersInterval = setInterval(() => {
                this.fetchOrders();
            }, 5000)
        }
    },
    beforeDestroy: function(){
        clearInterval(this.ordersInterval)
    },
    methods: {
        localDateTime(value) { return localDateTime(value, this.$page.props.timezone); },
        fetchOrders() {
            axios.get(this.route('orders.api.history')).then(response => {
                this.orders = response.data.data;
            });
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
