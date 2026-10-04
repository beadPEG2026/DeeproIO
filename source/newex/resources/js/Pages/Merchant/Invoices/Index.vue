<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Merchant/Invoices/Index.template'
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
        invoices: Object,
        filters: Object,
        stats: Object
    },

    data() {
        return {
            form: {
                status: this.filters.status || '',
                external_id: this.filters.external_id || '',
                customer_email: this.filters.customer_email || '',
                created_from: this.filters.created_from || '',
                created_to: this.filters.created_to || ''
            },
            sending: false,
            statuses: [
                { id: '', name: legacyText("All Statuses") },
                { id: 'awaiting_selection', name: legacyText("Pending") },
                { id: 'awaiting_payment', name: legacyText("Pending") },
                { id: 'detecting', name: legacyText("Detecting") },
                { id: 'confirming', name: legacyText("Confirming") },
                { id: 'paid', name: legacyText("Paid") },
                { id: 'overpaid', name: 'Overpaid' },
                { id: 'underpaid', name: 'Underpaid' },
                { id: 'settled', name: 'Settled' },
                { id: 'expired', name: 'Expired' },
                { id: 'cancelled', name: legacyText("Cancelled") }
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
                this.route('merchant.invoices', Object.keys(query).length ? query : { remember: 'forget' }),
                afterRequest
            )
        },

        viewInvoice(id) {
            this.$inertia.visit(this.route('merchant.invoices.show', id))
        },

        createInvoice() {
            this.$inertia.visit(this.route('merchant.invoices.create'))
        },

        copyCheckoutUrl(invoice) {
            const url = `${window.location.origin}/pay/i/${invoice.id}`
            navigator.clipboard.writeText(url).then(() => {
                this.$toast.open(this.$t('Checkout URL copied'))
            })
        },

        getStatusBadge(status) {
            const badges = {
                'awaiting_selection': { label: legacyText("Pending"), color: 'bg-gray-100 text-gray-800' },
                'awaiting_payment': { label: legacyText("Pending"), color: 'bg-yellow-100 text-yellow-800' },
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
