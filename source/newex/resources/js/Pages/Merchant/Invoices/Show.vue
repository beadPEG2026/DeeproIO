<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Merchant/Invoices/Show.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu
    },

    props: {
        invoice: Object
    },

    data() {
        return {
            cancelling: false,
            showCancelModal: false,
            cancelReason: ''
        }
    },

    computed: {
        canCancel() {
            return ['awaiting_selection', 'awaiting_payment'].includes(this.invoice.status)
        },
        hasPayments() {
            return this.invoice.payments && this.invoice.payments.length > 0
        },
        hasWebhooks() {
            return this.invoice.webhooks && this.invoice.webhooks.length > 0
        },
        hasTimeline() {
            return this.invoice.timeline && this.invoice.timeline.length > 0
        },
        checkoutUrl() {
            return `${window.location.origin}/pay/i/${this.invoice.id}`
        }
    },

    methods: {
        getStatusBadge(status) {
            const badges = {
                'awaiting_selection': { label: 'Awaiting Selection', color: 'bg-gray-100 text-gray-800' },
                'awaiting_payment': { label: 'Awaiting Payment', color: 'bg-yellow-100 text-yellow-800' },
                'detecting': { label: legacyText("Detecting"), color: 'bg-blue-100 text-blue-800' },
                'confirming': { label: legacyText("Confirming"), color: 'bg-blue-100 text-blue-800' },
                'paid': { label: legacyText("Paid"), color: 'bg-green-100 text-green-800' },
                'overpaid': { label: 'Overpaid', color: 'bg-orange-100 text-orange-800' },
                'underpaid': { label: 'Underpaid', color: 'bg-orange-100 text-orange-800' },
                'settled': { label: 'Settled', color: 'bg-green-100 text-green-800' },
                'expired': { label: 'Expired', color: 'bg-red-100 text-red-800' },
                'cancelled': { label: legacyText("Cancelled"), color: 'bg-gray-100 text-gray-800' },
                'failed': { label: legacyText("Failed"), color: 'bg-red-100 text-red-800' }
            }
            return badges[status] || { label: status, color: 'bg-gray-100 text-gray-800' }
        },

        formatCurrency(value) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(value || 0)
        },

        formatCrypto(value) {
            if (!value) return '-'
            return parseFloat(value).toString()
        },

        formatDate(date) {
            if (!date) return '-'
            return new Date(date).toLocaleString()
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {
            })
        },

        openCancelModal() {
            this.showCancelModal = true
        },

        async cancelInvoice() {
            if (this.cancelling) return

            this.cancelling = true

            try {
                await axios.post(this.route('merchant.api.invoices.cancel', this.invoice.id), {
                    reason: this.cancelReason
                })
                this.$toast.open(this.$t('Invoice cancelled'))
                this.$inertia.reload()
            } catch (error) {
                this.$toast.error(error.response.data.error.message || 'Failed to cancel invoice')
            } finally {
                this.cancelling = false
                this.showCancelModal = false
            }
        },

        goBack() {
            this.$inertia.visit(this.route('merchant.invoices'))
        }
    }
})
</script>
