<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Merchants/Show.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: {
        AppLayout
    },
    props: {
        merchant: Object,
        invoiceStats: Object,
        recentInvoices: Array,
        webhookStats: Object
    },
    data() {
        return {
            showVerifyModal: false,
            showSuspendModal: false,
            showReactivateModal: false,
            showLimitsModal: false,
            verifyForm: { verification_status: 'verified', notes: '' },
            suspendForm: { reason: '' },
            reactivateForm: { notes: '' },
            limitsForm: {
                daily_volume_limit_usd: 0,
                monthly_volume_limit_usd: 0,
                single_invoice_limit_usd: 0,
                fee_percent: 0
            },
            processing: false
        }
    },
    mounted() {
        this.initLimitsForm()
    },
    methods: {
        initLimitsForm() {
            this.limitsForm = {
                daily_volume_limit_usd: this.merchant.daily_volume_limit_usd || 0,
                monthly_volume_limit_usd: this.merchant.monthly_volume_limit_usd || 0,
                single_invoice_limit_usd: this.merchant.single_invoice_limit_usd || 0,
                fee_percent: this.merchant.fee_percent || 0
            }
        },
        openLimitsModal() {
            this.initLimitsForm()
            this.showLimitsModal = true
        },
        verifyMerchant() {
            this.processing = true
            this.$inertia.post(this.route('admin.merchant.merchants.verify', this.merchant.id), this.verifyForm, {
                onFinish: () => {
                    this.processing = false
                    this.showVerifyModal = false
                }
            })
        },
        suspendMerchant() {
            this.processing = true
            this.$inertia.post(this.route('admin.merchant.merchants.suspend', this.merchant.id), this.suspendForm, {
                onFinish: () => {
                    this.processing = false
                    this.showSuspendModal = false
                }
            })
        },
        reactivateMerchant() {
            this.processing = true
            this.$inertia.post(this.route('admin.merchant.merchants.reactivate', this.merchant.id), this.reactivateForm, {
                onFinish: () => {
                    this.processing = false
                    this.showReactivateModal = false
                    this.reactivateForm = { notes: '' }
                }
            })
        },
        updateLimits() {
            this.processing = true
            this.$inertia.post(this.route('admin.merchant.merchants.update-limits', this.merchant.id), this.limitsForm, {
                onFinish: () => {
                    this.processing = false
                    this.showLimitsModal = false
                }
            })
        },
        getStatusBadge(status) {
            const badges = {
                'active': 'bg-green-100 text-green-800',
                'pending': 'bg-yellow-100 text-yellow-800',
                'suspended': 'bg-red-100 text-red-800',
                'verified': 'bg-green-100 text-green-800',
                'rejected': 'bg-red-100 text-red-800'
            }
            return badges[status] || 'bg-gray-100 text-gray-800'
        }
    }
})
</script>
