<script>
import Template from '{Template}/Web/Pages/Merchant/Widget/Expired.template'

export default Template({
    props: {
        invoice: Object,
        merchant: Object
    },

    methods: {
        goBack() {
            if (this.invoice.cancel_url) {
                window.location.href = this.invoice.cancel_url
            } else {
                window.history.back()
            }
        },

        contactSupport() {
            if (this.merchant.support_email) {
                window.location.href = `mailto:${this.merchant.support_email}?subject=Invoice ${this.invoice.id} Expired`
            }
        }
    }
})
</script>
