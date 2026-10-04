<script>
import Template from '{Template}/Web/Pages/Merchant/Earnings/Payouts.template'
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
        payouts: Object,
        stats: Object,
        currencies: Array,
        filters: Object,
        canOperate: {
            type: Boolean,
            default: true
        },
        operationRestriction: {
            type: Object,
            default: null
        }
    },

    data() {
        return {
            showPayoutModal: false,
            submitting: false,
            sending: false,
            form: {
                amount: '',
                payout_address: this.merchant.default_payout_address || '',
                payout_memo: this.merchant.default_payout_memo || '',
                currency_id: '',
                network_id: '',
                notes: ''
            },
            errors: {},
            localFilters: {
                status: this.filters.status || '',
                date_from: this.filters.date_from || '',
                date_to: this.filters.date_to || ''
            },
            statusOptions: [
                { id: '', name: this.$t('All Statuses') },
                { id: 'pending', name: this.$t('Pending') },
                { id: 'approved', name: this.$t('Approved') },
                { id: 'processing', name: this.$t('Processing') },
                { id: 'completed', name: this.$t('Completed') },
                { id: 'rejected', name: this.$t('Rejected') },
                { id: 'cancelled', name: this.$t('Cancelled') }
            ]
        }
    },

    computed: {
        selectedCurrency() {
            if (!this.form.currency_id) return null
            return this.currencies.find(c => c.id == this.form.currency_id)
        },
        availableNetworks() {
            return this.selectedCurrency.networks || []
        },
        maxPayout() {
            return parseFloat(this.merchant.available_balance_usd || 0)
        },
        minPayout() {
            return parseFloat(this.merchant.min_payout_amount_usd || 50)
        },
        payoutFee() {
            const amount = parseFloat(this.form.amount) || 0
            return (amount * 0.005).toFixed(2) // 0.5% fee
        },
        netPayout() {
            const amount = parseFloat(this.form.amount) || 0
            return (amount - parseFloat(this.payoutFee)).toFixed(2)
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
        },

        getStatusClass(status) {
            const classes = {
                'pending': 'bg-yellow-100 text-yellow-800',
                'approved': 'bg-blue-100 text-blue-800',
                'processing': 'bg-indigo-100 text-indigo-800',
                'completed': 'bg-green-100 text-green-800',
                'rejected': 'bg-red-100 text-red-800',
                'cancelled': 'bg-gray-100 text-gray-800'
            }
            return classes[status] || 'bg-gray-100 text-gray-800'
        },

        openPayoutModal() {
            if (!this.canOperate) {
                this.$toast.error(this.$t('Your account is restricted from requesting payouts'))
                return
            }
            this.showPayoutModal = true
            this.errors = {}
        },

        closePayoutModal() {
            this.showPayoutModal = false
            this.form = {
                amount: '',
                payout_address: this.merchant.default_payout_address || '',
                payout_memo: this.merchant.default_payout_memo || '',
                currency_id: '',
                network_id: '',
                notes: ''
            }
        },

        setMaxAmount() {
            this.form.amount = this.maxPayout.toFixed(2)
        },

        async submitPayout() {
            if (!this.canOperate) {
                this.$toast.error(this.$t('Your account is restricted from requesting payouts'))
                return
            }
            if (this.submitting) return

            this.submitting = true
            this.errors = {}

            try {
                const response = await axios.post(this.route('merchant.payouts.request'), this.form)
                if (response.data.success) {
                    this.$toast.open(this.$t('Payout request submitted successfully'))
                    this.closePayoutModal()
                    this.$inertia.reload()
                }
            } catch (error) {
                if (error.response.data.errors) {
                    this.errors = error.response.data.errors
                } else if (error.response.data.error.message) {
                    this.$toast.error(error.response.data.error.message)
                } else {
                    this.$toast.error(this.$t('Failed to submit payout request'))
                }
            } finally {
                this.submitting = false
            }
        },

        async cancelPayout(payoutId) {
            if (!confirm(this.$t('Are you sure you want to cancel this payout request?'))) {
                return
            }

            try {
                const response = await axios.post(this.route('merchant.payouts.cancel', payoutId))
                if (response.data.success) {
                    this.$toast.open(this.$t('Payout cancelled'))
                    this.$inertia.reload()
                }
            } catch (error) {
                this.$toast.error(error.response.data.error.message || this.$t('Failed to cancel payout'))
            }
        },

        applyFilters() {
            this.$inertia.get(this.route('merchant.payouts'), this.localFilters, {
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
