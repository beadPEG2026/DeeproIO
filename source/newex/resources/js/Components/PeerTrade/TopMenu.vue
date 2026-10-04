<script>
import Template from '{Template}/Web/Components/PeerTrade/TopMenu.template'

export default Template({
    components: {

    },
    props: {
        label: String,
    },
    data() {
        return {
            activeOrdersQuantity: 0,
            showingMoreDropdown: false,
            showingMoreMobileDropdown: false,
            checkActiveOrdersInterval: null,
            isMobile: false,
        }
    },
    mounted() {
        this.mq();

        this.checkActiveOrders();

        this.checkActiveOrdersInterval = setInterval(() => {
            this.checkActiveOrders();
        }, 3000);

    },
    beforeDestroy: function(){
        clearInterval( this.checkActiveOrdersInterval )
    },
    methods: {
        mq () {
            if (typeof window.matchMedia !== "undefined") {
                this.isMobile = window.matchMedia('(max-width: 1000px)').matches;
            }
        },
        checkActiveOrders() {

            if(!this.$page.props.user) return;

            axios.get(this.route('p2p.api.getActiveOrdersQuantity')).then((response) => {
                this.activeOrdersQuantity = response.data.quantity;
            }).catch(error => {

            });
        }
    }
})

</script>
