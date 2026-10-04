<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/Deposits.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButton from "@/Jetstream/NavButton";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import throttle from 'lodash/throttle'
import {string_cut} from "@/Functions/String";
import AdminReportsTab from "@/Components/Reports/AdminReportsTab";
import TextInput from '@/Jetstream/TextInput'

export default Template({
    components: {
        Badge,
        NavLink,
        TextInput,
        AppLayout,
        Welcome,
        Pagination,
        AdminReportsTab,
        NavButton
    },

    props: {
        deposits: Object,
        filters: Object,
        currencies: Object,
        networks: {
            type: Array,
            default: () => [],
        },
        stats: {
            type: Object,
            default: () => ({}),
        },
    },

    data() {
        return {
            loading: false,
            missingOpened: false,
            sending: false,
            confirmingDepositId: null,
            form: {
                first_only: this.filters.first_only || null,
                search: this.filters.search || null,
                type: this.filters.type || null,
                status: this.filters.status || null,
                network_id: this.filters.network_id || null,
                user_id: this.filters.user_id || null,
                txn: this.filters.txn || null,
                address: this.filters.address || null,
                period: this.filters.period || [],
                per_page: Number(this.filters.per_page || 100),
            },
            depositForm: {
                address: null,
            },
            selectedReferrerTitle: '',
            selectedReferrerChain: null,
        }
    },

    computed: {
        isSuperAdmin() {
            const roles = this.$page?.props?.user?.roles || [];

            return roles.some(role => {
                if (typeof role === 'string') {
                    return role === 'superadmin';
                }

                return role && (role.name === 'superadmin');
            });
        },

        depositRows() {
            if (!this.deposits || !Array.isArray(this.deposits.data)) {
                return [];
            }

            return this.deposits.data;
        },

        normalDepositRows() {
            return this.depositRows.filter((item) => {
                const networkName = item.network && item.network.name
                    ? String(item.network.name).toLowerCase()
                    : '';

                const networkSlug = item.network && item.network.slug
                    ? String(item.network.slug).toLowerCase()
                    : '';

                const address = item.address
                    ? String(item.address).toLowerCase()
                    : '';

                /**
                 * Internal 内部充值不参与统计。
                 */
                return networkName !== 'internal' &&
                    networkSlug !== 'internal' &&
                    address !== 'internal' &&
                    !item.internal_id;
            });
        },

        totalDepositUserCount() {
            if (this.stats && this.stats.deposit_user_count !== undefined) {
                return this.toNumber(this.stats.deposit_user_count);
            }

            const userIds = [];

            this.normalDepositRows.forEach((item) => {
                if (item.user && item.user.id && !userIds.includes(item.user.id)) {
                    userIds.push(item.user.id);
                }
            });

            return userIds.length;
        },

        totalDepositCount() {
            if (this.stats && this.stats.deposit_count !== undefined) {
                return this.toNumber(this.stats.deposit_count);
            }

            return this.normalDepositRows.length;
        },

        totalDepositAmount() {
            if (this.stats && this.stats.total_deposit_amount_usdt !== undefined) {
                return this.toNumber(this.stats.total_deposit_amount_usdt);
            }

            return this.normalDepositRows.reduce((total, item) => {
                return total + this.toNumber(item.amount);
            }, 0);
        },
    },

    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
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

        currencySymbol(row) {
            const currency = row && row.currency ? row.currency : {};

            return String(currency.symbol || currency.alt_symbol || '').toUpperCase();
        },

        shouldShowUsdtEstimate(row) {
            const symbol = this.currencySymbol(row);

            return symbol && !['USDT', 'USDC'].includes(symbol);
        },

        depositUsdtAmount(row) {
            if (!row || row.usdt_amount === null || row.usdt_amount === undefined || row.usdt_amount === '') {
                return 0;
            }

            return row.usdt_amount;
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(legacyText("Text was copied to the clipboard"));
            }, function (e) {

            })
        },

        reset() {
            this.form = {
                search: null,
                type: null,
                status: null,
                network_id: null,
                user_id: null,
                txn: null,
                address: null,
                period: [],
                per_page: 100,
            }
        },

        buildQuery() {
            const query = {};
            if (this.form.first_only) query.first_only = this.form.first_only;

            if (this.form.search) query.search = this.form.search;
            if (this.form.type) query.type = this.form.type;
            if (this.form.status) query.status = this.form.status;
            if (this.form.network_id) query.network_id = this.form.network_id;
            if (this.form.user_id) query.user_id = this.form.user_id;
            if (this.form.txn) query.txn = this.form.txn;
            if (this.form.address) query.address = this.form.address;
            if (this.form.period && this.form.period.length === 2) {
                query['period[0]'] = this.form.period[0];
                query['period[1]'] = this.form.period[1];
            }

            if (this.form.per_page) {
                query.per_page = this.form.per_page;
            }

            if (this.filters && this.filters.referrer) {
                query.referrer = this.filters.referrer;
            }

            if (this.filters.team_user_id) query.team_user_id = this.filters.team_user_id;
            return query;
        },

        getList() {
            const query = this.buildQuery();

            this.$inertia.replace(
                this.route(
                    'admin.reports.deposits',
                    Object.keys(query).length ? query : { remember: 'forget' }
                )
            )
        },

        exportExcel() {
            const query = this.buildQuery();
            const params = new URLSearchParams(query).toString();
            const url = '/exchange-control-panel/reports/deposits/export' + (params ? '?' + params : '');

            window.location.href = url;
        },

        transfer(deposit, key) {
            this.deposits.data[key].wallet_transfer_status = 'pending';
            this.deposits.data[key].wallet_transfer_ago = 1;

            axios.post(this.route('admin.reports.deposits.resync'), {
                id: deposit.id,
            }).then(() => {

            });
        },

        confirmPending(deposit, key) {
            if (!this.isSuperAdmin) {
                this.$toast.error(legacyText("只有超级管理员可以确认充值"));
                return;
            }

            if (!deposit || deposit.status !== 'ignored' || this.confirmingDepositId) {
                return;
            }

            if (!confirm(legacyText("确定把这笔 ignored 充值改为 pending 状态吗？"))) {
                return;
            }

            this.confirmingDepositId = deposit.id;

            axios.post('/exchange-control-panel/reports/deposits/confirm-pending', {
                id: deposit.id,
            }).then((res) => {
                if (res.data && res.data.success) {
                    this.deposits.data[key].status = 'pending';
                    this.$toast.open(legacyText("已确认，ignored 状态已改为 pending"));
                    return;
                }

                this.$toast.error((res.data && res.data.message) || legacyText("处理失败"));
            }).catch((error) => {
                const message = error.response && error.response.data && error.response.data.message
                    ? error.response.data.message
                    : legacyText("处理失败");

                this.$toast.error(message);
            }).finally(() => {
                this.confirmingDepositId = null;
            });
        },

        dateDifference(created) {

        },

        openMissing() {
            this.missingOpened = !this.missingOpened;
        },

        searchTx() {
            if (this.loading) return false;

            if (!this.depositForm.address) {
                alert('Please enter wallet address');
                return;
            }

            this.loading = true;

            axios.post(this.route('admin.reports.deposits.recheck'), this.depositForm).then((res) => {
                this.loading = false;
                this.getList();

                if (res.data.success) {
                    this.$toast.open('The task was processed');
                } else {
                    this.$toast.error('Wallet address not found');
                }

            }).catch(error => {
                this.loading = false;
                this.$toast.error('Internal error');
            });
        },

        getReferrerChain(row) {
            if (row && Array.isArray(row.referrer_chain)) {
                return row.referrer_chain;
            }

            if (row && row.user && Array.isArray(row.user.referrer_chain)) {
                return row.user.referrer_chain;
            }

            return [];
        },

        getReferrerDisplay(row) {
            if (row && row.referrer_display_name) {
                return row.referrer_display_name;
            }

            if (row && row.user && row.user.referrer_display_name) {
                return row.user.referrer_display_name;
            }

            return 'N/A';
        },

        showReferrerChain(row) {
            const userId = row && row.user ? (row.user.referral_code || row.user.id) : '';

            this.selectedReferrerTitle = legacyText("用户 {value0} 的所有上级", {value0: userId});
            this.selectedReferrerChain = this.getReferrerChain(row);
        },

        closeReferrerChain() {
            this.selectedReferrerTitle = '';
            this.selectedReferrerChain = null;
        },

        referrerPrimaryName(item) {
            if (!item) {
                return '-';
            }

            return (item.nickname && item.nickname !== '-')
                ? item.nickname
                : (item.account || item.name || `ID: ${item.id}`);
        },
    },

    watch: {
        form: {
            handler: throttle(function () {
                this.getList()
            }, 300),
            deep: true,
        },
    },
})
</script>
