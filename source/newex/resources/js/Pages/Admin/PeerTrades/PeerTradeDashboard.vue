<script>
import Template from '{Template}/Admin/Pages/Admin/PeerTrades/PeerTradeDashboard.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import NavButton from "@/Jetstream/NavButton";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetCheckbox from '@/Jetstream/Checkbox'
import AdminTopMenu from "@/Components/PeerTrade/AdminTopMenu";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        NavButton,
        AppLayout,
        Welcome,
        Pagination,
        JetDialogModal,
        JetSecondaryButton,
        JetCheckbox,
        JetButton,
        AdminTopMenu
    },
    props: {
        ads: Object
    },
    data() {
        return {

        }
    },
    methods: {

    }

})
</script>
