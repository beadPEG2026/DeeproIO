<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/Finances.template'
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
import {math_formatter, math_percentage} from "@/Functions/Math";

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
        filters: Object,
    },
    data() {
        return {
            reports: null,
            types: [
                {'id': 'trades', 'name': legacyText("Trades")},
                {'id': 'peer_trades', 'name': 'P2P Trades'},
                {'id': 'deposits', 'name': legacyText("Deposits")},
                {'id': 'withdrawals', 'name': legacyText("Withdrawals")},
                {'id': 'fiat_deposits', 'name': legacyText("Fiat Deposits")},
                {'id': 'fiat_withdrawals', 'name': legacyText("Fiat Withdrawals")},
                {'id': 'options', 'name': legacyText("Options")},
                {'id': 'futures', 'name': legacyText("Futures")},
            ],
            sending: false,
            form: {
                period: null,
                type: null
            },
        }
    },
    mounted() {
        //this.fetchReport()
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
                this.$toast.open(legacyText("Text was copied to the clipboard"));
            }, function (e) {

            })
        },
        fetchReport () {

            axios.get(this.route('admin.reports.finances.fetch'), {
                params: {
                    type: this.form.type,
                    period: this.form.period
                }
            }).then((response) => {
                this.reports = response.data[this.form.type];
            });
        },
        exportCsv() {
            // Build query from current form values and optional referrer
            const query = {};
            if (this.form && this.form.type) query.type = this.form.type;
            if (this.form && this.form.period) query.period = this.form.period;
            if (this.filters && this.filters.referrer) query.referrer = this.filters.referrer;
            const url = this.route('admin.reports.finances.export', Object.keys(query).length ? query : {});
            window.location.href = url;
        }
    },
    watch: {
        form: {
            handler: throttle(function() {
                if(this.form.period && this.form.type) {
                    this.fetchReport()
                }
            }, 150),
            deep: true,
        },
    },
})
</script>
