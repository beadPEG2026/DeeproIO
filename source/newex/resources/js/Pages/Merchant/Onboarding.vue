<script>
import Template from '{Template}/Web/Pages/Merchant/Onboarding.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
    components: {
        AppLayout
    },

    props: {
        merchant: {
            type: Object,
            default: null
        },
        isResubmission: {
            type: Boolean,
            default: false
        },
        rejectionReason: {
            type: String,
            default: null
        },
        submissionCount: {
            type: Number,
            default: 0
        }
    },

    data() {
        return {
            form: {
                business_name: this.merchant ? this.merchant.business_name : '',
                business_email: this.merchant ? this.merchant.business_email : '',
                website_url: this.merchant ? this.merchant.business_website : '',
                description: this.merchant ? this.merchant.business_description : '',
                country_code: this.merchant ? this.merchant.country_code : '',
                agree_terms: false
            },
            sending: false,
            errors: {}
        }
    },

    computed: {
        submitButtonText() {
            if (this.sending) return ''
            return this.isResubmission ? this.$t('Resubmit Application') : this.$t('Submit Application')
        },
        pageTitle() {
            return this.isResubmission ? this.$t('Resubmit Your Application') : this.$t('Become a Merchant')
        }
    },

    methods: {
        async submitApplication() {
            if (this.sending) return

            this.sending = true
            this.errors = {}

            try {
                await axios.post(this.route('merchant.apply'), this.form)
                this.$toast.open(this.isResubmission
                    ? this.$t('Application resubmitted successfully')
                    : this.$t('Application submitted successfully'))
                this.$inertia.visit(this.route('merchant.dashboard'))
            } catch (error) {

                if (error.response.data.errors) {
                    this.errors = error.response.data.errors
                } else {
                    this.$toast.error(this.$t('Failed to submit application'))
                }
            } finally {
                this.sending = false
            }
        }
    }
})
</script>
