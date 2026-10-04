<script>
import Template from '{Template}/Admin/Pages/Admin/PeerTrades/PaymentMethods.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import AdminTopMenu from "@/Components/PeerTrade/AdminTopMenu";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        AdminTopMenu
    },
    props: {
        paymentMethods: Object,
    },
    data() {
        return {
            sending: false,
        }
    },
})
</script>
