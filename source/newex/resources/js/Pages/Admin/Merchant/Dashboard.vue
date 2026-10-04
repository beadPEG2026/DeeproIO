<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Dashboard.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: {
        AppLayout
    },
    props: {
        stats: Object
    },
    data() {
        return {
            refreshing: false
        }
    },
    methods: {
        refresh() {
            this.refreshing = true
            this.$inertia.reload({
                onFinish: () => {
                    this.refreshing = false
                }
            })
        }
    }
})
</script>
