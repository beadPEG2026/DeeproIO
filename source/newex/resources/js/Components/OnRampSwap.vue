<template>
<div class="" :class="{'on-ramp-swap-hidden': !show}">
    <div id="swap-container" ref="swapPopup" class="table-components on-ramp-swap">
        <div class="flex components-filter__item swap-inner rounded-lg p-8 flex flex-col mt-10 md:mt-0 relative">
            <div class="w-full flex justify-between items-center mb-2 on-ramp-swap-header">
                <div class="">
                    <h4>{{ $t('Sell Crypto') }}</h4>
                </div>

                <div class="" @click="show = false">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M5.29289 5.29289C5.68342 4.90237 6.31658 4.90237 6.70711 5.29289L12 10.5858L17.2929 5.29289C17.6834 4.90237 18.3166 4.90237 18.7071 5.29289C19.0976 5.68342 19.0976 6.31658 18.7071 6.70711L13.4142 12L18.7071 17.2929C19.0976 17.6834 19.0976 18.3166 18.7071 18.7071C18.3166 19.0976 17.6834 19.0976 17.2929 18.7071L12 13.4142L6.70711 18.7071C6.31658 19.0976 5.68342 19.0976 5.29289 18.7071C4.90237 18.3166 4.90237 17.6834 5.29289 17.2929L10.5858 12L5.29289 6.70711C4.90237 4.90237 4.90237 5.68342 5.29289 5.29289Z" />
                    </svg>
                </div>
            </div>

            <template v-if="activeTab === 'sell'">
                <div class="pb-6 w-full">
                    <div class="">
                        <label>{{ $t('Sell:') }}</label>

                        <div class="swap-container-elements rounded filter-select w-full flex text-left mt-2 justify-between items-center px-3 py-2 transition duration-100 ease-in-out focus:border-gray-200 focus:ring-2 focus:ring-gray-200 focus:outline-none focus:ring-opacity-10 disabled:opacity-50 disabled:cursor-not-allowed bg-white border-gray-200">
                            <t-rich-user-select
                                v-model="sellBasePair"
                                :options="sellBaseCurrencies"
                                :placeholder="$t('Select coin')"
                                value-attribute="id"
                                text-attribute="name"
                                v-on:change="setupSellData"
                                :clearable="false"
                                :hideSearchBox="true"
                            >
                                <template slot="label" slot-scope="{ className, option, query }">
                                    <div class="flex">
                                        <div class="flex flex-col">
                                            <div class="swap-label flex items-stretch">
                                                <img :src="option.text.logo" width="28" />
                                                <span class="pt-1 ml-5">{{ option.text.name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <template slot="option" slot-scope="{ className, option, query }">
                                    <div class="flex">
                                        <div class="flex flex-col">
                                            <div class="flex items-stretch pt-2 pb-2 pl-2">
                                                <img :src="option.text.logo" width="28" />
                                                <span class="pt-1 ml-5">{{ option.text.name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </t-rich-user-select>

                            <div class="relative-right">
                                <input
                                    placeholder="0.00"
                                    type="text"
                                    v-model="sellForm.crypto_amount"
                                    @keyup="(e) => clearSellInput(e)"
                                    @input="handleSellInput"
                                    @paste.prevent
                                    @drop.prevent
                                    class="filter-select w-full flex text-left mt-1 justify-between items-center px-3 py-2 transition duration-100 ease-in-out focus:border-gray-200 focus:ring-2 focus:ring-gray-200 focus:outline-none focus:ring-opacity-10 disabled:opacity-50 disabled:cursor-not-allowed bg-white border-gray-200"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pb-6 w-full">
                    <label class="swap-block float-left">{{ $t('Receive:') }}</label>

                    <div class="swap-container-elements rounded filter-select w-full flex text-left mt-2 justify-between items-center px-3 py-2 transition duration-100 ease-in-out focus:border-gray-200 focus:ring-2 focus:ring-gray-200 focus:outline-none focus:ring-opacity-10 disabled:opacity-50 disabled:cursor-not-allowed bg-white border-gray-200">
                        <t-rich-user-select
                            v-model="sellQuotePair"
                            :options="sellQuoteCurrencies"
                            v-on:change="setupSellData"
                            :placeholder="$t('Select fiat')"
                            value-attribute="id"
                            text-attribute="name"
                            :clearable="false"
                            :hideSearchBox="true"
                        >
                            <template slot="label" slot-scope="{ className, option, query }">
                                <div class="flex">
                                    <div class="flex flex-col">
                                        <div class="swap-label flex items-stretch">
                                            <img :src="option.text.logo" width="28" />
                                            <span class="pt-1 ml-5">{{ option.text.name }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template slot="option" slot-scope="{ className, option, query }">
                                <div class="flex">
                                    <div class="flex flex-col">
                                        <div class="flex items-stretch pt-2 pb-2 pl-2">
                                            <img :src="option.text.logo" width="28" />
                                            <span class="pt-1 ml-5">{{ option.text.name }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </t-rich-user-select>

                        <div class="relative-right">
                            <input
                                disabled
                                placeholder="0.00"
                                type="text"
                                v-model="sellForm.fiat_amount"
                                @paste.prevent
                                @drop.prevent
                                class="filter-select w-full flex text-left mt-1 justify-between items-center px-3 py-2 transition duration-100 ease-in-out focus:border-gray-200 focus:ring-2 focus:ring-gray-200 focus:outline-none focus:ring-opacity-10 disabled:opacity-50 disabled:cursor-not-allowed bg-white border-gray-200"
                            />
                        </div>
                    </div>
                </div>

                <div class="pb-6 w-full">
                    <label class="swap-block float-left">{{ $t('Payout Method:') }}</label>

                    <div class="swap-container-elements no-bordered-select rounded filter-select w-full flex text-left mt-2 justify-between items-center px-3 py-2 transition duration-100 ease-in-out focus:border-gray-200 focus:ring-2 focus:ring-gray-200 focus:outline-none focus:ring-opacity-10 disabled:opacity-50 disabled:cursor-not-allowed bg-white border-gray-200">
                        <themed-select
                            v-model="sellForm.payout_method"
                            class="w-full bg-transparent border-0 focus:outline-none"
                            @change="onSellPayoutMethodChange"
                        >
                            <option value="" disabled>
                                {{ $t('Please select bank card') }}
                            </option>

                            <option
                                v-for="method in payoutMethods"
                                :key="method.bank_account_id || method.id"
                                :value="methodOptionValue(method)"
                            >
                                {{ method.name }}
                            </option>
                        </themed-select>
                    </div>

                    <div v-if="payoutMethods.length === 0" class="mt-2 text-sm text-red-500">
                        {{ $t('No approved bank card available.') }}
                    </div>
                </div>
            </template>

            <div class="pb-8 w-full">
                <div class="flex items-center">
                    <strong>{{ $t('Payout Methods:') }}</strong>

                    <div class="ml-2 payment-method-card payment-method-card--visa">
                        <span>VISA</span>
                    </div>

                    <div class="ml-1 payment-method-card payment-method-card--mastercard">
                        <span class="mastercard-circle mastercard-circle--red"></span>
                        <span class="mastercard-circle mastercard-circle--orange"></span>
                    </div>
                </div>

                <div class="mb-4 text-red-600">
                    {{ error }}
                </div>

                <template v-if="activeTab === 'sell'">
                    <div class="">
                        {{ $t('Exchange Rate:') }}

                        <span v-if="sellQuote && !sellProcessing && !error">
                            1
                            <span v-if="sellBaseCurrencies[sellBasePair]">{{ sellBaseCurrencies[sellBasePair].name }}</span>
                            =
                            {{ sellQuote.exchangeRate }}
                            <span v-if="sellQuoteCurrencies[sellQuotePair]">{{ sellQuoteCurrencies[sellQuotePair].name }}</span>
                        </span>

                        <span v-else-if="!sellProcessing">{{ $t('N/A') }}</span>

                        <svg v-show="sellProcessing" class="animate-spin ml-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>

                    <div v-if="sellQuote && !sellProcessing && !error">
                        {{ $t('Network Fee:') }}
                        <span>{{ sellQuote.networkFee || '0.00' }} {{ sellBaseCurrencies[sellBasePair] ? sellBaseCurrencies[sellBasePair].name : '' }}</span>
                    </div>

                    <div v-if="sellQuote && !sellProcessing && !error">
                        {{ $t('Processing Fee:') }}
                        <span>{{ sellQuote.processingFee || '0.00' }} {{ sellQuoteCurrencies[sellQuotePair] ? sellQuoteCurrencies[sellQuotePair].name : '' }}</span>
                    </div>
                </template>
            </div>

            <div class="pb-2 w-full">
                <div class="form-components__block">
                    <div class="form-components__block-item block-button">
                        <button
                            v-if="activeTab === 'sell'"
                            @click="submitSell"
                            :class="{'disabled': !canSubmitSell}"
                            :disabled="!canSubmitSell"
                            class="form-components__block-button"
                        >
                            {{ $t($page.props.user ? 'Sell' : 'Login to continue') }}
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
</template>

<script>
export default {
    name: "OnRampSwap",

    mounted() {
        this.getSellCurrencies();
        this.getSellFiatCurrencies();
        this.getPayoutMethods();

        this.$worker.$on('openBuyCryptoModal', () => {
            this.openSellModal();
        });

        this.$worker.$on('openSellCryptoModal', () => {
            this.openSellModal();
        });

        document.addEventListener('click', this.handleClickOutside);
    },

    beforeDestroy() {
        document.removeEventListener('click', this.handleClickOutside);
    },

    data() {
        return {
            show: false,
            justOpened: false,
            activeTab: 'sell',
            error: null,

            sellBaseCurrencies: {},
            sellQuoteCurrencies: {},
            sellBasePair: null,
            sellQuotePair: null,

            sellForm: {
                crypto_amount: '0.00',
                fiat_amount: '0.00',
                payout_method: '',
            },

            sellQuote: null,
            sellQuoted: false,
            sellProcessing: false,
            sellProcessingCheckout: false,
            sellQuoteDebounce: null,

            payoutMethods: [],
        }
    },

    computed: {
        sellAmount() {
            const amount = parseFloat(this.sellForm.crypto_amount);
            return Number.isFinite(amount) ? amount : 0;
        },

        selectedSellPayoutMethod() {
            return this.getSelectedPaymentMethod(this.sellForm.payout_method);
        },

        hasSelectedSellBankCard() {
            return !!this.selectedSellPayoutMethod &&
                !!this.selectedSellPayoutMethod.bank_account_id;
        },

        canSubmitSell() {
            return !!this.$page.props.user &&
                !!this.sellBasePair &&
                !!this.sellQuotePair &&
                !!this.hasSelectedSellBankCard &&
                this.sellAmount > 0 &&
                !!this.sellQuoted &&
                !!this.sellQuote &&
                !this.sellProcessingCheckout &&
                !this.sellProcessing;
        }
    },

    watch: {
        sellBasePair() {
            this.triggerSellQuote();
        },

        sellQuotePair() {
            this.triggerSellQuote();
        },

        'sellForm.crypto_amount'() {
            this.triggerSellQuote();
        },

        'sellForm.payout_method'() {
            this.triggerSellQuote();
        },
    },

    methods: {
        openSellModal() {
            this.justOpened = true;
            this.show = true;
            this.activeTab = 'sell';
            this.error = null;

            this.$nextTick(() => {
                this.triggerSellQuote();
            });
        },

        handleClickOutside(event) {
            if (!this.show) return;

            if (this.justOpened) {
                this.justOpened = false;
                return;
            }

            const popup = this.$refs.swapPopup;

            if (popup && !popup.contains(event.target)) {
                this.show = false;
            }
        },

        methodOptionValue(method) {
            if (!method) {
                return '';
            }

            if (method.bank_account_id !== undefined && method.bank_account_id !== null && method.bank_account_id !== '') {
                return `${method.id}__${method.bank_account_id}`;
            }

            return method.id;
        },

        getSelectedPaymentMethod(value) {
            if (!value) {
                return null;
            }

            return this.payoutMethods.find((item) => {
                return this.methodOptionValue(item) === value;
            }) || null;
        },

        getPaymentPayload(value) {
            const method = this.getSelectedPaymentMethod(value);

            if (!method) {
                return {
                    payment: null,
                    payment_method: null,
                    payout_method: null,
                    bank_account_id: null,
                    bank_account_name: null,
                };
            }

            return {
                payment: method.id,
                payment_method: method.id,
                payout_method: method.id,
                bank_account_id: method.bank_account_id || null,
                bank_account_name: method.name || null,
            };
        },

        onSellPayoutMethodChange() {
            this.error = null;
            this.triggerSellQuote();
        },

        getSellCurrencies() {
            axios.get(this.route('unlimit.offramp.currencies'), {timeout:15000}).then((response) => {
                this.sellBaseCurrencies = response.data || {};

                if (Object.keys(this.sellBaseCurrencies).length > 0) {
                    this.sellBasePair = Object.keys(this.sellBaseCurrencies)[0];
                }
            }).catch(error => {
                this.error=this.$t('Payment service unavailable. Please reload and try again.');
            });
        },

        getSellFiatCurrencies() {
            axios.get(this.route('unlimit.offramp.fiatCurrencies'), {timeout:15000}).then((response) => {
                this.sellQuoteCurrencies = response.data || {};

                if (Object.keys(this.sellQuoteCurrencies).length > 0) {
                    this.sellQuotePair = Object.keys(this.sellQuoteCurrencies)[0];
                }
            }).catch(error => {
                this.error=this.$t('Payment service unavailable. Please reload and try again.');
            });
        },

        getPayoutMethods() {
            axios.get(this.route('unlimit.offramp.payoutMethods'), {timeout:15000}).then((response) => {
                let remoteMethods = [];

                if (response.data && response.data.length > 0) {
                    remoteMethods = response.data;
                }

                this.payoutMethods = remoteMethods.filter((method) => {
                    return method && method.id && method.bank_account_id;
                });

                this.sellForm.payout_method = '';

                this.$nextTick(() => {
                    this.triggerSellQuote();
                });
            }).catch(error => {
                this.error=this.$t('Payment service unavailable. Please reload and try again.');

                this.payoutMethods = [];
                this.sellForm.payout_method = '';

                this.$nextTick(() => {
                    this.triggerSellQuote();
                });
            });
        },

        setupSellData() {
            this.triggerSellQuote();
        },

        triggerSellQuote() {
            if (this.sellQuoteDebounce) {
                clearTimeout(this.sellQuoteDebounce);
            }

            this.sellQuoteDebounce = setTimeout(() => {
                this.fetchSellQuote();
            }, 400);
        },

        fetchSellQuote() {
            this.error = null;
            this.sellQuote = null;
            this.sellQuoted = false;

            if (!this.sellBasePair || !this.sellQuotePair || !this.hasSelectedSellBankCard || !this.sellAmount || this.sellAmount <= 0) {
                this.sellForm.fiat_amount = '0.00';
                return;
            }

            if (this.$page.props.mode == "readonly") {
                return;
            }

            this.sellProcessing = true;

            const paymentPayload = this.getPaymentPayload(this.sellForm.payout_method);

            axios.get(this.route('unlimit.offramp.quote'), {
                params: {
                    crypto_currency_id: this.sellBasePair,
                    fiat_currency_id: this.sellQuotePair,
                    amount: this.sellAmount,
                    payout_method: paymentPayload.payout_method,
                    bank_account_id: paymentPayload.bank_account_id,
                    bank_account_name: paymentPayload.bank_account_name,
                }
            }).then((resp) => {
                if (resp.data && resp.data.success) {
                    this.sellQuoted = true;
                    const res = resp.data.data;
                    this.sellQuote = res;
                    this.sellForm.fiat_amount = res.amountOut || res.fiatAmount || '0.00';
                    return;
                }

                if (resp.data && resp.data.error) {
                    this.error = resp.data.error;
                    this.sellQuoted = false;
                    return;
                }

                this.error = this.$t('Failed to fetch sell quote.');
                this.sellQuoted = false;
            }).catch(() => {
                this.error = this.$t('Failed to fetch sell quote.');
                this.sellQuoted = false;
            }).finally(() => {
                this.sellProcessing = false;
            });
        },

        handleSellInput() {
            let value = this.sellForm.crypto_amount;

            if (value === null || value === undefined) return;

            value = value.toString();
            value = value.replace(/[^0-9.]/g, '');

            const parts = value.split('.');

            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }

            if (value.includes('.')) {
                const [int, dec] = value.split('.');
                value = int + '.' + dec.slice(0, 8);
            }

            this.sellForm.crypto_amount = value;
        },

        clearSellInput($event) {
            let formField = this.sellForm.crypto_amount.toString();

            if (formField.charAt(0) == '.') {
                this.sellForm.crypto_amount = 0;
                return;
            }

            if (/^0+\.\d+/.test(formField)) {
                this.sellForm.crypto_amount = formField.replace(/^0+/, '0');
            }

            if (/^0+\d+/.test(formField)) {
                this.sellForm.crypto_amount = formField.replace(/^0+/, '');
            }
        },

        submitSell() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we disabled selling crypto functionality.');
            }

            this.error = null;

            if (!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            if (!this.$page.props.user.kyc_verified) {
                this.error = this.$t('Please complete KYC verification to proceed with the sale.');
                return;
            }

            if (!this.sellBasePair || !this.sellQuotePair) {
                this.error = this.$t('Please select both Sell and Receive currencies.');
                return;
            }

            if (!this.hasSelectedSellBankCard) {
                this.error = this.$t('Please select a bank card.');
                return;
            }

            if (!this.sellAmount || this.sellAmount <= 0) {
                this.error = this.$t('Please enter a valid amount.');
                return;
            }

            if (!this.sellQuoted || !this.sellQuote) {
                this.error = this.$t('Please wait for the quote to complete.');
                this.triggerSellQuote();
                return;
            }

            if (this.sellProcessingCheckout) {
                return;
            }

            this.sellProcessingCheckout = true;

            const paymentPayload = this.getPaymentPayload(this.sellForm.payout_method);

            axios.post(this.route('unlimit.offramp.checkout'), {
                crypto_currency_id: this.sellBasePair,
                fiat_currency_id: this.sellQuotePair,
                amount: this.sellAmount,
                payout_method: paymentPayload.payout_method,
                bank_account_id: paymentPayload.bank_account_id,
                bank_account_name: paymentPayload.bank_account_name,
            }).then((response) => {
                if (response.data && response.data.success && response.data.redirect_url) {
                    window.location.href = response.data.redirect_url;
                    return;
                }

                if (response.data && response.data.success) {
                    this.$toast.success(this.$t('Sell order initiated successfully.'));
                    this.show = false;
                    return;
                }

                if (response.data && response.data.error) {
                    this.error = response.data.error;
                    return;
                }

                this.error = this.$t('Unexpected response from payment gateway.');
            }).catch((error) => {
                if (error.response && error.response.data && error.response.data.error) {
                    this.error = error.response.data.error;
                } else {
                    this.error = this.$t('Failed to initialize sale.');
                }
            }).finally(() => {
                this.sellProcessingCheckout = false;
            });
        },
    }
}
</script>

<style scoped>
.on-ramp-tabs {
    margin-bottom: 20px;
}

.on-ramp-tab {
    margin: 0;
    width: 100%;
    background-color: var(--rows-color, #f3f4f6);
    color: var(--second-text-color, #6b7280);
    font-weight: 500;
    transition: all 0.15s ease;
}

.on-ramp-tab:hover {
    background-color: var(--border-line-color, #e5e7eb);
}

.on-ramp-tab--active {
    color: #fff;
}

.on-ramp-tab--active.on-ramp-tab-btn {
    background-color: var(--green-color, #3b82f6);
}

.on-ramp-tab--active.off-ramp-tab-btn {
    background-color: var(--red-color, #3b82f6);
}

.on-ramp-tab--active.on-ramp-tab-btn:hover {
    background-color: var(--green-color, #3b82f6);
}

.on-ramp-tab--active.off-ramp-tab-btn:hover {
    background-color: var(--red-color, #3b82f6);
}

.rounded-left {
    border-top-left-radius: 9999px;
    border-bottom-left-radius: 9999px;
}

.rounded-right {
    border-top-right-radius: 9999px;
    border-bottom-right-radius: 9999px;
}

.payment-method-card {
    width: 40px;
    height: 24px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}

.payment-method-card--visa {
    background: #25459a;
    color: #ffffff;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 0.8px;
    font-style: italic;
    line-height: 1;
}

.payment-method-card--visa span {
    display: inline-block;
    transform: none !important;
    direction: ltr;
    unicode-bidi: isolate;
}

.payment-method-card--mastercard {
    position: relative;
    background: #10427a;
}

.mastercard-circle {
    width: 15px;
    height: 15px;
    border-radius: 999px;
    display: block;
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
}

.mastercard-circle--red {
    left: 10px;
    background: #eb001b;
}

.mastercard-circle--orange {
    right: 10px;
    background: #f79e1b;
    mix-blend-mode: screen;
}

.deposit-bank-card-box {
    width: 100%;
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 12px;
    padding: 12px 14px;
    background: rgba(15, 23, 42, 0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    transition: all 0.15s ease;
}

.deposit-bank-card-box--clickable {
    cursor: pointer;
}

.deposit-bank-card-box--clickable:hover {
    border-color: rgba(99, 102, 241, 0.55);
    background: rgba(99, 102, 241, 0.08);
}

.deposit-bank-card-box__content {
    min-width: 0;
    flex: 1;
}

.deposit-bank-card-box__label {
    font-size: 12px;
    opacity: 0.7;
    margin-bottom: 4px;
}

.deposit-bank-card-box__value {
    font-size: 14px;
    font-weight: 600;
    word-break: break-all;
    white-space: pre-wrap;
}

.deposit-bank-card-box__copy {
    flex-shrink: 0;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    color: #4f46e5;
    background: rgba(79, 70, 229, 0.1);
}

.deposit-bank-card-box__text {
    font-size: 14px;
    opacity: 0.8;
}
</style>