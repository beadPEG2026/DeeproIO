<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Earnings/Payouts.template'
import AdminLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'

export default Template({
    components: {
        AdminLayout,
        Pagination
    },

    props: {
        payouts: Object,
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
            showApproveModal: false,
            showRejectModal: false,
            showCompleteModal: false,
            selectedPayout: null,
            approveNotes: '',
            sendImmediately: false,
            rejectReason: '',
            rejectNotes: '',
            completeTxnHash: '',
            completeExplorerUrl: '',
            processing: false
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
                'cancelled': 'bg-gray-100 text-gray-800',
                'failed': 'bg-orange-100 text-orange-800'
            }
            return classes[status] || 'bg-gray-100 text-gray-800'
        },

        applyFilters() {
            this.$inertia.get(this.route('admin.merchant.payouts'), this.localFilters, {
                preserveState: true,
                preserveScroll: true
            })
        },

        clearFilters() {
            this.localFilters = { merchant_id: '', status: '', date_from: '', date_to: '' }
            this.applyFilters()
        },

        openApproveModal(payout) {
            this.selectedPayout = payout
            this.approveNotes = ''
            this.showApproveModal = true
        },

        openRejectModal(payout) {
            this.selectedPayout = payout
            this.rejectReason = ''
            this.rejectNotes = ''
            this.showRejectModal = true
        },

        openCompleteModal(payout) {
            this.selectedPayout = payout
            this.completeTxnHash = ''
            this.completeExplorerUrl = ''
            this.showCompleteModal = true
        },

        closeModals() {
            this.showApproveModal = false
            this.showRejectModal = false
            this.showCompleteModal = false
            this.selectedPayout = null
        },

        async approvePayout() {
            if (this.processing) return
            this.processing = true

            this.$inertia.post(this.route('admin.merchant.payouts.approve', this.selectedPayout.id), {
                notes: this.approveNotes,
                send_immediately: this.sendImmediately
            }, {
                onSuccess: () => {
                    this.closeModals()
                    this.$toast.open(this.sendImmediately ? this.$t('Payout approved and sent') : this.$t('Payout approved'))
                },
                onError: () => {
                    this.$toast.error(this.$t('Failed to approve payout'))
                },
                onFinish: () => {
                    this.processing = false
                }
            })
        },

        async processPayout(payout) {
            if (this.processing) return

            if (!confirm(this.$t('Send this payout now? This will transfer crypto to the merchant.'))) {
                return
            }

            this.processing = true

            this.$inertia.post(this.route('admin.merchant.payouts.process', payout.id), {}, {
                onSuccess: () => {
                    this.$toast.open(this.$t('Payout sent successfully'))
                },
                onError: () => {
                    this.$toast.error(this.$t('Failed to send payout'))
                },
                onFinish: () => {
                    this.processing = false
                }
            })
        },

        async retryPayout(payout) {
            if (this.processing) return

            if (!confirm(this.$t('Retry this failed payout?'))) {
                return
            }

            this.processing = true

            this.$inertia.post(this.route('admin.merchant.payouts.retry', payout.id), {}, {
                onSuccess: () => {
                    this.$toast.open(this.$t('Payout retry successful'))
                },
                onError: () => {
                    this.$toast.error(this.$t('Payout retry failed'))
                },
                onFinish: () => {
                    this.processing = false
                }
            })
        },

        async rejectPayout() {
            if (this.processing || !this.rejectReason) return
            this.processing = true

            this.$inertia.post(this.route('admin.merchant.payouts.reject', this.selectedPayout.id), {
                reason: this.rejectReason,
                notes: this.rejectNotes
            }, {
                onSuccess: () => {
                    this.closeModals()
                    this.$toast.open(this.$t('Payout rejected'))
                },
                onError: () => {
                    this.$toast.error(this.$t('Failed to reject payout'))
                },
                onFinish: () => {
                    this.processing = false
                }
            })
        },

        async completePayout() {
            if (this.processing || !this.completeTxnHash) return
            this.processing = true

            this.$inertia.post(this.route('admin.merchant.payouts.complete', this.selectedPayout.id), {
                txn_hash: this.completeTxnHash,
                explorer_url: this.completeExplorerUrl
            }, {
                onSuccess: () => {
                    this.closeModals()
                    this.$toast.open(this.$t('Payout marked as completed'))
                },
                onError: () => {
                    this.$toast.error(this.$t('Failed to complete payout'))
                },
                onFinish: () => {
                    this.processing = false
                }
            })
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'))
            }, function (e) {})
        }
    }
})
</script>
