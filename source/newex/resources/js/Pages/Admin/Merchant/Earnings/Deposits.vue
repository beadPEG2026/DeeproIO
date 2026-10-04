<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Earnings/Deposits.template'
import AdminLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'

export default Template({
    components: {
        AdminLayout,
        Pagination
    },

    props: {
        deposits: Object,
        stats: Object,
        merchants: Array,
        filters: Object
    },

    data() {
        return {
            localFilters: {
                merchant_id: this.filters ? this.filters.merchant_id : '',
                status: this.filters ? this.filters.status : '',
                date_from: this.filters ? this.filters.date_from : '',
                date_to: this.filters ? this.filters.date_to : ''
            },
            processing: false,
            processingAddressId: null
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
            if (!amount) return '0'
            const formatted = parseFloat(amount).toFixed(8).replace(/\.?0+$/, '')
            return symbol ? `${formatted} ${symbol}` : formatted
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
                'failed': 'bg-red-100 text-red-800',
                'orphaned': 'bg-gray-100 text-gray-800'
            }
            return classes[status] || 'bg-gray-100 text-gray-800'
        },

        getSweepStatusClass(deposit) {
            const address = deposit.deposit_address
            if (!address) return 'bg-gray-100 text-gray-800'
            
            if (address.swept_at) {
                return 'bg-green-100 text-green-800'
            }
            if (address.is_sweep_required) {
                return 'bg-yellow-100 text-yellow-800'
            }
            // is_sweep_required = false AND swept_at = null means failed
            return 'bg-red-100 text-red-800'
        },

        getSweepStatusText(deposit) {
            const address = deposit.deposit_address
            if (!address) return '-'
            
            if (address.swept_at) {
                return this.$t('Swept')
            }
            if (address.is_sweep_required) {
                return this.$t('Pending')
            }
            // is_sweep_required = false AND swept_at = null means failed
            return this.$t('Failed')
        },

        canRetrySweep(deposit) {
            const address = deposit.deposit_address
            if (!address) return false
            // Can retry if: is_sweep_required = false AND swept_at = null (failed state)
            return !address.is_sweep_required && !address.swept_at
        },

        applyFilters() {
            this.$inertia.get(this.route('admin.merchant.earnings.deposits'), this.localFilters, {
                preserveState: true,
                preserveScroll: true
            })
        },

        clearFilters() {
            this.localFilters = { merchant_id: '', status: '', date_from: '', date_to: '' }
            this.applyFilters()
        },

        async retrySweep(deposit) {
            // Prevent double click - check both global processing flag and specific address
            if (this.processing || this.processingAddressId) return
            
            const addressId = deposit.deposit_address?.id
            if (!addressId) {
                this.$toast.error(this.$t('Deposit address not found'))
                return
            }

            if (!confirm(this.$t('Retry sweep for this deposit address? This will attempt to transfer funds to the hot wallet.'))) {
                return
            }

            // Set both flags to prevent any double-click
            this.processing = true
            this.processingAddressId = addressId

            this.$inertia.post(this.route('admin.merchant.earnings.deposits.retry-sweep', addressId), {}, {
                onSuccess: () => {
                    this.$toast.open(this.$t('Sweep retry initiated'))
                },
                onError: () => {
                    this.$toast.error(this.$t('Failed to retry sweep'))
                },
                onFinish: () => {
                    this.processing = false
                    this.processingAddressId = null
                }
            })
        },

        isRetryingAddress(deposit) {
            return this.processing && this.processingAddressId === deposit.deposit_address?.id
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'))
            }, function (e) {})
        }
    }
})
</script>
