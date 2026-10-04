<script>
import Template from '{Template}/Web/Pages/Merchant/Rejected.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
    components: {
        AppLayout
    },

    props: {
        merchant: {
            type: Object,
            required: true
        },
        rejectionReason: {
            type: String,
            default: null
        },
        rejectedAt: {
            type: String,
            default: null
        },
        submissionCount: {
            type: Number,
            default: 1
        }
    },

    computed: {
        formattedRejectedAt() {
            if (!this.rejectedAt) return null
            return new Date(this.rejectedAt).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            })
        }
    },

    methods: {
        goToResubmit() {
            this.$inertia.visit(this.route('merchant.apply'))
        }
    }
})
</script>
