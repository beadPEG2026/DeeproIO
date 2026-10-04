<script>
import Template from '{Template}/Admin/Pages/Admin/Merchant/Webhooks/DeadLetters.template'
import AppLayout from '@/Layouts/AdminLayout'

export default Template({
    components: { AppLayout },
    props: {
        deadLetters: Object,
        filters: Object
    },
    data() {
        return {
            showResolveModal: false,
            selectedDeadLetter: null,
            resolveForm: { resolution: 'discard', notes: '' },
            processing: false
        }
    },
    methods: {
        openResolve(deadLetter) {
            this.selectedDeadLetter = deadLetter
            this.showResolveModal = true
        },
        resolveDeadLetter() {
            this.processing = true
            this.$inertia.post(this.route('admin.merchant.webhooks.resolve', this.selectedDeadLetter.id), this.resolveForm, {
                onFinish: () => {
                    this.processing = false
                    this.showResolveModal = false
                }
            })
        }
    }
})
</script>
