<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Reports/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import SimplePie from '@/Components/Charts/SimplePie'

export default Template({
    components: {
        AppLayout,
        SimplePie
    },

    props: {
        filters: Object,
        summary: Object,
        dailyData: Array,
        invoicesByStatus: Array,
        volumeByCurrency: Array,
        volumeByNetwork: Array,
        topMerchants: Array,
        merchantGrowth: Array,
        conversionFunnel: Array,
        hourlyDistribution: Array,
        payoutStats: Object,
        feeAnalysis: Object,
        recentActivity: Array
    },

    data() {
        return {
            form: {
                date_from: this.filters.date_from,
                date_to: this.filters.date_to,
                report_type: this.filters.report_type || 'overview'
            },
            activeTab: 'overview',
            exportLoading: false
        }
    },

    computed: {
        // Chart colors
        statusColors() {
            return {
                'pending': '#FCD34D',
                'awaiting_payment': '#60A5FA',
                'partially_paid': '#F97316',
                'paid': '#10B981',
                'overpaid': '#8B5CF6',
                'underpaid': '#F59E0B',
                'expired': '#EF4444',
                'cancelled': '#6B7280',
                'settled': '#059669'
            }
        },

        networkColors() {
            return ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#84CC16', '#F97316', '#6366F1']
        },

        currencyColors() {
            return ['#F7931A', '#627EEA', '#26A17B', '#F3BA2F', '#E84142', '#2775CA', '#14F195', '#000000', '#C3A634', '#345D9D']
        },

        // Pie chart data for status breakdown
        statusPieData() {
            if (!this.invoicesByStatus) return []
            return this.invoicesByStatus.map((item, index) => ({
                label: this.formatStatus(item.status),
                value: item.count,
                color: this.statusColors[item.status] || '#999'
            }))
        },

        // Pie chart data for currency volume
        currencyPieData() {
            if (!this.volumeByCurrency) return []
            return this.volumeByCurrency.map((item, index) => ({
                label: item.symbol,
                value: item.total_usd,
                color: this.currencyColors[index] || '#999'
            }))
        },

        // Pie chart data for network volume
        networkPieData() {
            if (!this.volumeByNetwork) return []
            return this.volumeByNetwork.map((item, index) => ({
                label: item.name,
                value: item.total_usd,
                color: this.networkColors[index] || '#999'
            }))
        },

        // Max values for bar chart scaling
        maxDailyVolume() {
            if (!this.dailyData) return 0
            return Math.max(...this.dailyData.map(d => d.volume), 1)
        },

        maxDailyInvoices() {
            if (!this.dailyData) return 0
            return Math.max(...this.dailyData.map(d => d.total_invoices), 1)
        },

        maxHourlyVolume() {
            if (!this.hourlyDistribution) return 0
            return Math.max(...this.hourlyDistribution.map(d => d.volume), 1)
        },

        // Period days
        periodDays() {
            if (!this.form.date_from || !this.form.date_to) return 30
            const start = new Date(this.form.date_from)
            const end = new Date(this.form.date_to)
            return Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1
        }
    },

    methods: {
        applyFilters() {
            this.$inertia.get(this.route('admin.merchant.reports'), this.form, {
                preserveState: true,
                preserveScroll: true
            })
        },

        setPreset(preset) {
            const today = new Date()
            let startDate, endDate = today.toISOString().split('T')[0]

            switch (preset) {
                case 'today':
                    startDate = endDate
                    break
                case 'yesterday':
                    const yesterday = new Date(today)
                    yesterday.setDate(yesterday.getDate() - 1)
                    startDate = endDate = yesterday.toISOString().split('T')[0]
                    break
                case '7days':
                    const week = new Date(today)
                    week.setDate(week.getDate() - 6)
                    startDate = week.toISOString().split('T')[0]
                    break
                case '30days':
                    const month = new Date(today)
                    month.setDate(month.getDate() - 29)
                    startDate = month.toISOString().split('T')[0]
                    break
                case '90days':
                    const quarter = new Date(today)
                    quarter.setDate(quarter.getDate() - 89)
                    startDate = quarter.toISOString().split('T')[0]
                    break
                case 'thisMonth':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0]
                    break
                case 'lastMonth':
                    const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1)
                    startDate = lastMonth.toISOString().split('T')[0]
                    endDate = new Date(today.getFullYear(), today.getMonth(), 0).toISOString().split('T')[0]
                    break
            }

            this.form.date_from = startDate
            this.form.date_to = endDate
            this.applyFilters()
        },

        formatCurrency(value) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(value || 0)
        },

        formatNumber(value) {
            return new Intl.NumberFormat('en-US').format(value || 0)
        },

        formatPercent(value) {
            return Number(value || 0).toFixed(1) + '%'
        },

        formatStatus(status) {
            if (!status) return 'Unknown'
            return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
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
        },

        getStatusClass(status) {
            const classes = {
                'pending': 'bg-yellow-100 text-yellow-800',
                'awaiting_payment': 'bg-blue-100 text-blue-800',
                'partially_paid': 'bg-orange-100 text-orange-800',
                'paid': 'bg-green-100 text-green-800',
                'overpaid': 'bg-purple-100 text-purple-800',
                'underpaid': 'bg-amber-100 text-amber-800',
                'expired': 'bg-red-100 text-red-800',
                'cancelled': 'bg-gray-100 text-gray-800',
                'settled': 'bg-emerald-100 text-emerald-800'
            }
            return classes[status] || 'bg-gray-100 text-gray-800'
        },

        getChangeClass(value) {
            if (value > 0) return 'text-green-600'
            if (value < 0) return 'text-red-600'
            return 'text-gray-500'
        },

        getChangeIcon(value) {
            if (value > 0) return '↑'
            if (value < 0) return '↓'
            return '→'
        },

        barHeight(value, max) {
            if (max === 0) return 0
            return Math.max((value / max) * 100, 2)
        },

        exportReport(format) {
            this.exportLoading = true
            // Create downloadable report
            const data = {
                summary: this.summary,
                dailyData: this.dailyData,
                topMerchants: this.topMerchants,
                invoicesByStatus: this.invoicesByStatus
            }

            if (format === 'json') {
                const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
                const url = URL.createObjectURL(blob)
                const a = document.createElement('a')
                a.href = url
                a.download = `merchant-report-${this.form.date_from}-${this.form.date_to}.json`
                a.click()
                URL.revokeObjectURL(url)
            } else if (format === 'csv') {
                let csv = 'Date,Total Invoices,Paid Invoices,Volume (USD),Fees (USD)\n'
                this.dailyData.forEach(d => {
                    csv += `${d.date},${d.total_invoices},${d.paid_invoices},${d.volume.toFixed(2)},${d.fees.toFixed(2)}\n`
                })
                const blob = new Blob([csv], { type: 'text/csv' })
                const url = URL.createObjectURL(blob)
                const a = document.createElement('a')
                a.href = url
                a.download = `merchant-report-${this.form.date_from}-${this.form.date_to}.csv`
                a.click()
                URL.revokeObjectURL(url)
            }

            setTimeout(() => {
                this.exportLoading = false
                this.$toast.open(this.$t('Report exported successfully'))
            }, 500)
        }
    }
})
</script>
