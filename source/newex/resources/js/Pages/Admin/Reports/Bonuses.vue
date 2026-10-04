<script>
import Template from '{Template}/Admin/Pages/Admin/Reports/Bonuses.template'
import AppLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'
import AdminReportsTab from '@/Components/Reports/AdminReportsTab'

export default Template({
    components: { AppLayout, Pagination, AdminReportsTab },
    props: {
        bonuses: Object,
        filters: Object,
    },
})
</script>
