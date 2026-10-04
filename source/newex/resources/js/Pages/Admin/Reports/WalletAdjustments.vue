<script>
import Template from '{Template}/Admin/Pages/Admin/Reports/WalletAdjustments.template'
import AppLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'
import AdminReportsTab from '@/Components/Reports/AdminReportsTab'

export default Template({
    components: { AppLayout, Pagination, AdminReportsTab },
    props: {
        records: Object,
        filters: Object,
    },
})
</script>
