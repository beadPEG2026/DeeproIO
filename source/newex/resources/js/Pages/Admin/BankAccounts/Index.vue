<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/BankAccounts/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        JetConfirmationModal,
        JetSecondaryButton,
        JetDangerButton,
    },

    props: {
        bankAccounts: Object,
        filters: {
            type: Object,
            default: () => ({})
        },
    },

    data() {
        return {
            sending: false,
            selectedAccount: null,
            selectedAction: null,

            form: {
                search: this.filters.search || null,
                status_type: this.filters.status_type === undefined ? null : this.filters.status_type,
                user_id: this.filters.user_id || null,
                per_page: this.filters.per_page || '50',
            },
        }
    },

    methods: {
        bankAccountsUrl(params = null) {
            const baseUrl = '/exchange-control-panel/bank-accounts';

            if (!params || Object.keys(params).length === 0) {
                return baseUrl;
            }

            const query = new URLSearchParams();

            Object.keys(params).forEach(key => {
                if (
                    params[key] !== null &&
                    params[key] !== undefined &&
                    params[key] !== ''
                ) {
                    query.append(key, params[key]);
                }
            });

            const queryString = query.toString();

            return queryString ? `${baseUrl}?${queryString}` : baseUrl;
        },

        moderateUrl(id) {
            return `/exchange-control-panel/bank-accounts/${id}/moderate`;
        },

        deleteUrl(id) {
            return `/exchange-control-panel/bank-accounts/${id}`;
        },

        editUrl(id) {
            return `/exchange-control-panel/bank-accounts/${id}/edit`;
        },

        createUrl() {
            return '/exchange-control-panel/bank-accounts/create';
        },

        getList() {
            let query = pickBy(this.form, value => {
                return value !== null && value !== undefined && value !== '';
            });

            this.$inertia.replace(
                this.bankAccountsUrl(query),
                {
                    preserveScroll: true,
                }
            );
        },

        reset() {
            this.form = {
                search: null,
                status_type: null,
                user_id: null,
                per_page: '50',
            };

            this.getList();
        },

        userName(account) {
            if (account.admin_user && account.admin_user.name) {
                return account.admin_user.name;
            }

            if (account.admin_user && account.admin_user.email) {
                return account.admin_user.email;
            }

            if (account.user && account.user.name) {
                return account.user.name;
            }

            if (account.user && account.user.email) {
                return account.user.email;
            }

            return '-';
        },

        userEmail(account) {
            if (account.admin_user && account.admin_user.email) {
                return account.admin_user.email;
            }

            if (account.user && account.user.email) {
                return account.user.email;
            }

            return null;
        },

        userId(account) {
            if (account.admin_user && account.admin_user.id) {
                return account.admin_user.id;
            }

            if (account.user && account.user.id) {
                return account.user.id;
            }

            return account.user_id || null;
        },

        bankNumber(account) {
            return account.iban || account.account_number || account.card_number || account.card_no || '-';
        },

        bankName(account) {
            return account.name || account.bank_name || account.bank || '-';
        },

        statusType(account) {
            if (account.status_type !== null && account.status_type !== undefined) {
                return parseInt(account.status_type);
            }

            return account.status ? 1 : 0;
        },

        statusText(account) {
            const status = this.statusType(account);

            if (status === 1) {
                return legacyText("通过");
            }

            if (status === 2) {
                return legacyText("拒绝");
            }

            return legacyText("待审核");
        },

        statusBadgeType(account) {
            const status = this.statusType(account);

            if (status === 1) {
                return 'green';
            }

            if (status === 2) {
                return 'red';
            }

            return 'orange';
        },

        askApprove(account) {
            this.selectedAccount = account;
            this.selectedAction = 'approve';
        },

        askReject(account) {
            this.selectedAccount = account;
            this.selectedAction = 'reject';
        },

        askDelete(account) {
            this.selectedAccount = account;
            this.selectedAction = 'delete';
        },

        closeActionModal() {
            this.selectedAccount = null;
            this.selectedAction = null;
        },

        actionTitle() {
            if (this.selectedAction === 'approve') {
                return legacyText("通过银行账户");
            }

            if (this.selectedAction === 'reject') {
                return legacyText("拒绝银行账户");
            }

            if (this.selectedAction === 'delete') {
                return legacyText("删除银行账户");
            }

            return legacyText("确认操作");
        },

        actionContent() {
            if (this.selectedAction === 'approve') {
                return legacyText("确定要通过这个银行账户吗？");
            }

            if (this.selectedAction === 'reject') {
                return legacyText("确定要拒绝这个银行账户吗？");
            }

            if (this.selectedAction === 'delete') {
                return legacyText("确定要删除这个银行账户吗？删除后无法恢复。");
            }

            return legacyText("确定要继续吗？");
        },

        actionButtonText() {
            if (this.selectedAction === 'approve') {
                return legacyText("确认通过");
            }

            if (this.selectedAction === 'reject') {
                return legacyText("确认拒绝");
            }

            if (this.selectedAction === 'delete') {
                return legacyText("确认删除");
            }

            return legacyText("确认");
        },

        confirmAction() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.');
            }

            if (!this.selectedAccount || !this.selectedAction || this.sending) {
                return;
            }

            if (this.selectedAction === 'delete') {
                this.deleteAccount();
                return;
            }

            this.$inertia.put(
                this.moderateUrl(this.selectedAccount.id),
                {
                    action: this.selectedAction,
                },
                {
                    preserveScroll: true,
                    onStart: () => this.sending = true,
                    onFinish: () => this.sending = false,
                    onSuccess: () => {
                        this.$toast.open(legacyText("操作成功"));
                        this.closeActionModal();
                    },
                    onError: () => {
                        this.$toast.error(legacyText("操作失败"));
                    },
                }
            );
        },

        deleteAccount() {
            this.$inertia.delete(
                this.deleteUrl(this.selectedAccount.id),
                {
                    preserveScroll: true,
                    onStart: () => this.sending = true,
                    onFinish: () => this.sending = false,
                    onSuccess: () => {
                        this.$toast.open(legacyText("银行账户已删除"));
                        this.closeActionModal();
                    },
                    onError: () => {
                        this.$toast.error(legacyText("删除失败"));
                    },
                }
            );
        },
    },

    watch: {
        form: {
            handler: throttle(function () {
                this.getList();
            }, 300),
            deep: true,
        },
    },
})
</script>