<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Earnings/Index.template'
import AdminLayout from '@/Layouts/AdminLayout'

export default Template({
    components: {
        AdminLayout
    },

    props: {
        stats: Object,
        monthlyData: Array,
        topMerchants: Array
    },

    methods: {
        formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(amount || 0)
        }
    }
})
</script>
