<script>
import Template from '{Template}/Admin/Pages/Admin/PeerTrades/OrdersAppealView.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import NavButton from "@/Jetstream/NavButton";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButton,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
    },
    props: {
        appeal: Object,
    },
    data() {
        return {
            message: null,
            messages: {},
            scrollOps: {
                vuescroll: {},
                scrollPanel: {
                    initialScrollY: '100%'
                },
                rail: {},
                bar: {}
            },
        }
    },
    mounted() {
        this.loadMessages();
    },
    methods: {
        loadMessages() {
            axios.get(this.route('admin.peerOrdersAppeals.getChat'), {
                params: {
                    order_id: this.appeal.order_id
                }
            }).then((response) => {
                this.messages = response.data.messages;
            }).catch(error => {

            });
        },
        textAreaAdjust() {
            const { message } = this.$refs;
            message.style.height = "0px";
            message.style.height = (message.scrollHeight)+"px";
        },
        addMessage() {
            axios.post(this.route('admin.peerOrdersAppeals.postChat'), {order_id: this.appeal.order_id, message: this.message}).then((response) => {
                this.loadMessages();
                this.message = null;
            }).catch(error => {

            });
        },
    },
})
</script>
