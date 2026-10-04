<script>
import Template from '{Template}/Admin/Components/Reports/AdminReportsTab.template'

export default Template({
    props: ['section', 'referrer'],

    computed: {
        currentUrl() {
            return (this.$page && this.$page.url) ? this.$page.url : ''
        },

        isDepositWithdrawalSection() {
            return this.currentUrl.includes('/reports/deposits') ||
                this.currentUrl.includes('/reports/withdrawals') ||
                this.currentUrl.includes('/reports/fiat-deposits') ||
                this.currentUrl.includes('/reports/fiat-withdrawals')
        },

        isWalletSection() {
            return this.currentUrl.includes('/reports/wallets') ||
                this.currentUrl.includes('/reports/transfer-commissions') ||
                this.currentUrl.includes('/reports/wallet-adjustments') ||
                this.currentUrl.includes('/reports/wallet-balance-logs')
        },

        roles() {
            return (this.$page && this.$page.props && this.$page.props.user && this.$page.props.user.roles)
                ? this.$page.props.user.roles
                : []
        },

        isSuperAdmin() {
            return this.roles.includes('superadmin')
        },

        isSalesman() {
            return this.roles.includes('salesman')
        },

        /*
         * 财务完整菜单只给超级管理员。
         * 不再给 admin / finance_manager / user_leader / perm_finances 显示其他财务 tab。
         */
        canSeeFullFinance() {
            return this.isSuperAdmin
        },

        canSeeFeeRefunds() {
            return this.roles.some(role => [
                'superadmin',
                'admin',
                'finance_manager',
                'user_leader',
                'salesman',
                'perm_finances',
            ].includes(role))
        },

        /*
         * 开仓中订单：所有能进入后台的账号都显示。
         */
        canSeeOpenFutures() {
            return this.roles.length > 0
        },

        /*
         * 量化订单 / 质押订单：所有能进入后台的账号都显示。
         */
        canSeeFinanceOrderReports() {
            return this.roles.length > 0
        },
    },

    methods: {
        setReport(url) {
            if (this.referrer) {
                const separator = url.includes('?') ? '&' : '?';
                url = `${url}${separator}referrer=${encodeURIComponent(this.referrer)}`;
            }

            this.$inertia.visit(url);
        }
    }
})
</script>
