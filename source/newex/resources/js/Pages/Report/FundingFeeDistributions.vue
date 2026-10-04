<script>
import Template from '{Template}/Web/Pages/Report/FundingFeeDistributions.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import TextUserInput from "@/Jetstream/TextUserInput";
import SelectUserInput from "@/Jetstream/SelectUserInput";
import ReportsTab from "@/Components/Reports/ReportsTab";
import {math_formatter} from "@/Functions/Math";
export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        TextUserInput,
        SelectUserInput,
        ReportsTab
    },
    props: {
        distributions: Object,
        filters: Object,
        markets: Object,
    },
    data() {
        return {
            sending: false,
            form: {
                market: this.filters.market ? parseInt(this.filters.market) : null,
                start_date: this.filters.start_date,
                end_date: this.filters.end_date,
            },
        }
    },
    methods: {
        math_formatter(value, decimals) {
            return math_formatter(value, decimals);
        },
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

            if(this.sending) return;

            this.sending = true;

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.sending = false;
                },
                onError: () => {
                    this.sending = false;
                },
                preserveScroll: true
            };


            let query = pickBy(this.form)
            this.$inertia.replace(this.route('reports.funding-fee-distributions', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },
    },
})
</script>
