<script>
import Template from '{Template}/Web/Pages/Launchpad/Launchpads.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";

export default Template({
    components: {
        AppLayout,
        IconFilter
    },
    data() {
        return {
            filter: {
                sortBy: null,
            },
        }
    },
    props: {
        launchpads: Object,
        sort: String
    },
    methods: {
        setLaunchpad(launchpad) {
            this.$inertia.visit(this.route('launchpad', launchpad.id));
        },
        setSort(sort) {
            this.$inertia.visit(this.route('launchpads', {'sort': sort}));
        }
    }
})
</script>
