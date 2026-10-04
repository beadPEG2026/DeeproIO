<script>
import Template from '{Template}/Web/Pages/Merchant/Pending.template'
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
        submittedAt: {
            type: String,
            default: null
        },
        submissionCount: {
            type: Number,
            default: 1
        }
    },

    computed: {
        formattedSubmittedAt() {
            if (!this.submittedAt) return null
            return new Date(this.submittedAt).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            })
        },
        isResubmission() {
            return this.submissionCount > 1
        }
    }
})
</script>
