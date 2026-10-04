<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Merchants/Index.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: {
        AppLayout
    },
    props: {
        merchants: Object,
        filters: Object,
        stats: Object
    },
    data() {
        return {
            form: {
                status: this.filters.status || '',
                verification_status: this.filters.verification_status || '',
                search: this.filters.search || ''
            }
        }
    },
    methods: {
        applyFilters() {
            this.$inertia.get(this.route('admin.merchant.merchants'), this.form, {
                preserveState: true
            })
        },
        resetFilters() {
            this.form = { status: '', verification_status: '', search: '' }
            this.applyFilters()
        },
        getStatusBadge(status) {
            const badges = {
                'active': 'bg-green-100 text-green-800',
                'pending': 'bg-yellow-100 text-yellow-800',
                'suspended': 'bg-red-100 text-red-800',
                'terminated': 'bg-gray-100 text-gray-800'
            }
            return badges[status] || 'bg-gray-100 text-gray-800'
        },
        getVerificationBadge(status) {
            const badges = {
                'verified': 'bg-green-100 text-green-800',
                'pending': 'bg-yellow-100 text-yellow-800',
                'rejected': 'bg-red-100 text-red-800',
                'unverified': 'bg-gray-100 text-gray-800'
            }
            return badges[status] || 'bg-gray-100 text-gray-800'
        }
    }
})
</script>
