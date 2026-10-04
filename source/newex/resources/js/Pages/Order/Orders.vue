<script>
import Template from '{Template}/Web/Pages/Order/Orders.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
    components: {
        AppLayout,
    },
    data() {
        return {
            openedOrders: {},
        }
    },
    props: {
        orders: Object,
    },
    mounted() {
        this.openedOrders = this.orders.data;
    },
    computed: {
        openOrders() {
            return this.openedOrders;
        }
    },
    methods: {
        cancelOrder(order) {

            let form = {
                'uuid': order.id
            };

            this.openedOrders = _.filter(this.openedOrders, function(object) {
                return object.id !== order.id;
            });

            axios.post(this.route('orders.api.cancel'), form).then((response) => {
                this.$toast.open(this.$t('Order cancelled'));
            }).catch(error => {

            });
        },
    }
})
</script>
