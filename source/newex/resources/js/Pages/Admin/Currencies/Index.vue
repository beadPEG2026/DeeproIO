<script>
import Template from '{Template}/Admin/Pages/Admin/Currencies/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter
    },
    props: {
        settings: Object,
        currencies: Object,
        filters: Object,
    },
    data() {
        return {
            sending: false,
            form: {
                search: this.filters.search,
                trashed: this.filters.trashed,
                type: this.filters.type,
            },
        }
    },
    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
    },
    methods: {
        reset() {
            this.form = mapValues(this.form, () => null)
        },
        getList() {
            let query = pickBy(this.form)
            this.$inertia.replace(this.route('admin.currencies', Object.keys(query).length ? query : { remember: 'forget' }))
        },
    },
})
</script>
