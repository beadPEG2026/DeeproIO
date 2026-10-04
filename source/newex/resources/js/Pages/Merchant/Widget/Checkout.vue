<script>
import Template from '{Template}/Web/Pages/Merchant/Widget/Checkout.template'
import axios from 'axios'
import QrCode from 'vue-qrcode-component'

export default Template({
    components: {
        QrCode
    },

    props: {
        invoice: Object,
        merchant: Object,
        widgetToken: String,
        apiEndpoint: String,
        pollInterval: {
            type: Number,
            default: 3000
        }
    },

    data() {
        return {
            loading: false,
            selecting: false,
            polling: false,
            pollingTimer: null,
            countdownTimer: null,
            status: null,
            selectedCurrency: null,
            availableCurrencies: [],
            paymentDetails: null,
            confirmations: 0,
            requiredConfirmations: 0,
            rateExpiresIn: 0,
            paymentExpiresIn: 0,
            paymentExpiryTime: null,
            rateExpiryTime: null,
            copied: false,
            showQrCode: true,
            error: null
        }
    },

    computed: {
        isAwaitingSelection() {
            return this.status === 'awaiting_selection'
        },
        isAwaitingPayment() {
            return ['awaiting_payment', 'detecting', 'confirming'].includes(this.status)
        },
        isDetecting() {
            return this.status === 'detecting'
        },
        isConfirming() {
            return this.status === 'confirming'
        },
        isPaid() {
            return ['paid', 'overpaid', 'settled'].includes(this.status)
        },
        isExpired() {
            return this.status === 'expired'
        },
        isCancelled() {
            return this.status === 'cancelled'
        },
        isFailed() {
            return this.status === 'failed'
        },
        confirmationProgress() {
            if (this.requiredConfirmations === 0) return 100
            return Math.min(100, Math.round((this.confirmations / this.requiredConfirmations) * 100))
        },
        qrCodeData() {
            if (!this.paymentDetails.address) return null
            return this.paymentDetails.address
        },
        formattedRateExpiry() {
            if (!this.rateExpiryTime) return '--:--'
            if (this.rateExpiresIn <= 0) return '0:00'
            const minutes = Math.floor(this.rateExpiresIn / 60)
            const seconds = this.rateExpiresIn % 60
            return `${minutes}:${seconds.toString().padStart(2, '0')}`
        },
        formattedPaymentExpiry() {
            // If expiry time not set yet, show loading indicator
            if (!this.paymentExpiryTime) return '--:--'
            if (this.paymentExpiresIn <= 0) return '0:00'
            const minutes = Math.floor(this.paymentExpiresIn / 60)
            const seconds = this.paymentExpiresIn % 60
            return `${minutes}:${seconds.toString().padStart(2, '0')}`
        },
        hasPaymentExpiry() {
            return this.paymentExpiryTime !== null
        }
    },

    mounted() {
        this.status = this.invoice.status
        this.loadAvailableCurrencies()

        if (!this.isAwaitingSelection) {
            this.paymentDetails = {
                address: this.invoice.deposit_address,
                memo: this.invoice.deposit_memo,
                amount_crypto: this.invoice.amount_crypto,
                currency: this.invoice.currency.symbol
            }

            // Initialize countdown timers from invoice data if available
            if (this.invoice.payment_expires_at) {
                this.startPaymentTimer(this.invoice.payment_expires_at)
            }
            if (this.invoice.rate_expires_at) {
                this.startRateTimer(this.invoice.rate_expires_at)
            }

            // Always start polling to get fresh data including expiry times
            this.startPolling()
        }
    },

    beforeDestroy() {
        this.stopPolling()
        this.stopTimers()
    },

    methods: {
        async loadAvailableCurrencies() {
            try {
                this.loading = true;
                const response = await axios.get(`${this.apiEndpoint}/currencies`, {
                    headers: { 'Authorization': `Bearer ${this.widgetToken}` }
                })
                if (response.data.success) {
                    this.availableCurrencies = response.data.data
                }
                this.loading = false;

            } catch (error) {
                this.loading = false;
                console.error('Failed to load currencies:', error)
            }
        },

        async selectCurrency(currency) {
            if (this.selecting) return

            this.selecting = true
            this.selectedCurrency = currency
            this.error = null

            try {
                const response = await axios.post(
                    `${this.apiEndpoint}/invoice/select-currency`,
                    {
                        currency: currency.symbol,
                        network_id: currency.network_id
                    },
                    { headers: { 'Authorization': `Bearer ${this.widgetToken}` } }
                )

                if (response.data.success) {
                    const data = response.data.data
                    this.status = data.invoice.status
                    this.paymentDetails = data.payment_details
                    this.requiredConfirmations = currency.required_confirmations

                    // Start countdown timers
                    this.startRateTimer(data.payment_details.rate_expires_at)
                    this.startPaymentTimer(data.payment_details.payment_expires_at)

                    // Start polling for payment status
                    this.startPolling()
                }
            } catch (error) {
                this.error = error.response.data.error.message || 'Failed to select currency'
                this.selectedCurrency = null
            } finally {
                this.selecting = false
            }
        },
        async fetchStatus() {
            if (this.polling) return

            this.polling = true

            try {
                const response = await axios.get(`${this.apiEndpoint}/invoice/status`, {
                    headers: { 'Authorization': `Bearer ${this.widgetToken}` }
                })

                if (response.data.success) {
                    const data = response.data.data
                    this.status = data.status
                    this.confirmations = data.confirmations || 0
                    this.requiredConfirmations = data.required_confirmations || 3

                    // Sync timer from server only if there's significant drift (more than 3 seconds)
                    // This prevents jumping but corrects for clock differences
                    if (data.payment_expires_in_seconds !== undefined && data.payment_expires_in_seconds > 0) {
                        const serverExpiryTime = Date.now() + (data.payment_expires_in_seconds * 1000)
                        if (!this.paymentExpiryTime || Math.abs(this.paymentExpiryTime - serverExpiryTime) > 3000) {
                            this.paymentExpiryTime = serverExpiryTime
                        }
                        this.ensureCountdownTimer()
                    }
                    if (data.rate_expires_in_seconds !== undefined && data.rate_expires_in_seconds > 0) {
                        const serverExpiryTime = Date.now() + (data.rate_expires_in_seconds * 1000)
                        if (!this.rateExpiryTime || Math.abs(this.rateExpiryTime - serverExpiryTime) > 3000) {
                            this.rateExpiryTime = serverExpiryTime
                        }
                        this.ensureCountdownTimer()
                    }

                    // Handle final states
                    if (this.isPaid) {
                        this.stopPolling()
                        this.handlePaymentSuccess()
                    } else if (this.isExpired || this.isCancelled || this.isFailed) {
                        this.stopPolling()
                    }
                }
            } catch (error) {
                console.error('Status poll failed:', error)
            } finally {
                this.polling = false
            }
        },

        startPolling() {
            if (this.pollingTimer) return

            this.fetchStatus()
            this.pollingTimer = setInterval(() => {
                this.fetchStatus()
            }, this.pollInterval)
        },

        stopPolling() {
            if (this.pollingTimer) {
                clearInterval(this.pollingTimer)
                this.pollingTimer = null
            }
        },

        startRateTimer(expiresAt) {
            if (!expiresAt) return

            this.rateExpiryTime = new Date(expiresAt).getTime()
            this.updateCountdowns()
            this.ensureCountdownTimer()
        },

        startPaymentTimer(expiresAt) {
            if (!expiresAt) return

            this.paymentExpiryTime = new Date(expiresAt).getTime()
            this.updateCountdowns()
            this.ensureCountdownTimer()
        },

        ensureCountdownTimer() {
            // Start the countdown timer if not already running
            if (this.countdownTimer) return

            this.countdownTimer = setInterval(() => {
                this.updateCountdowns()
            }, 1000)
        },

        updateCountdowns() {
            const now = Date.now()

            // Update payment countdown
            if (this.paymentExpiryTime) {
                const newValue = Math.max(0, Math.floor((this.paymentExpiryTime - now) / 1000))
                this.paymentExpiresIn = newValue

                // Only set expired if we had a valid expiry time and it has passed
                // Don't change status if already in a final state
                if (newValue <= 0 && !['expired', 'paid', 'settled', 'cancelled', 'failed'].includes(this.status)) {
                    this.status = 'expired'
                }
            }

            // Update rate countdown
            if (this.rateExpiryTime) {
                this.rateExpiresIn = Math.max(0, Math.floor((this.rateExpiryTime - now) / 1000))
            }

            // Stop timer if both countdowns are done or no expiry times set
            const paymentDone = !this.paymentExpiryTime || this.paymentExpiresIn <= 0
            const rateDone = !this.rateExpiryTime || this.rateExpiresIn <= 0
            if (paymentDone && rateDone && this.countdownTimer) {
                this.stopCountdownTimer()
            }
        },

        stopCountdownTimer() {
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer)
                this.countdownTimer = null
            }
        },

        stopTimers() {
            this.stopCountdownTimer()
        },

        async extendRate() {
            try {
                const response = await axios.post(
                    `${this.apiEndpoint}/invoice/extend-rate`,
                    {},
                    { headers: { 'Authorization': `Bearer ${this.widgetToken}` } }
                )

                if (response.data.success) {
                    this.startRateTimer(response.data.data.rate_expires_at)
                    this.$toast.open(this.$t('Rate extended successfully'))
                }
            } catch (error) {
                this.$toast.error(error.response.data.error.message || 'Failed to extend rate')
            }
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.copied = true
                setTimeout(() => { this.copied = false }, 2000)
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {
            })
        },

        buildQrData() {
            if (!this.paymentDetails) return ''

            const { address, amount, currency } = this.paymentDetails
            const network = currency.network_slug.toLowerCase() || ''

            switch (network) {
                case 'bitcoin':
                case 'btc':
                    return `bitcoin:${address}?amount=${amount}`
                case 'ethereum':
                case 'eth':
                case 'erc20':
                    return `ethereum:${address}?value=${amount}`
                case 'litecoin':
                case 'ltc':
                    return `litecoin:${address}?amount=${amount}`
                default:
                    return address
            }
        },

        handlePaymentSuccess() {
            // Redirect after short delay if redirect URL provided
            if (this.invoice.redirect_url) {
                setTimeout(() => {
                    window.location.href = this.invoice.redirect_url
                }, 3000)
            }
        },

        handleCancel() {
            if (this.invoice.cancel_url) {
                window.location.href = this.invoice.cancel_url
            }
        },

        formatCrypto(value) {
            if (!value) return '0'
            return parseFloat(value).toString()
        }
    }
})
</script>
