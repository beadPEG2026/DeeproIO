<script>
    import JetActionSection from '@/Jetstream/ActionSection'
    import JetButton from '@/Jetstream/Button'
    import JetConfirmsPassword from '@/Jetstream/ConfirmsPassword'
    import JetDangerButton from '@/Jetstream/DangerButton'
    import JetSecondaryButton from '@/Jetstream/SecondaryButton'
    import Template from '{Template}/Web/Pages/Profile/TwoFactorAuthenticationForm.template'

    export default Template({
        components: {
            JetActionSection,
            JetButton,
            JetConfirmsPassword,
            JetDangerButton,
            JetSecondaryButton,
        },

        data() {
            return {
                enabling: false,
                confirming: false,
                disabling: false,
                qrText: null,
                qrCode: null,
                recoveryCodes: [],
                confirmationCode: '',
                confirmationErrors: {},
                confirmationProcessing: false,
                qrCodeLoading: false,
                qrCodeError: null,
                localTwoFactorEnabled: false,
            }
        },

        methods: {
            enableTwoFactorAuthentication() {
                this.enabling = true

                axios.post('/user/pending-two-factor-authentication')
                    .then(response => {
                        if (!response.data.pending) {
                            throw new Error('Two factor authentication setup has not been started.')
                        }

                        this.localTwoFactorEnabled = false
                        this.confirming = true

                        return Promise.all([
                            this.showQrCode(),
                            this.$toast.success(this.$t('Scan the QR code and enter the verification code to finish enabling two factor authentication.')),
                        ])
                    })
                    .catch(error => {
                        const message = error.response?.data?.message || error.message || 'Unable to start two factor authentication setup.'
                        this.$toast.error(this.$t(message))
                    })
                    .finally(() => {
                        this.enabling = false
                    })
            },

            showQrCode() {
                this.qrCodeLoading = true
                this.qrCodeError = null

                return axios.get('/user/pending-two-factor-qr-code')
                        .then(response => {
                            if (!response.data.svg || !response.data.url) {
                                throw new Error('The QR code response was empty.')
                            }

                            this.qrCode = response.data.svg;

                            let text = response.data.url.split('&issuer=')[0];
                            let code = text.split('secret=')[1];
                        
                            this.qrText = code;
                        })
                        .catch(error => {
                            const message = error.response?.data?.message || error.message || 'Unable to load QR code.'
                            this.qrCodeError = message
                            this.$toast.error(this.$t(message))
                        })
                        .finally(() => {
                            this.qrCodeLoading = false
                        })
            },

            showRecoveryCodes() {
                return axios.get('/user/two-factor-recovery-codes')
                        .then(response => {
                            this.recoveryCodes = response.data
                        })
            },

            regenerateRecoveryCodes() {
                axios.post('/user/two-factor-recovery-codes')
                        .then(response => {
                            this.showRecoveryCodes()
                        })
            },

            normalizeConfirmationCode() {
                this.confirmationCode = (this.confirmationCode || '').replace(/\D/g, '').slice(0, 6)
                this.confirmationErrors = {}
            },

            confirmTwoFactorAuthentication() {
                if (!this.canConfirmTwoFactorAuthentication) {
                    this.confirmationErrors = {
                        code: this.$t('Please enter the 6-digit verification code.'),
                    }

                    return
                }

                this.confirmationProcessing = true
                this.confirmationErrors = {}

                axios.post('/user/pending-two-factor-authentication/confirm', {
                    code: this.confirmationCode,
                })
                    .then(() => {
                        this.localTwoFactorEnabled = true
                        this.confirming = false
                        this.confirmationCode = ''
                        this.qrCode = null
                        this.qrText = null
                        this.$toast.success(this.$t('You have enabled two factor authentication.'))
                        this.showRecoveryCodes()
                    })
                    .catch(error => {
                        const errors = error.response?.data?.errors || {}
                        this.confirmationErrors = errors

                        const message = errors.code?.[0] || error.response?.data?.message || 'The provided two factor authentication code was invalid.'
                        this.$toast.error(this.$t(message))
                    })
                    .finally(() => {
                        this.confirmationProcessing = false
                    })
            },

            disableTwoFactorAuthentication() {
                this.disabling = true

                axios.delete('/user/pending-two-factor-authentication')
                    .then(() => {
                        this.$toast.error(this.$t(this.confirming ? 'Two factor authentication setup was cancelled.' : 'You have disabled two factor authentication.'))
                        this.disabling = false
                        this.confirming = false
                        this.localTwoFactorEnabled = false
                        this.confirmationCode = ''
                        this.confirmationErrors = {}
                        this.qrCode = null
                        this.qrText = null
                        this.recoveryCodes = []
                    })
                    .finally(() => (this.disabling = false))
            },
        },

        computed: {
            twoFactorEnabled() {
                return ! this.enabling && (this.localTwoFactorEnabled || this.$page.props.user.two_factor_enabled)
            },

            showingTwoFactorSetup() {
                return this.twoFactorEnabled || this.confirming
            },

            canConfirmTwoFactorAuthentication() {
                return this.confirmationCode.length === 6 && !this.confirmationProcessing
            },

            confirmationErrorMessage() {
                const error = this.confirmationErrors.code

                if (Array.isArray(error)) {
                    return error[0]
                }

                return error || ''
            }
        }
    });
</script>
