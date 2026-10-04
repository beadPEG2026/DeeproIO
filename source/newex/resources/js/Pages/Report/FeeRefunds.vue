<script>
import Template from '{Template}/Web/Pages/Report/FeeRefunds.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
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
        records: Object,
        filters: Object,
        total_refund_amount: {
            type: [String, Number],
            default: '0'
        },
        total_records: {
            type: [String, Number],
            default: 0
        },
    },

    data() {
        return {
            sending: false,
            form: {
                type: this.filters.type || '',
                search: this.filters.search || '',
            },
        }
    },

    computed: {
        typeOptions() {
            return [
                {id: '', name: this.$t('All')},
                {id: 'spot', name: this.$t('Spot')},
                {id: 'futures', name: this.$t('Futures')},
                {id: 'option', name: this.$t('Options')},
                {id: 'transfer', name: this.$t('Transfer')},
            ];
        },
    },

    methods: {
        trimTrailingZeros(value) {
            if (value === null || value === undefined || value === '') {
                return value;
            }

            let amount = String(value);

            if (amount.indexOf('e') !== -1 || amount.indexOf('E') !== -1) {
                amount = Number(amount).toFixed(8);
            }

            if (amount.indexOf('.') === -1) {
                return amount;
            }

            amount = amount.replace(/(\.\d*?[1-9])0+$/g, '$1');
            amount = amount.replace(/\.0+$/g, '');
            amount = amount.replace(/\.$/g, '');

            return amount === '' ? '0' : amount;
        },

        math_formatter(value, decimals) {
            return this.trimTrailingZeros(math_formatter(value, decimals));
        },

        format_string(string, limit) {
            return string_cut(string, limit);
        },

        doCopy(string) {
            if (!string) {
                return;
            }

            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {

            })
        },

        getList() {
            if (this.sending) return;

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

            let query = pickBy(this.form);

            let url = '/reports/fee-refunds';

            if (Object.keys(query).length) {
                url += '?' + new URLSearchParams(query).toString();
            } else {
                url += '?remember=forget';
            }

            this.$inertia.replace(url, afterRequest);
        },

        getProductTypeLabel(type) {
            const labels = {
                spot: this.$t('Spot'),
                futures: this.$t('Futures'),
                option: this.$t('Options'),
                transfer: this.$t('Transfer'),
            };

            return labels[type] || '-';
        },

        getFeeTypeLabel(type) {
            const labels = {
                entry: this.$t('Entry Fee'),
                exit: this.$t('Exit Fee'),
                order: this.$t('Order Fee'),
                cursor: this.$t('Matched Order Fee'),

                to_trade: this.$t('Funding to Trading'),
                to_funding: this.$t('Trading to Funding'),
                to_lc: this.$t('Funding to Earn'),
                from_lc: this.$t('Earn to Funding'),
                trade_to_lc: this.$t('Trading to Earn'),
                lc_to_trade: this.$t('Earn to Trading'),
            };

            return labels[type] || '-';
        },

        getProductTypeClass(type) {
            if (type === 'spot') {
                return 'label-green';
            }

            if (type === 'futures') {
                return 'label-red';
            }

            if (type === 'option') {
                return 'label-green';
            }

            if (type === 'transfer') {
                return 'label-blue';
            }

            return 'label-green';
        },

        formatPercent(value) {
            let number = Number(value || 0);

            if (!Number.isFinite(number)) {
                return '0%';
            }

            return this.trimTrailingZeros(number.toFixed(2)) + '%';
        },

        formatDiscount(value) {
            if (value === null || value === undefined || value === '') {
                return '-';
            }

            let number = Number(value);

            if (!Number.isFinite(number)) {
                return '-';
            }

            return this.trimTrailingZeros(number.toFixed(2));
        },

        getSourceId(record) {
            return record.source_id || record.order_id || '-';
        },
    },
})
</script>