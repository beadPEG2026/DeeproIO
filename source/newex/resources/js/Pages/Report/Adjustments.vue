<script>
import Template from '{Template}/Web/Pages/Report/Adjustments.template'
import AppLayout from '@/Layouts/AppLayout'
import Pagination from '@/Jetstream/Pagination'
import ReportsTab from '@/Components/Reports/ReportsTab'

export default Template({
    components: { AppLayout, Pagination, ReportsTab },
    props: {
        records: Object,
        filters: Object,
    },
})
</script>
