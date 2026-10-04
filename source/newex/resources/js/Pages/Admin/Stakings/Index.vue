<script>
import UmiLocale from '@/Functions/UmiLocale';
import Template from '{Template}/Admin/Pages/Admin/Stakings/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";

export default Template({
    mixins:[UmiLocale],
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
    },
    props: {
        stakings: Object,
    },
    data() {
        return {
            sending: false,
        }
    },
})
</script>
