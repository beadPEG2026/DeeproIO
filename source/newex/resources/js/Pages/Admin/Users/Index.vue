<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Users/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import throttle from "lodash/throttle";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
    },

    props: {
        users: Object,
        filters: Object,
        stats: Object,
    },

    data() {
        return {
            sending: false,
            localUsers: [],

            showUplineModal: false,
            uplineLoading: false,
            selectedUplineUser: null,
            uplineRows: [],

            form: {
                search: this.filters && this.filters.search ? this.filters.search : null,
                dashboard_participants: this.filters?.dashboard_participants || null,
                period: this.filters && this.filters.period ? this.filters.period : [],
                team_user_id: this.filters && this.filters.team_user_id ? this.filters.team_user_id : null,
                role: this.filters && this.filters.role ? this.filters.role : null,
                status: this.filters && this.filters.status ? this.filters.status : null,
                kyc_status: this.filters && this.filters.kyc_status ? this.filters.kyc_status : null,
                email_status: this.filters && this.filters.email_status ? this.filters.email_status : null,
                login_ip: this.filters && this.filters.login_ip ? this.filters.login_ip : null,
                ip_location: this.filters && this.filters.ip_location ? this.filters.ip_location : null,
                duplicate_ip: this.filters && this.filters.duplicate_ip ? this.filters.duplicate_ip : null,
                duplicate_account: this.filters && this.filters.duplicate_account ? this.filters.duplicate_account : null,
            },
        }
    },

    created() {
        this.localUsers = this.prepareUsers(this.users);
    },

    watch: {
        users: {
            handler(value) {
                this.localUsers = this.prepareUsers(value);
            },
            deep: true,
        },

        form: {
            handler: throttle(function () {
                this.getList();
            }, 300),
            deep: true,
        },
    },

    computed: {
        isSuperAdmin() {
            const user = this.$page && this.$page.props
                ? (this.$page.props.user || (this.$page.props.auth ? this.$page.props.auth.user : null))
                : null;

            const roles = user && user.roles ? user.roles : [];

            return roles.some(role => {
                if (typeof role === 'string') {
                    return role === 'superadmin';
                }

                return role.name === 'superadmin';
            });
        },

        displayUsers() {
            const users = this.users || {};

            return {
                ...users,
                data: this.localUsers,
            };
        },

        safeStats() {
            return this.stats || {};
        },
    },

    methods: {
        locationLabel(value) { const text = String(value || ''); const translated = legacyText(text); return translated !== text ? translated : text.split(/(\s*[/·]\s*)/).map(part => legacyText(part)).join(''); },
        prepareUsers(users) {
            const data = users && Array.isArray(users.data) ? users.data : [];

            return data.map(user => {
                return this.prepareNode({
                    ...user,
                    display_email: this.cleanText(user.email),
                    display_phone: this.cleanText(user.phone),
                    display_nickname: this.cleanText(user.nickname),
                    display_leader_name: this.cleanText(user.leader_display_name),
                });
            });
        },

        prepareNode(node) {
            const children = Array.isArray(node.children)
                ? node.children
                : (Array.isArray(node.team_children) ? node.team_children : []);

            return {
                ...node,

                display_email: this.cleanText(node.display_email || node.email),
                display_phone: this.cleanText(node.display_phone || node.phone),
                display_nickname: this.cleanText(node.display_nickname || node.nickname),
                display_leader_name: this.cleanText(node.display_leader_name || node.leader_display_name),

                referral_chain: Array.isArray(node.referral_chain) ? node.referral_chain : [],
                uplines: Array.isArray(node.uplines) ? node.uplines : [],
                parent_chain: Array.isArray(node.parent_chain) ? node.parent_chain : [],
                ancestors: Array.isArray(node.ancestors) ? node.ancestors : [],

                wallet_balance: this.normalizeBalance(node.wallet_balance),
                trade_balance: this.normalizeBalance(node.trade_balance),
                total_balance: this.normalizeBalance(node.total_balance),

                real_wallet_balance: this.normalizeBalance(node.real_wallet_balance),
                real_trade_balance: this.normalizeBalance(node.real_trade_balance),
                real_order_balance: this.normalizeBalance(node.real_order_balance),
                real_withdraw_balance: this.normalizeBalance(node.real_withdraw_balance),
                real_total_balance: this.normalizeBalance(node.real_total_balance),
                real_locked_total_balance: this.normalizeBalance(node.real_locked_total_balance),
                list_real_earn_balance: this.normalizeBalance(node.list_real_earn_balance),
                list_real_staking_balance: this.normalizeBalance(node.list_real_staking_balance),

                virtual_wallet_balance: this.normalizeBalance(node.virtual_wallet_balance),
                virtual_trade_balance: this.normalizeBalance(node.virtual_trade_balance),
                virtual_order_balance: this.normalizeBalance(node.virtual_order_balance),
                virtual_total_balance: this.normalizeBalance(node.virtual_total_balance),
                virtual_locked_total_balance: this.normalizeBalance(node.virtual_locked_total_balance),

                grand_total_balance: this.normalizeBalance(node.grand_total_balance || node.total_balance),
                locked_total_balance: this.normalizeBalance(node.locked_total_balance),

                has_children: !!node.has_children || children.length > 0,
                children_loaded: !!node.children_loaded,
                children: children.map(child => this.prepareNode(child)),
            };
        },

        normalizeBalance(value) {
            if (value === null || value === undefined || value === '') {
                return '0.00';
            }

            return value;
        },

        viewTeam(user) {
            if (!user || !user.id) {
                return;
            }

            const url = this.getTeamBlankViewUrl(user.id);
            const newWindow = window.open(url, '_blank');

            if (!newWindow) {
                window.location.href = url;
            }
        },

        getTeamBlankViewUrl(parentId) {
            try {
                if (this.route && this.route().has && this.route().has('admin.users.team.view')) {
                    return this.route('admin.users.team.view', {
                        parent_id: parentId,
                    });
                }
            } catch (e) {
            }

            const baseUrl = this.route('admin.users').replace(/\?.*$/, '').replace(/\/$/, '');

            return baseUrl + '/team-view?parent_id=' + parentId;
        },

        referralDisplay(user) {
            if (!user) {
                return 'N/A';
            }

            const referral = user.referral || null;

            if (referral) {
                const account = referral.email || referral.phone || referral.wallet_id || referral.name || referral.id || '-';
                return account + (referral.id ? '(' + referral.id + ')' : '');
            }

            if (user.referral_id) {
                return 'ID: ' + user.referral_id;
            }

            return 'N/A';
        },

        normalizeUplineRow(item, index) {
            if (!item) {
                return {
                    level: index + 1,
                    id: '-',
                    account: '-',
                    nickname: '-',
                    name: '-',
                };
            }

            return {
                level: item.level || index + 1,
                id: item.id || item.user_id || '-',
                account: item.account || item.email || item.phone || item.wallet_id || item.username || '-',
                nickname: item.nickname || item.display_nickname || '-',
                name: item.name || item.real_name || item.full_name || item.leader_nickname || item.nickname || item.email || '-',
            };
        },

        buildLocalUplineRows(user) {
            if (!user) {
                return [];
            }

            const possibleChains = [
                user.uplines,
                user.referral_chain,
                user.parent_chain,
                user.ancestors,
            ];

            for (let i = 0; i < possibleChains.length; i++) {
                if (Array.isArray(possibleChains[i]) && possibleChains[i].length > 0) {
                    return possibleChains[i].map((item, index) => this.normalizeUplineRow(item, index));
                }
            }

            if (user.referral) {
                return [this.normalizeUplineRow(user.referral, 0)];
            }

            if (user.referral_id) {
                return [{
                    level: 1,
                    id: user.referral_id,
                    account: user.referral_email || user.referrer_email || '-',
                    nickname: user.referral_nickname || user.referrer_nickname || '-',
                    name: user.referral_name || user.referrer_name || '-',
                }];
            }

            return [];
        },

        getUplineUrl(userId) {
            try {
                if (this.route && this.route().has && this.route().has('admin.users.uplines')) {
                    return this.route('admin.users.uplines', {
                        user_id: userId,
                    });
                }
            } catch (e) {
            }

            const baseUrl = this.route('admin.users').replace(/\?.*$/, '').replace(/\/$/, '');

            return baseUrl + '/uplines?user_id=' + userId;
        },

        openUplineModal(user) {
            if (!user || !user.id) {
                return;
            }

            this.selectedUplineUser = user;
            this.showUplineModal = true;
            this.uplineRows = this.buildLocalUplineRows(user);
            this.uplineLoading = true;

            axios.get(this.getUplineUrl(user.id), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            }).then((response) => {
                const data = response.data || {};
                const rows = data.data || data.uplines || data.rows || [];

                if (Array.isArray(rows)) {
                    this.uplineRows = rows.map((item, index) => this.normalizeUplineRow(item, index));
                }
            }).catch(() => {
                /*
                 * 后端接口未添加或请求失败时，保留当前行已有的推荐人数据。
                 */
            }).finally(() => {
                this.uplineLoading = false;
            });
        },

        closeUplineModal() {
            this.showUplineModal = false;
            this.uplineLoading = false;
            this.selectedUplineUser = null;
            this.uplineRows = [];
        },

        toBalanceNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            return Number.isNaN(number) ? 0 : number;
        },

        hasVirtualBalance(user) {
            if (!user) {
                return false;
            }

            const virtualWalletBalance = this.toBalanceNumber(
                user.list_virtual_wallet_balance ||
                user.virtual_wallet_balance ||
                user.virtual_balance_in_wallet ||
                user.balance_in_virtual_wallet
            );

            const virtualTradeBalance = this.toBalanceNumber(
                user.list_virtual_trade_balance ||
                user.virtual_trade_balance ||
                user.virtual_balance_in_trade ||
                user.balance_in_virtual_trade
            );

            const virtualOrderBalance = this.toBalanceNumber(
                user.virtual_order_balance ||
                user.list_virtual_order_balance ||
                user.virtual_balance_in_order ||
                user.balance_in_virtual_order
            );

            const virtualTotalBalance = this.toBalanceNumber(
                user.virtual_total_balance ||
                user.list_virtual_total_balance ||
                user.total_virtual_balance
            );

            return (virtualWalletBalance + virtualTradeBalance + virtualOrderBalance + virtualTotalBalance) > 0;
        },

        depositWithdrawDiff(user) {
            if (!user) {
                return 0;
            }

            const deposit = this.toBalanceNumber(user.list_total_deposit);
            const withdrawal = this.toBalanceNumber(user.list_total_withdrawal);

            return deposit - withdrawal;
        },

        formatBalance(value) {
            if (value === null || value === undefined || value === '') {
                value = 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            if (Number.isNaN(number)) {
                return '0.00';
            }

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        cleanText(value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value).trim();
        },

        reset() {
            this.form = {
                search: null,
                dashboard_participants: null,
                period: [],
                team_user_id: null,
                role: null,
                status: null,
                kyc_status: null,
                email_status: null,
                login_ip: null,
                ip_location: null,
                duplicate_ip: null,
                duplicate_account: null,
            };
        },

        buildQuery() {
            const query = {};

            if (this.form.search) query.search = this.form.search;
            if (this.form.dashboard_participants) query.dashboard_participants = 1;
            if (this.form.team_user_id) query.team_user_id = this.form.team_user_id;
            if (this.form.role) query.role = this.form.role;
            if (this.form.status) query.status = this.form.status;
            if (this.form.kyc_status) query.kyc_status = this.form.kyc_status;
            if (this.form.email_status) query.email_status = this.form.email_status;
            if (this.form.login_ip) query.login_ip = this.form.login_ip;
            if (this.form.ip_location) query.ip_location = this.form.ip_location;
            if (this.form.duplicate_ip) query.duplicate_ip = this.form.duplicate_ip;
            if (this.form.duplicate_account) query.duplicate_account = this.form.duplicate_account;

            if (this.form.period && this.form.period.length === 2) {
                query['period[0]'] = this.form.period[0];
                query['period[1]'] = this.form.period[1];
            }

            return query;
        },

        getList() {
            const query = this.buildQuery();

            const url = this.route(
                'admin.users',
                Object.keys(query).length ? query : { remember: 'forget' }
            );

            this.$inertia.replace(url, {
                preserveState: false,
                preserveScroll: true,
            });
        },

        filterDuplicateIp() {
            this.form.duplicate_ip = 1;
            this.form.duplicate_account = null;
        },

        filterDuplicateAccount() {
            this.form.duplicate_account = 1;
            this.form.duplicate_ip = null;
        },

        goFund(user) {
            this.$inertia.visit(this.route('admin.reports.wallets', {
                user: user.id,
            }));
        },

        viewWallet(user) {
            if (!user || !user.id) {
                return;
            }

            this.$inertia.visit(this.route('admin.reports.wallets', {
                user: user.id,
            }));
        },

        clearVirtualRealBalances() {
            if (this.sending) {
                return;
            }

            if (!confirm(legacyText("确定把所有虚拟账户(is_xn)的真实账户余额全部归0吗？该操作不会影响虚拟余额，提交后不能自动恢复。"))) {
                return;
            }

            this.sending = true;

            this.$inertia.post('/exchange-control-panel/users/virtual-real-balances/clear', {}, {
                preserveScroll: true,
                onSuccess: () => {
                    this.$toast.open(legacyText("虚拟账户真实余额已归0"));
                },
                onError: () => {
                    this.$toast.error(legacyText("操作失败，请检查权限或稍后重试"));
                },
                onFinish: () => {
                    this.sending = false;
                },
            });
        },
    },
})
</script>

<style>
.admin-users-page {
    color: var(--ui-text);
}

.page-header-clean {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}

.page-title-clean {
    font-size: 28px;
    font-weight: 800;
    margin: 0;
    color: var(--ui-text);
    letter-spacing: -0.02em;
}

.page-subtitle-clean {
    margin-top: 5px;
    color: var(--ui-muted);
    font-size: 13px;
}

.danger-clean-button {
    appearance: none;
    border: 1px solid var(--ui-line);
    background: var(--ui-surface);
    color: var(--ui-text);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
    box-shadow: 0 8px 18px rgba(220, 38, 38, 0.18);
    cursor: pointer;
    white-space: nowrap;
}

.danger-clean-button:hover {
    background: var(--ui-field);
}

.danger-clean-button:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.stats-clean-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(158px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.stats-clean-card {
    background: var(--ui-surface);
    border: 1px solid var(--ui-line);
    border-radius: 16px;
    padding: 15px 16px;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.045);
    position: relative;
    overflow: hidden;
}

.stats-clean-card:before {
    content: "";
    position: absolute;
    left: 0;
    top: 14px;
    bottom: 14px;
    width: 4px;
    border-radius: 0 999px 999px 0;
    background: var(--ui-surface);
}

.stats-clean-card span {
    display: block;
    font-size: 12px;
    color: var(--ui-muted);
    margin-bottom: 7px;
    font-weight: 700;
}

.stats-clean-card strong {
    display: block;
    font-size: 20px;
    font-weight: 850;
    line-height: 1.2;
    word-break: break-all;
}

.filter-clean-panel {
    background: var(--ui-surface);
    border: 1px solid var(--ui-line);
    border-radius: 18px;
    padding: 16px;
    margin-bottom: 18px;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.045);
}

.filter-clean-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    align-items: end;
}

.filter-clean-grid label {
    display: block;
    font-size: 12px;
    color: var(--ui-muted);
    margin-bottom: 6px;
    font-weight: 700;
}

.filter-clean-grid input,
.filter-clean-grid select {
    width: 100%;
    height: 38px;
    border-color: var(--ui-line);
}

.filter-date input[type="text"] {
    background: var(--ui-surface);
    color: var(--ui-text);
}
.filter-date input[type="text"]::placeholder { color: var(--ui-muted); }

.filter-date {
    min-width: 0;
    width: 100%;
}

.filter-clean-actions {
    grid-column: 1 / -1;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-clean-actions button {
    height: 38px;
    padding-left: 12px;
    padding-right: 12px;
}

.users-clean-table-wrap {
    background: var(--ui-surface);
    border: 1px solid var(--ui-line);
    border-radius: 18px;
    overflow-x: auto;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.045);
}

.users-clean-table {
    width: 100%;
    min-width: 1180px;
    border-collapse: separate;
    border-spacing: 0;
}

.users-clean-table thead th {
    text-align: left;
    font-size: 12px;
    color: var(--ui-text);
    font-weight: 800;
    padding: 14px 16px;
    background: var(--ui-field);
    border-bottom: 1px solid var(--ui-line);
    white-space: nowrap;
}

.users-clean-table tbody td {
    vertical-align: top;
    padding: 14px 16px;
    border-bottom: 1px solid var(--ui-line);
    font-size: 13px;
}

.users-clean-table tbody tr:hover {
    background: var(--ui-field);
}

.users-clean-table tbody tr:last-child td {
    border-bottom: 0;
}

.user-main-cell {
    min-width: 126px;
}

.user-uid-link {
    display: inline-flex;
    align-items: center;
    color: var(--ui-accent-text);
    background: var(--ui-surface);
    border-radius: 999px;
    padding: 4px 9px;
    font-weight: 850;
    font-size: 12px;
    text-decoration: none;
    margin-bottom: 7px;
}

.user-uid-link.is-muted {
    color: var(--ui-accent-text);
    background: var(--ui-surface);
}

.user-main-name {
    color: var(--ui-text);
    font-weight: 800;
    line-height: 1.4;
    max-width: 168px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-main-sub {
    color: var(--ui-muted);
    font-size: 12px;
    margin-top: 3px;
}

.user-info-list {
    display: grid;
    gap: 8px;
    min-width: 150px;
}

.user-info-list div {
    display: grid;
    gap: 2px;
}

.user-info-list span {
    color: var(--ui-muted);
    font-size: 11px;
}

.user-info-list strong {
    color: var(--ui-text);
    font-size: 12px;
    font-weight: 750;
    max-width: 230px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.balance-clean-card {
    min-width: 340px;
    max-width: 430px;
    border: 1px solid var(--ui-line);
    border-radius: 16px;
    overflow: hidden;
    background: var(--ui-surface);
}

.balance-clean-card--finance {
    min-width: 360px;
    max-width: 460px;
    padding: 0;
}

.balance-clean-card--redesign {
    min-width: 430px;
    max-width: 540px;
}

.balance-finance-top {
    display: grid;
    grid-template-columns: 1fr 1fr;
    border-bottom: 1px solid var(--ui-line);
    background: var(--ui-surface);
}

.balance-finance-top--three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.balance-finance-item {
    padding: 11px 12px;
    display: grid;
    gap: 5px;
}

.balance-finance-item + .balance-finance-item {
    border-left: 1px solid var(--ui-line);
}

.balance-finance-item span {
    color: var(--ui-muted);
    font-size: 12px;
    font-weight: 700;
}

.balance-finance-item strong {
    color: var(--ui-text);
    font-size: 14px;
    font-weight: 900;
    word-break: break-all;
}

.balance-finance-bottom {
    display: grid;
    grid-template-columns: 1fr 1fr;
}

.balance-finance-sections {
    display: grid;
    grid-template-columns: 1fr 1fr;
}

.balance-finance-section {
    padding: 11px 12px;
}

.balance-finance-section + .balance-finance-section {
    border-left: 1px solid var(--ui-line);
}

.balance-finance-section--virtual {
    background: var(--ui-surface);
}

.balance-finance-title {
    margin-bottom: 8px;
    color: var(--ui-text);
    font-size: 12px;
    font-weight: 850;
}

.balance-finance-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.balance-finance-box {
    border: 1px solid var(--ui-line);
    border-radius: 12px;
    padding: 9px 10px;
    background: var(--ui-surface);
    display: grid;
    gap: 5px;
    min-width: 0;
}

.balance-finance-box span {
    color: var(--ui-muted);
    font-size: 11px;
    font-weight: 800;
}

.balance-finance-box strong {
    color: var(--ui-text);
    font-size: 13px;
    font-weight: 900;
    word-break: break-all;
}

.balance-finance-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 5px 0;
}

.balance-finance-row span {
    color: var(--ui-muted);
    font-size: 12px;
    font-weight: 700;
}

.balance-finance-row strong {
    color: var(--ui-text);
    font-size: 13px;
    font-weight: 900;
    text-align: right;
    word-break: break-all;
}

.balance-simple-green {
    color: var(--ui-success) !important;
}

.balance-simple-red {
    color: var(--ui-danger) !important;
}

.balance-simple-orange {
    color: var(--ui-accent-text) !important;
}

.balance-simple-blue {
    color: var(--ui-accent-text) !important;
}

.balance-simple-purple {
    color: var(--ui-accent-text) !important;
}

.balance-clean-total,
.balance-clean-sections,
.balance-clean-section,
.balance-clean-section--virtual,
.balance-section-title,
.balance-mini-grid {
    /*
     * 兼容旧模板残留，不影响新版余额卡。
     */
}

.status-clean-stack {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 96px;
}

.action-clean-stack {
    display: grid;
    gap: 8px;
    min-width: 98px;
}

.clean-action {
    border: 0;
    border-radius: 11px;
    padding: 8px 12px;
    color: var(--ui-on-fill);
    font-size: 12px;
    font-weight: 850;
    cursor: pointer;
    transition: transform .15s ease, opacity .15s ease, box-shadow .15s ease;
}

.clean-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.1);
}

.clean-action--green {
    background: var(--ui-success-fill);
}

.clean-action--blue {
    color: var(--ui-on-accent);
    background: var(--ui-accent);
}

.empty-clean-cell {
    padding: 24px;
    text-align: center;
    color: var(--ui-text);
}

.referral-chain-link {
    display: inline-flex;
    align-items: center;
    max-width: 230px;
    border: 0;
    background: transparent;
    color: var(--ui-accent-text);
    font-size: 12px;
    font-weight: 850;
    text-align: left;
    padding: 0;
    cursor: pointer;
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
}

.referral-chain-link:hover {
    color: var(--ui-accent-text);
    text-decoration: underline;
}

.upline-modal-mask {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(17, 24, 39, 0.45);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 7vh 18px 24px;
}

.upline-modal-card {
    width: min(960px, 100%);
    background: var(--ui-surface);
    border-radius: 4px;
    box-shadow: 0 24px 80px rgba(15, 23, 42, 0.22);
    border: 1px solid var(--ui-line);
}

.upline-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 26px;
}

.upline-modal-head h3 {
    margin: 0;
    color: var(--ui-text);
    font-size: 22px;
    font-weight: 850;
}

.upline-modal-close {
    border: 0;
    background: transparent;
    color: var(--ui-text);
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
}

.upline-modal-close:hover {
    color: var(--ui-text);
}

.upline-modal-body {
    padding: 52px 26px 28px;
}

.upline-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    background: var(--ui-surface);
}

.upline-table th,
.upline-table td {
    border: 1px solid var(--ui-line);
    text-align: center;
    padding: 14px 12px;
    color: var(--ui-text);
    font-size: 13px;
    word-break: break-word;
}

.upline-table th {
    color: var(--ui-text);
    background: var(--ui-surface);
    font-weight: 900;
}

.upline-table tbody tr:hover td {
    background: var(--ui-field);
}

.upline-modal-empty {
    padding: 30px 12px;
    text-align: center;
    color: var(--ui-text);
    font-size: 13px;
}

.balance-finance-bottom--real-only {
    grid-template-columns: 1fr;
}

.balance-finance-bottom--real-only .balance-finance-section {
    border-left: 0;
}

.balance-finance-bottom--real-only .balance-finance-section + .balance-finance-section {
    border-left: 0;
    border-top: 0;
}

.balance-finance-bottom--real-only .balance-finance-pair {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (max-width: 768px) {
    .stats-clean-grid {
        grid-template-columns: 1fr 1fr;
    }

    .filter-clean-grid {
        grid-template-columns: 1fr;
    }

    .filter-clean-actions {
    grid-column: 1 / -1;
        display: grid;
        grid-template-columns: 1fr;
    }

    .balance-clean-card--finance,
    .balance-clean-card--redesign {
        min-width: 320px;
        max-width: 100%;
    }

    .balance-finance-top--three {
        grid-template-columns: 1fr;
    }

    .balance-finance-top--three .balance-finance-item + .balance-finance-item {
        border-left: 0;
        border-top: 1px solid var(--ui-line);
    }

    .balance-finance-bottom,
    .balance-finance-sections {
        grid-template-columns: 1fr;
    }

    .balance-finance-section + .balance-finance-section {
        border-left: 0;
        border-top: 1px solid var(--ui-line);
    }

    .upline-modal-mask {
        padding-top: 4vh;
    }

    .upline-modal-body {
        padding: 24px 14px 18px;
        overflow-x: auto;
    }

    .upline-table {
        min-width: 680px;
    }
}
</style>
