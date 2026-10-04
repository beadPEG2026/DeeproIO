<script>
import Template from '{Template}/Admin/Pages/Admin/Networks/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import { networkRouteKind } from '@/Functions/NetworkIdentity.mjs';

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
    },
    props: {
        networks: Array,
    },
    data() {
        return {
            sending: false,
        }
    },
    methods: {
        networkRouteKind,
        getList() {
            this.$inertia.replace(this.route('admin.networks'))
        },
    },
})
</script>
