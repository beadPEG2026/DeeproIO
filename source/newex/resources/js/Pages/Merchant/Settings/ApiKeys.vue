<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Merchant/Settings/ApiKeys.template'
import AppLayout from '@/Layouts/AppLayout'
import MerchantTopMenu from '@/Components/Merchant/TopMenu'

export default Template({
    components: {
        AppLayout,
        MerchantTopMenu
    },

    props: {
        merchant: Object,
        apiKeys: Array,
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
            showCreateModal: false,
            showSecretModal: false,
            creating: false,
            revoking: null,
            newKeyForm: {
                name: '',
                environment: 'live'
            },
            newKeyData: null,
            errors: {}
        }
    },

    methods: {
        openCreateModal() {
            if (!this.canOperate) {
                this.$toast.error(this.$t('Your account is restricted from creating API keys'))
                return
            }
            this.showCreateModal = true
        },

        async createApiKey() {
            if (!this.canOperate) {
                this.$toast.error(this.$t('Your account is restricted from creating API keys'))
                return
            }
            if (this.creating) return

            this.creating = true
            this.errors = {}

            try {
                const response = await axios.post(this.route('merchant.api.api-keys.create'), this.newKeyForm)

                if (response.data.success) {
                    this.newKeyData = response.data.data
                    this.showCreateModal = false
                    this.showSecretModal = true
                    this.newKeyForm = { name: '', environment: 'live' }
                    this.$inertia.reload()
                }
            } catch (error) {
                if (error.response.data.error.details.errors) {
                    this.errors = error.response.data.error.details.errors
                } else {
                    this.$toast.error(error.response.data.error.message || 'Failed to create API key')
                }
            } finally {
                this.creating = false
            }
        },

        async revokeApiKey(apiKey) {
            if (this.revoking === apiKey.id) return

            if (!confirm(this.$t('Are you sure you want to revoke this API key? This cannot be undone.'))) {
                return
            }

            this.revoking = apiKey.id

            try {
                await axios.delete(this.route('merchant.api.api-keys.revoke', apiKey.id))
                this.$toast.open(this.$t('API key revoked'))
                this.$inertia.reload()
            } catch (error) {
                this.$toast.error(error.response.data.error.message || 'Failed to revoke API key')
            } finally {
                this.revoking = null
            }
        },

        doCopy(string, label = null) {
            this.$copyText(string).then(() => {
                const message = label ? this.$t(`${label} copied`) : this.$t('Text was copied to the clipboard');
                this.$toast.open(message);
            }, function (e) {
            })
        },

        closeSecretModal() {
            this.showSecretModal = false
            this.newKeyData = null
        },

        formatDate(date) {
            if (!date) return '-'
            return new Date(date).toLocaleString()
        },

        getEnvironmentBadge(env) {
            return env === 'live'
                ? { label: legacyText("Live"), color: 'bg-green-100 text-green-800' }
                : { label: legacyText("Test"), color: 'bg-yellow-100 text-yellow-800' }
        }
    }
})
</script>
