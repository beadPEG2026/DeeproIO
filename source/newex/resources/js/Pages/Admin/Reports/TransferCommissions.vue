<script>
import Template from '{Template}/Admin/Pages/Admin/Reports/TransferCommissions.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import AdminReportsTab from "@/Components/Reports/AdminReportsTab";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        AdminReportsTab
    },
    props: {
        commissions: Object,
        filters: Object,
    },
    data() {
        return {
            sending: false,
            form: {
                search: this.filters.search,
                period: this.filters.period || null,
            },
        }
    },
    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {

            })
        },
        reset() {
            this.form = mapValues(this.form, () => null)
        },
        getList() {
            let query = pickBy(this.form)
            this.$inertia.replace(this.route('admin.reports.transfer-commissions', Object.keys(query).length ? query : { remember: 'forget' }))
        },
        exportCsv() {
            let query = pickBy(this.form)
            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer
            }
            const url = this.route('admin.reports.transfer-commissions.export', Object.keys(query).length ? query : {})
            window.location.href = url
        },
    },
    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
    },
})
</script>
