<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Merchant/Webhooks/Index.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'
import Pagination from '@/Jetstream/Pagination'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu,
        Pagination
    },

    props: {
        webhooks: Object,
        filters: Object,
        stats: Object
    },

    data() {
        return {
            form: {
                status: this.filters.status || '',
                event_type: this.filters.event_type || '',
                invoice_id: this.filters.invoice_id || ''
            },
            sending: false,
            retrying: null,
            eventTypes: [
                { id: '', name: 'All Events' },
                { id: 'invoice.created', name: 'Invoice Created' },
                { id: 'invoice.pending', name: 'Invoice Pending' },
                { id: 'invoice.payment_detecting', name: 'Payment Detecting' },
                { id: 'invoice.confirming', name: legacyText("Confirming") },
                { id: 'invoice.paid', name: legacyText("Paid") },
                { id: 'invoice.overpaid', name: 'Overpaid' },
                { id: 'invoice.underpaid', name: 'Underpaid' },
                { id: 'invoice.settled', name: 'Settled' },
                { id: 'invoice.expired', name: 'Expired' },
                { id: 'invoice.cancelled', name: legacyText("Cancelled") }
            ],
            statuses: [
                { id: '', name: legacyText("All Statuses") },
                { id: 'pending', name: legacyText("Pending") },
                { id: 'delivered', name: legacyText("Delivered") },
                { id: 'failed', name: legacyText("Failed") },
                { id: 'pending_retry', name: 'Pending Retry' }
            ]
        }
    },

    methods: {
        getList() {
            if (this.sending) return

            this.sending = true

            const afterRequest = {
                onFinish: () => { this.sending = false },
                preserveScroll: true
            }

            const query = pickBy(this.form)
            this.$inertia.replace(
                this.route('merchant.webhooks', Object.keys(query).length ? query : { remember: 'forget' }),
                afterRequest
            )
        },

        async retryWebhook(webhook) {
            if (this.retrying === webhook.id) return

            this.retrying = webhook.id

            try {
                await axios.post(this.route('merchant.api.webhooks.retry', webhook.id))
                this.$toast.open(this.$t('Webhook retry initiated'))
                this.$inertia.reload()
            } catch (error) {
                this.$toast.error(error.response.data.error.message || 'Failed to retry webhook')
            } finally {
                this.retrying = null
            }
        },

        getStatusBadge(status) {
            const badges = {
                'pending': { label: legacyText("Pending"), color: 'bg-yellow-100 text-yellow-800' },
                'processing': { label: legacyText("Processing"), color: 'bg-blue-100 text-blue-800' },
                'delivered': { label: legacyText("Delivered"), color: 'bg-green-100 text-green-800' },
                'failed': { label: legacyText("Failed"), color: 'bg-red-100 text-red-800' },
                'pending_retry': { label: 'Pending Retry', color: 'bg-orange-100 text-orange-800' }
            }
            return badges[status] || { label: status, color: 'bg-gray-100 text-gray-800' }
        },

        formatDate(date) {
            if (!date) return '-'
            return new Date(date).toLocaleString()
        }
    },

    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 300),
            deep: true
        }
    }
})
</script>
