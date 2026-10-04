<script>
import Template from '{Template}/Web/Pages/Merchant/Widget/Success.template'

export default Template({
    props: {
        invoice: Object,
        merchant: Object
    },

    data() {
        return {
            redirecting: false,
            countdown: 5
        }
    },

    computed: {
        hasRedirectUrl() {
            return !!this.invoice.redirect_url
        }
    },

    mounted() {
        if (this.hasRedirectUrl) {
            this.startCountdown()
        }
    },

    methods: {
        startCountdown() {
            const timer = setInterval(() => {
                this.countdown--
                if (this.countdown <= 0) {
                    clearInterval(timer)
                    this.redirect()
                }
            }, 1000)
        },

        redirect() {
            this.redirecting = true
            window.location.href = this.invoice.redirect_url
        }
    }
})
</script>
