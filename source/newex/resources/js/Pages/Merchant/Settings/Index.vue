<script>
import Template from '{Template}/Web/Pages/Merchant/Settings/Index.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu
    },

    props: {
        merchant: Object
    },

    data() {
        return {
            form: {
                business_name: this.merchant.business_name,
                business_email: this.merchant.business_email,
                website_url: this.merchant.website_url,
                default_webhook_url: this.merchant.default_webhook_url
            },
            saving: false,
            errors: {},
            showWebhookSecret: false,
            rotatingSecret: false
        }
    },

    methods: {
        async saveSettings() {
            if (this.saving) return

            this.saving = true
            this.errors = {}

            try {
                await axios.put(this.route('merchant.api.settings.update'), this.form)
                this.$toast.open(this.$t('Settings saved'))
            } catch (error) {
                if (error.response.data.error.details.errors) {
                    this.errors = error.response.data.error.details.errors
                } else {
                    this.$toast.error('Failed to save settings')
                }
            } finally {
                this.saving = false
            }
        },

        async rotateWebhookSecret() {
            if (this.rotatingSecret) return

            if (!confirm(this.$t('Are you sure? This will invalidate your current webhook secret.'))) {
                return
            }

            this.rotatingSecret = true

            try {
                const response = await axios.post(this.route('merchant.api.webhook-secret.rotate'))
                if (response.data.success) {
                    this.merchant.webhook_secret = response.data.data.webhook_secret
                    this.showWebhookSecret = true
                    this.$toast.open(this.$t('Webhook secret rotated'))
                }
            } catch (error) {
                console.lg(error);
                this.$toast.error('Failed to rotate webhook secret')
            } finally {
                this.rotatingSecret = false
            }
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'))
            }, function (e) {
                // Copy failed silently
            })
        }
    }
})
</script>
