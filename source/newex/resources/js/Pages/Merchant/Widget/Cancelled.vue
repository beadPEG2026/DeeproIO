<script>
import Template from '{Template}/Web/Pages/Merchant/Widget/Cancelled.template'

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
        }
    }
})
</script>
