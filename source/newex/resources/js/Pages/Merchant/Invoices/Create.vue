<script>
import Template from '{Template}/Web/Pages/Merchant/Invoices/Create.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu
    },

    props: {
        merchant: Object,
        limits: Object,
        canOperate: {
            type: Boolean,
            default: true
        },
        operationRestriction: {
            type: Object,
            default: null
        }
    },

    data() {
        return {
            form: {
                amount: '',
                description: '',
                customer_email: '',
                customer_name: '',
                external_id: '',
                redirect_url: '',
                cancel_url: '',
                metadata: {}
            },
            sending: false,
            errors: {},
            createdInvoice: null,
            showSuccessModal: false
        }
    },

    computed: {
        isValidAmount() {
            const amount = parseFloat(this.form.amount)
            return amount >= this.limits.min_amount_usd && amount <= this.limits.max_amount_usd
        },
        canSubmit() {
            return this.canOperate && this.isValidAmount && !this.sending
        }
    },

    methods: {
        async createInvoice() {
            if (!this.canOperate) {
                this.$toast.error(this.$t('Your account is restricted from creating invoices'))
                return
            }
            if (this.sending || !this.isValidAmount) return

            this.sending = true
            this.errors = {}

            try {
                const response = await axios.post(this.route('merchant.api.invoices.create'), this.form)

                if (response.data.success) {
                    this.createdInvoice = response.data.data
                    this.showSuccessModal = true
                }
            } catch (error) {
                if (error.response.data.error.details.errors) {
                    this.errors = error.response.data.error.details.errors
                } else {
                    this.$toast.error(error.response.data.error.message || 'Failed to create invoice')
                }
            } finally {
                this.sending = false
            }
        },

        copyCheckoutUrl() {
            if (!this.createdInvoice) return
            navigator.clipboard.writeText(this.createdInvoice.checkout_url).then(() => {
                this.$toast.open(this.$t('Checkout URL copied'))
            })
        },

        openCheckout() {
            if (this.createdInvoice) {
                window.open(this.createdInvoice.checkout_url, '_blank')
            }
        },

        createAnother() {
            this.form = {
                amount: '',
                description: '',
                customer_email: '',
                customer_name: '',
                external_id: '',
                redirect_url: '',
                cancel_url: '',
                metadata: {}
            }
            this.createdInvoice = null
            this.showSuccessModal = false
        },

        goToInvoices() {
            this.$inertia.visit(this.route('merchant.invoices'))
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'))
            }, function (e) {})
        }
    }
})
</script>
