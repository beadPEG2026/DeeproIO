<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/FeeRefunds.template'
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
        records: Object,
        filters: Object,
        total_refund_amount: {
            type: [String, Number],
            default: '0.00000000',
        },
        total_original_fee: {
            type: [String, Number],
            default: '0.00000000',
        },
        total_records: {
            type: [String, Number],
            default: 0,
        },
    },

    data() {
        return {
            form: {
                search: this.filters.search || null,
                type: this.filters.type || null,
                fee_type: this.filters.fee_type || null,
                user_id: this.filters.user_id || null,
                email: this.filters.email || null,
                wallet_id: this.filters.wallet_id || null,
                currency_id: this.filters.currency_id || null,
                market_id: this.filters.market_id || null,
                vip_level: this.filters.vip_level || null,

                original_fee_min: this.filters.original_fee_min || null,
                original_fee_max: this.filters.original_fee_max || null,
                refund_amount_min: this.filters.refund_amount_min || null,
                refund_amount_max: this.filters.refund_amount_max || null,
                refund_rate_min: this.filters.refund_rate_min || null,
                refund_rate_max: this.filters.refund_rate_max || null,
                discount_rate_min: this.filters.discount_rate_min || null,
                discount_rate_max: this.filters.discount_rate_max || null,

                period: this.filters.period || [],
                per_page: Number(this.filters.per_page || 50),
            },
        }
    },

    computed: {
        rows() {
            if (!this.records || !Array.isArray(this.records.data)) {
                return [];
            }

            return this.records.data;
        },

        currentPageRecordCount() {
            return this.rows.length;
        },

        currentPageOriginalFeeTotal() {
            return this.rows.reduce((total, item) => {
                return total + this.toNumber(item.original_fee);
            }, 0);
        },

        currentPageRefundAmountTotal() {
            return this.rows.reduce((total, item) => {
                return total + this.toNumber(item.refund_amount);
            }, 0);
        },

        currentPageActualFeeTotal() {
            return this.rows.reduce((total, item) => {
                return total + Math.max(this.toNumber(item.original_fee) - this.toNumber(item.refund_amount), 0);
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
formatWallet(record) {
    if (!record.wallet_id) {
        return '-';
    }

    if (record.currency_symbol) {
        return record.currency_symbol + legacyText(" 钱包 #") + record.wallet_id;
    }

    return legacyText("钱包 #") + record.wallet_id;
},

formatCurrency(record) {
    if (record.currency_symbol) {
        return record.currency_symbol;
    }

    return record.currency_id || '-';
},

formatMarket(record) {
    if (record.market_name) {
        return record.market_name;
    }

    return record.market_id || '-';
},
        formatAmount(value) {
            const number = this.toNumber(value);

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 8,
            });
        },

        formatPercent(value) {
            const number = this.toNumber(value);

            return number.toFixed(2).replace(/\.00$/, '') + '%';
        },

        formatDiscount(value) {
            if (value === null || value === undefined || value === '') {
                return '-';
            }

            const number = this.toNumber(value);

            return number.toFixed(2).replace(/\.00$/, '');
        },

        formatProductType(type) {
            const map = {
                spot: legacyText("现货"),
                futures: legacyText("合约"),
                option: legacyText("期权"),
                transfer: legacyText("转账"),
            };

            return map[type] || type || '-';
        },

        productBadgeType(type) {
            if (type === 'spot') {
                return 'green';
            }
        
            if (type === 'futures') {
                return 'red';
            }
        
            if (type === 'option') {
                return 'green';
            }
        
            if (type === 'transfer') {
                return 'red';
            }
        
            return 'green';
        },

        formatFeeType(type) {
            const map = {
                entry: legacyText("开仓手续费"),
                exit: legacyText("平仓手续费"),
                order: legacyText("订单手续费"),
                cursor: legacyText("撮合订单手续费"),

                to_trade: legacyText("资金账户转交易账户"),
                to_funding: legacyText("交易账户转资金账户"),
                to_lc: legacyText("资金账户转量化账户"),
                from_lc: legacyText("量化账户转资金账户"),
                trade_to_lc: legacyText("交易账户转量化账户"),
                lc_to_trade: legacyText("量化账户转交易账户"),
            };

            return map[type] || type || '-';
        },

        reset() {
            this.form = {
                search: null,
                type: null,
                fee_type: null,
                user_id: null,
                email: null,
                wallet_id: null,
                currency_id: null,
                market_id: null,
                vip_level: null,

                original_fee_min: null,
                original_fee_max: null,
                refund_amount_min: null,
                refund_amount_max: null,
                refund_rate_min: null,
                refund_rate_max: null,
                discount_rate_min: null,
                discount_rate_max: null,

                period: [],
                per_page: 50,
            }
        },

        buildQuery() {
            const query = {};

            Object.keys(this.form).forEach((key) => {
                const value = this.form[key];

                if (value === null || value === undefined || value === '') {
                    return;
                }

                if (Array.isArray(value)) {
                    if (value.length === 2 && value[0] && value[1]) {
                        query['period[0]'] = value[0];
                        query['period[1]'] = value[1];
                    }

                    return;
                }

                query[key] = value;
            });

            return query;
        },

        getList() {
            const query = this.buildQuery();

            this.$inertia.replace(
                this.route(
                    'admin.reports.fee-refunds',
                    Object.keys(query).length ? query : { remember: 'forget' }
                )
            )
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