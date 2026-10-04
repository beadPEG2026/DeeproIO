<script>
import Template from '{Template}/Web/Pages/Merchant/Earnings/Index.template'
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
        recentTransactions: Array,
        monthlyEarnings: Array
    },

    data() {
        return {}
    },

    computed: {
        conversionRate() {
            if (this.stats.total_invoices === 0) return 0
            return ((this.stats.paid_invoices / this.stats.total_invoices) * 100).toFixed(1)
        }
    },

    methods: {
        formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(amount || 0)
        },

        formatDate(date) {
            if (!date) return '-'
            return new Date(date).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            })
        }
    }
})
</script>
