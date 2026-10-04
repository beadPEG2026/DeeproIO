<script>
import SitePresentationSettings from '@/Components/Admin/SitePresentationSettings.vue';
import Template from '{Template}/Admin/Pages/Admin/Pages/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";

export default Template({
    components: {
        SitePresentationSettings,
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
    },
    props: {
        pages: Object,
    },
    data() {
        return {
            sending: false,
        }
    },
})
</script>
