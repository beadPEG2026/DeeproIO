<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Webhooks/Index.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: { AppLayout },
    props: {
        webhooks: Object,
        stats: Object,
        filters: Object,
        statusOptions: Array
    },
    data() {
        return {
            filter: {
                status: this.filters ? this.filters.status : '',
                event_type: this.filters ? this.filters.event_type : '',
                merchant_id: this.filters ? this.filters.merchant_id : ''
            },
            processing: false,
            retryingId: null
        }
    },
    methods: {
        applyFilters() {
            this.$inertia.get(this.route('admin.merchant.webhooks'), this.filter, {
                preserveState: true
            })
        },
        clearFilters() {
            this.filter = { status: '', event_type: '', merchant_id: '' }
            this.applyFilters()
        },
        retryWebhook(webhook) {
            if (this.retryingId) return

            this.retryingId = webhook.id
            this.$inertia.post(this.route('admin.merchant.webhooks.retry', webhook.id), {}, {
                onFinish: () => {
                    this.retryingId = null
                }
            })
        },
        getStatusClass(status) {
            const classes = {
                pending: 'bg-yellow-100 text-yellow-800',
                processing: 'bg-blue-100 text-blue-800',
                delivered: 'bg-green-100 text-green-800',
                pending_retry: 'bg-orange-100 text-orange-800',
                failed: 'bg-red-100 text-red-800',
                skipped_duplicate: 'bg-gray-100 text-gray-600'
            }
            return classes[status] || 'bg-gray-100 text-gray-600'
        },
        formatDate(date) {
            if (!date) return '-'
            return new Date(date).toLocaleString()
        }
    }
})
</script>
