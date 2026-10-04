<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Merchant/Dashboard.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu
    },

    props: {
        merchant: Object,
        stats: Object,
        recentInvoices: Array,
        dailyVolume: Number,
        monthlyVolume: Number,
        limits: Object
    },

    data() {
        return {
            chartData: null,
            loading: false
        }
    },

    computed: {
        conversionRate() {
            if (!this.stats.total_invoices) return 0
            return ((this.stats.paid_invoices / this.stats.total_invoices) * 100).toFixed(1)
        },
        dailyLimitUsage() {
            if (!this.limits.daily_limit_usd) return 0
            return ((this.dailyVolume / this.limits.daily_limit_usd) * 100).toFixed(1)
        },
        isVerified() {
            return this.merchant.verification_status === 'verified'
        },
        statusColor() {
            const colors = {
                'active': 'green',
                'pending': 'yellow',
                'suspended': 'red',
                'inactive': 'gray'
            }
            return colors[this.merchant.status] || 'gray'
        }
    },

    methods: {
        formatCurrency(value) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(value || 0)
        },

        formatNumber(value) {
            return new Intl.NumberFormat('en-US').format(value || 0)
        },

        getStatusBadge(status) {
            const badges = {
                'awaiting_selection': { label: legacyText("Pending"), color: 'gray' },
                'awaiting_payment': { label: legacyText("Pending"), color: 'yellow' },
                'detecting': { label: legacyText("Detecting"), color: 'blue' },
                'confirming': { label: legacyText("Confirming"), color: 'blue' },
                'paid': { label: legacyText("Paid"), color: 'green' },
                'overpaid': { label: 'Overpaid', color: 'orange' },
                'underpaid': { label: 'Underpaid', color: 'orange' },
                'settled': { label: 'Settled', color: 'green' },
                'expired': { label: 'Expired', color: 'red' },
                'cancelled': { label: legacyText("Cancelled"), color: 'gray' },
                'failed': { label: legacyText("Failed"), color: 'red' }
            }
            return badges[status] || { label: status, color: 'gray' }
        },

        viewInvoice(id) {
            this.$inertia.visit(this.route('merchant.invoices.show', id))
        },

        createInvoice() {
            this.$inertia.visit(this.route('merchant.invoices.create'))
        }
    }
})
</script>
