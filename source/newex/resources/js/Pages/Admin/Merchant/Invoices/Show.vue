<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Invoices/Show.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: { AppLayout },
    props: {
        invoice: Object
    },
    methods: {
        getStatusBadge(status) {
            const badges = {
                'paid': 'bg-green-100 text-green-800',
                'settled': 'bg-blue-100 text-blue-800',
                'awaiting_payment': 'bg-yellow-100 text-yellow-800',
                'confirming': 'bg-blue-100 text-blue-800',
                'expired': 'bg-gray-100 text-gray-800',
                'cancelled': 'bg-red-100 text-red-800'
            }
            return badges[status] || 'bg-gray-100 text-gray-800'
        }
    }
})
</script>
