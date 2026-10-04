<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/OrphanPayments/Index.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: { AppLayout },
    props: {
        orphanPayments: Object,
        filters: Object,
        stats: Object
    },
    data() {
        return {
            showResolveModal: false,
            selectedPayment: null,
            resolveForm: { resolution: 'keep', notes: '' },
            processing: false
        }
    },
    methods: {
        openResolve(payment) {
            this.selectedPayment = payment
            this.showResolveModal = true
        },
        resolvePayment() {
            this.processing = true
            this.$inertia.post(this.route('admin.merchant.orphan-payments.resolve', this.selectedPayment.id), this.resolveForm, {
                onFinish: () => {
                    this.processing = false
                    this.showResolveModal = false
                }
            })
        }
    }
})
</script>
