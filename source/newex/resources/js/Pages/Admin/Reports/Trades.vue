<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/Trades.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import throttle from 'lodash/throttle'
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
        AdminReportsTab,
    },

    props: {
        transactions: Object,
        filters: Object,
        markets: {
            type: Array,
            default: () => [],
        },
    },

    data() {
        return {
            sending: false,
            form: {
                search: this.filters.search || null,
                market_id: this.filters.market_id || null,
                side: this.filters.side || null,
                order_type: this.filters.order_type || null,
                user_id: this.filters.user_id || null,
                email: this.filters.email || null,
                price_min: this.filters.price_min || null,
                price_max: this.filters.price_max || null,
                fee_min: this.filters.fee_min || null,
                fee_max: this.filters.fee_max || null,
                base_amount_min: this.filters.base_amount_min || null,
                base_amount_max: this.filters.base_amount_max || null,
                quote_amount_min: this.filters.quote_amount_min || null,
                quote_amount_max: this.filters.quote_amount_max || null,
                period: this.filters.period || [],
                per_page: Number(this.filters.per_page || 50),
            },
        }
    },

    computed: {
        transactionRows() {
            if (!this.transactions || !Array.isArray(this.transactions.data)) {
                return [];
            }

            return this.transactions.data;
        },

        currentPageTradeCount() {
            return this.transactionRows.length;
        },

        currentPageBuyCount() {
            return this.transactionRows.filter((item) => item.order_side === 'buy').length;
        },

        currentPageSellCount() {
            return this.transactionRows.filter((item) => item.order_side === 'sell').length;
        },

        currentPageFeeTotal() {
            return this.transactionRows.reduce((total, item) => {
                return total + this.toNumber(item.fee);
            }, 0);
        },
    },

    methods: {
        format_string(string, limit) {
            return string_cut(string || '', limit);
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = parseFloat(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        formatAmount(value) {
            const number = this.toNumber(value);

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 8,
            });
        },

        formatOrderType(type) {
            if (type === 'limit') {
                return legacyText("限价");
            }

            if (type === 'market') {
                return legacyText("市价");
            }

            return type || '';
        },

        reset() {
            this.form = {
                search: null,
                market_id: null,
                side: null,
                order_type: null,
                user_id: null,
                email: null,
                price_min: null,
                price_max: null,
                fee_min: null,
                fee_max: null,
                base_amount_min: null,
                base_amount_max: null,
                quote_amount_min: null,
                quote_amount_max: null,
                period: [],
                per_page: 50,
            }
        },

        buildQuery() {
            const query = {};

            if (this.form.search) query.search = this.form.search;
            if (this.form.market_id) query.market_id = this.form.market_id;
            if (this.form.side) query.side = this.form.side;
            if (this.form.order_type) query.order_type = this.form.order_type;
            if (this.form.user_id) query.user_id = this.form.user_id;
            if (this.form.email) query.email = this.form.email;

            if (this.form.price_min) query.price_min = this.form.price_min;
            if (this.form.price_max) query.price_max = this.form.price_max;
            if (this.form.fee_min) query.fee_min = this.form.fee_min;
            if (this.form.fee_max) query.fee_max = this.form.fee_max;

            if (this.form.base_amount_min) query.base_amount_min = this.form.base_amount_min;
            if (this.form.base_amount_max) query.base_amount_max = this.form.base_amount_max;
            if (this.form.quote_amount_min) query.quote_amount_min = this.form.quote_amount_min;
            if (this.form.quote_amount_max) query.quote_amount_max = this.form.quote_amount_max;

            if (this.form.per_page) {
                query.per_page = this.form.per_page;
            }

            if (this.form.period && this.form.period.length === 2) {
                query['period[0]'] = this.form.period[0];
                query['period[1]'] = this.form.period[1];
            }

            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer;
            }

            return query;
        },

        getList() {
            const query = this.buildQuery();

            this.$inertia.replace(
                this.route(
                    'admin.reports.trades',
                    Object.keys(query).length ? query : { remember: 'forget' }
                )
            )
        },

        exportCsv() {
            const query = this.buildQuery();
            const params = new URLSearchParams(query).toString();
            const url = '/exchange-control-panel/reports/trades/export' + (params ? '?' + params : '');

            window.location.href = url;
        },
    },

    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 300),
            deep: true,
        },
    },
})
</script>