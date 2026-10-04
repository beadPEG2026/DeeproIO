<script>
import Template from '{Template}/Web/Pages/Merchant/Earnings/Deposits.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'
import Pagination from '@/Jetstream/Pagination'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu,
        Pagination
    },

    props: {
        merchant: Object,
        deposits: Object,
        stats: Object,
        filters: Object
    },

    data() {
        return {
            sending: false,
            localFilters: {
                status: this.filters.status || '',
                date_from: this.filters.date_from || '',
                date_to: this.filters.date_to || ''
            },
            statusOptions: [
                { id: '', name: this.$t('All Statuses') },
                { id: 'detecting', name: this.$t('Detecting') },
                { id: 'confirming', name: this.$t('Confirming') },
                { id: 'confirmed', name: this.$t('Confirmed') },
                { id: 'failed', name: this.$t('Failed') }
            ]
        }
    },

    methods: {
        formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(amount || 0)
        },

        formatCrypto(amount, symbol) {
            return parseFloat(amount || 0).toFixed(8) + ' ' + (symbol || '')
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
                'detecting': 'bg-yellow-100 text-yellow-800',
                'confirming': 'bg-blue-100 text-blue-800',
                'confirmed': 'bg-green-100 text-green-800',
                'failed': 'bg-red-100 text-red-800'
            }
            return classes[status] || 'bg-gray-100 text-gray-800'
        },

        applyFilters() {
            this.$inertia.get(this.route('merchant.deposits'), this.localFilters, {
                preserveState: true,
                preserveScroll: true
            })
        },

        clearFilters() {
            this.localFilters = { status: '', date_from: '', date_to: '' }
            this.applyFilters()
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'))
            }, function (e) {})
        }
    }
})
</script>
