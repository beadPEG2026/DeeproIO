<script>
import Template from '{Template}/Admin/Pages/Admin/Reports/ComplianceAudit.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButton from "@/Jetstream/NavButton";
import SearchFilter from '@/Jetstream/SearchFilter'
import AdminReportsTab from '@/Components/Reports/AdminReportsTab'

export default Template({
    components: { AppLayout, NavButton, SearchFilter, AdminReportsTab },
    props: {
        filters: Object,
    },
    data() {
        return {
            form: {
                period: this.filters?.period || [],
            }
        }
    },
    methods: {
        exportUsers() {
            const params = new URLSearchParams();
            if (this.form.period && this.form.period.length === 2) {
                params.append('period[0]', this.form.period[0]);
                params.append('period[1]', this.form.period[1]);
            }
            window.location.href = this.route('admin.reports.compliance.users.export') + (params.toString() ? ('?' + params.toString()) : '');
        }
    }
})
</script>
