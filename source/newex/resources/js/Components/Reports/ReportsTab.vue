<script>
import Template from '{Template}/Web/Components/Reports/ReportsTab.template'

export default Template({
    props: ['section'],

    data() {
        return {
            tabPage: '',
        }
    },

    mounted() {
        if (this.section == "trades")
            this.tabPage = 'reports.trades';

        if (this.section == "futures_trades")
            this.tabPage = 'reports.trades.futures';

        if (this.section == "funding_fee_distributions")
            this.tabPage = 'reports.funding-fee-distributions';

        if (this.section == "fee_refunds")
            this.tabPage = 'reports.fee-refunds';

        if (this.section == "deposits")
            this.tabPage = 'reports.deposits';

        if (this.section == "withdrawals")
            this.tabPage = 'reports.withdrawals';

        if (this.section == "fiat_deposits")
            this.tabPage = 'reports.deposits.fiat';

        if (this.section == "fiat_withdrawals")
            this.tabPage = 'reports.withdrawals.fiat';

        if (this.section == "referrals")
            this.tabPage = 'reports.referral-transactions';

        if (this.section == "launchpads")
            this.tabPage = 'reports.launchpad-transactions';

        if (this.section == "options")
            this.tabPage = 'reports.options';

        if (this.section == "bonuses")
            this.tabPage = 'reports.bonuses';

        if (this.section == "adjustments")
            this.tabPage = 'reports.adjustments';
    },

    methods: {
        routeOrUrl(report) {
            const urlMap = {
                'reports.fee-refunds': '/reports/fee-refunds',
            }

            if (urlMap[report]) {
                return urlMap[report]
            }

            return this.route(report)
        },

        setReport(report) {
            this.$inertia.visit(this.routeOrUrl(report));
        },

        setTabPage() {
            this.$inertia.visit(this.routeOrUrl(this.tabPage));
        }
    }
})
</script>