<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/Futures.template'
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
        AdminReportsTab
    },
    props: {
        transactions: Object,
        filters: Object,
        markets: Array,
        stats: Object,
        currentStatus: {
            type: String,
            default: 'active',
        },
    },
    data() {
        return {
            sending: false,
            loadingId: null,
            showSuperiorModal: false,
            superiorRows: [],
            livePrices: {},
            livePnls: {},
            livePriceTimer: null,
            livePriceLoading: false,
            form: {
                search: this.filters.search || null,
                type: this.filters.type || null,
                market: this.filters.market || null,
                status: this.filters.status || null,
                period: this.filters.period || null,
                per_page: Number(this.filters.per_page || 10),
            },
        }
    },
    computed: {
        pageTitle() {
            return this.currentStatus === 'history' ? legacyText("合约历史订单") : legacyText("合约开仓中订单");
        },

        reportSection() {
            return this.currentStatus === 'history' ? 'futures_history' : 'futures_active';
        },

        currentPath() {
            return this.currentStatus === 'history'
                ? '/exchange-control-panel/reports/futures/history'
                : '/exchange-control-panel/reports/futures/open';
        },

        canManageFutures() {
            const roles = this.$page?.props?.user?.roles || [];

            const names = roles.map(role => typeof role === 'string' ? role : role.name);

            return names.includes('superadmin') ||
                names.includes('admin') ||
                names.includes('finance_manager') ||
                names.includes('user_leader') ||
                names.includes('perm_finances');
        },

        activeUserCount() {
            return this.getStatNumber('active_user_count');
        },

        activeOrderCount() {
            return this.getStatNumber('active_count');
        },

        activePositionAmountTotal() {
            return this.getStatNumber('active_position_amount_total');
        },
    },
    mounted() {
        this.startLivePricePolling();
    },

    beforeDestroy() {
        this.stopLivePricePolling();
    },
    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(legacyText("Text was copied to the clipboard"));
            }, function (e) {

            })
        },

        getFullOrderId(transaction) {
            if (!transaction || transaction.id === null || transaction.id === undefined || transaction.id === '') {
                return '-';
            }

            return String(transaction.id);
        },

        getShortOrderId(transaction) {
            const id = this.getFullOrderId(transaction);

            if (id === '-' || id.length <= 12) {
                return id;
            }

            return `${id.slice(0, 6)}...${id.slice(-4)}`;
        },

        copyOrderId(transaction) {
            const id = this.getFullOrderId(transaction);

            if (id === '-') {
                return;
            }

            this.doCopy(id);
        },

        reset() {
            this.form = mapValues(this.form, () => null)
            this.form.per_page = 10
        },

        buildUrl(path, query = {}) {
            const params = new URLSearchParams();

            Object.keys(query).forEach((key) => {
                const value = query[key];

                if (Array.isArray(value)) {
                    value.forEach((item, index) => {
                        if (item !== null && item !== undefined && item !== '') {
                            params.append(`${key}[${index}]`, item);
                        }
                    });

                    return;
                }

                if (value !== null && value !== undefined && value !== '') {
                    params.append(key, value);
                }
            });

            const queryString = params.toString();

            return queryString ? `${path}?${queryString}` : path;
        },

        getList() {
            let query = pickBy(this.form)

            if (this.currentStatus === 'active') {
                query.status = 'active'
            } else if (!query.status || query.status === 'active') {
                query.status = 'history'
            }

            this.$inertia.replace(
                this.buildUrl(this.currentPath, Object.keys(query).length ? query : { remember: 'forget' })
            )
        },

        exportCsv() {
            let query = pickBy(this.form)

            if (this.currentStatus === 'active') {
                query.status = 'active'
            } else if (!query.status || query.status === 'active') {
                query.status = 'history'
            }

            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer
            }
            const url = this.buildUrl('/exchange-control-panel/reports/futures/export', Object.keys(query).length ? query : {})
            window.location.href = url
        },

        math_formatter(value, decimals) {
            return math_formatter(value, decimals);
        },

        getStatNumber(key) {
            if (!this.stats || this.stats[key] === null || this.stats[key] === undefined || this.stats[key] === '') {
                return 0;
            }

            return this.toNumber(this.stats[key]);
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            return Number.isNaN(number) ? 0 : number;
        },

        getFirstDateValue(transaction, keys) {
            if (!transaction || !Array.isArray(keys)) {
                return '-';
            }

            for (let i = 0; i < keys.length; i++) {
                const key = keys[i];

                if (
                    transaction[key] !== null &&
                    transaction[key] !== undefined &&
                    transaction[key] !== ''
                ) {
                    return transaction[key];
                }
            }

            return '-';
        },

        getOpenTime(transaction) {
            return this.getFirstDateValue(transaction, [
                'opened_at',
                'open_at',
                'open_time',
                'opened_time',
                'entry_time',
                'created_at',
            ]);
        },

        getCloseTime(transaction) {
            if (!transaction) {
                return '-';
            }

            if (transaction.status === 'active') {
                return '-';
            }

            return this.getFirstDateValue(transaction, [
                'closed_at',
                'close_at',
                'close_time',
                'closed_time',
                'exit_time',
                'liquidated_at',
                'updated_at',
            ]);
        },

        getTransactionMarketName(transaction) {
            return transaction && transaction.market ? transaction.market.name : null;
        },

        getActiveMarketNames() {
            const rows = this.transactions && Array.isArray(this.transactions.data)
                ? this.transactions.data
                : [];

            const markets = rows
                .filter(transaction => transaction.status === 'active')
                .map(transaction => this.getTransactionMarketName(transaction))
                .filter(Boolean);

            return Array.from(new Set(markets));
        },

        getActivePositionIds() {
            const rows = this.transactions && Array.isArray(this.transactions.data)
                ? this.transactions.data
                : [];

            const ids = rows
                .filter(transaction => transaction.status === 'active')
                .map(transaction => transaction.id)
                .filter(Boolean);

            return Array.from(new Set(ids));
        },

        startLivePricePolling() {
            this.stopLivePricePolling();

            if (this.currentStatus !== 'active') {
                return;
            }

            this.fetchLivePrices();
            this.livePriceTimer = window.setInterval(() => {
                this.fetchLivePrices();
            }, 1000);
        },

        stopLivePricePolling() {
            if (this.livePriceTimer) {
                window.clearInterval(this.livePriceTimer);
                this.livePriceTimer = null;
            }
        },

        fetchLivePrices() {
            if (this.livePriceLoading) {
                return;
            }

            const markets = this.getActiveMarketNames();
            const ids = this.getActivePositionIds();

            if (markets.length === 0) {
                this.livePrices = {};
                this.livePnls = {};
                return;
            }

            this.livePriceLoading = true;

            axios.get('/exchange-control-panel/reports/futures/live-prices', {
                params: {
                    markets: markets.join(','),
                    ids: ids.join(','),
                },
            }).then((response) => {
                this.livePrices = response && response.data && response.data.prices
                    ? response.data.prices
                    : {};
                this.livePnls = response && response.data && response.data.positions
                    ? response.data.positions
                    : {};
            }).catch(() => {
            }).finally(() => {
                this.livePriceLoading = false;
            });
        },

        calculateLivePnl(transaction) {
            if (!transaction || transaction.status !== 'active') {
                return this.toNumber(transaction ? transaction.pnl : 0);
            }

            const livePnl = this.livePnls && transaction.id
                ? this.livePnls[transaction.id]
                : null;

            if (livePnl && livePnl.pnl !== null && livePnl.pnl !== undefined && livePnl.pnl !== '') {
                return this.toNumber(livePnl.pnl);
            }

            const marketName = this.getTransactionMarketName(transaction);
            const marketPrice = this.toNumber(this.livePrices[marketName]);

            if (marketPrice <= 0) {
                return this.toNumber(transaction.pnl);
            }

            const quantity = this.toNumber(transaction.quantity);
            const entryPrice = this.toNumber(transaction.price);
            const leverage = this.toNumber(transaction.leverage);

            if (quantity <= 0 || entryPrice <= 0 || leverage <= 0) {
                return this.toNumber(transaction.pnl);
            }

            const pnlAmount = transaction.is_long
                ? quantity * (marketPrice - entryPrice)
                : quantity * (entryPrice - marketPrice);
            const margin = (quantity * entryPrice) / leverage;

            if (margin <= 0) {
                return this.toNumber(transaction.pnl);
            }

            const percentage = (pnlAmount / margin) * 100;

            return percentage < -100 ? -100 : percentage;
        },

        livePnlType(transaction) {
            return this.calculateLivePnl(transaction) >= 0 ? 'green' : 'red';
        },

        getCustomerNickname(transaction) {
            const user = transaction && transaction.user ? transaction.user : null;

            if (!user) {
                return '-';
            }

            return user.email || user.nickname || user.name || user.phone || '-';
        },

        normalizeSuperior(item, index) {
            if (!item) {
                return {
                    level: index + 1,
                    id: '-',
                    account: '-',
                    nickname: '-',
                };
            }

            return {
                level: item.level || index + 1,
                id: item.id || item.user_id || '-',
                account: item.account || item.email || item.phone || item.wallet_id || item.referral_code || '-',
                nickname: item.nickname || item.name || item.real_name || '-',
            };
        },

        getSuperiorRows(transaction) {
            const chain = transaction && Array.isArray(transaction.referrer_chain)
                ? transaction.referrer_chain
                : [];

            return chain.map((item, index) => this.normalizeSuperior(item, index));
        },

        hasSuperiorChain(transaction) {
            return this.getSuperiorRows(transaction).length > 0;
        },

        getSuperiorName(transaction) {
            if (transaction && transaction.referrer_display_name) {
                return transaction.referrer_display_name;
            }

            const user = transaction && transaction.user ? transaction.user : null;

            if (user && user.referrer_display_name) {
                return user.referrer_display_name;
            }

            const rows = this.getSuperiorRows(transaction);

            if (rows.length > 0) {
                return rows[0].nickname !== '-' ? rows[0].nickname : rows[0].account;
            }

            return '-';
        },

        openSuperiorModal(transaction) {
            this.superiorRows = this.getSuperiorRows(transaction);
            this.showSuperiorModal = true;
        },

        closeSuperiorModal() {
            this.showSuperiorModal = false;
            this.superiorRows = [];
        },

        forceLiquidate(transaction) {
            if (!this.canManageFutures) {
                return;
            }

            if (this.loadingId) return;

            if (!confirm(legacyText("确定强制平仓？\nID: {value0}\n市场: {value1}", {value0: transaction.id, value1: transaction.market.name}))) {
                return;
            }

            this.loadingId = transaction.id;

            axios.post('/exchange-control-panel/reports/futures/force-liquidate', {
                uuid: transaction.id
            }).then((response) => {
                this.$toast.open({
                    message: response?.data?.message || legacyText("强制平仓成功"),
                    type: 'success',
                });

                this.getList();
            }).catch((error) => {
                this.$toast.open({
                    message: error?.response?.data?.message || legacyText("强制平仓失败"),
                    type: 'error',
                });
            }).finally(() => {
                this.loadingId = null;
            });
        },
    },

    watch: {
        transactions: {
            handler() {
                this.startLivePricePolling();
            },
            deep: true,
        },

        currentStatus() {
            this.startLivePricePolling();
        },

        form: {
            handler: throttle(function() {
                this.getList()
            }, 300),
            deep: true,
        },
    },
})
</script>
