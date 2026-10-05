<script>
import {requestIntent, completeIntent, spotIntent} from '@/Functions/RequestIntent.mjs';

import Template from '{Template}/Web/Pages/Market/Swap.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";
import {math_formatter} from "@/Functions/Math";

export default Template({
    components: {
        AppLayout,
        IconFilter
    },
    props: {
        currencies: Object,
        defaultBasePair: Number,
        defaultQuotePair: Number,
    },
    data() {
        return {
            reversed: false,
            processing: false,
            processing_balance: false,
            placingOrder: false,
            placedOrder: false,
            form: {
                quote_amount: '0',
                base_amount: null,
                market_id: null,
            },
            basePair: null,
            quotePair: null,
            exchangeRate: null,
            selectedMarket: null,
            selectedMarketBase: null,
            error: null,
            balance: '0',
            balanceSource: 'real',
            balanceWallet: null,
            exchangeState: false,

            // 全部固定 8 位，不再依赖 markets 表
            fixedPrecision: 8,
        }
    },
    computed: {
        baseMarkets: function () {
            return _.filter(this.currencies, (market) => {
                return market.id != this.quotePair;
            });
        },
        quoteMarkets: function () {
            return _.filter(this.currencies, (market) => {
                return market.id != this.basePair;
            });
        },
        currentAction: function () {
            return this.selectedMarketBase == this.basePair ? 'sell' : 'buy';
        }
    },
    mounted() {
        /*
         * 页面进入时默认选择：
         * You send: USDT
         * You get: BTC
         *
         * 如果 currencies 里找不到 USDT / BTC，
         * 再回退使用后端传入的 defaultBasePair / defaultQuotePair。
         */
        const usdtId = this.findCurrencyIdBySymbol('USDT');
        const btcId = this.findCurrencyIdBySymbol('BTC');

        this.basePair = usdtId || this.defaultBasePair || null;
        this.quotePair = btcId || this.defaultQuotePair || null;

        if (this.basePair && this.quotePair && String(this.basePair) === String(this.quotePair)) {
            this.quotePair = this.findFirstDifferentCurrencyId(this.basePair);
        }

        if (this.basePair && this.quotePair) {
            this.setupInitialData();
        }
    },
    methods: {
        findCurrencyIdBySymbol(symbol) {
            if (!this.currencies || !symbol) {
                return null;
            }

            const target = String(symbol).toUpperCase();

            const list = Array.isArray(this.currencies)
                ? this.currencies
                : Object.values(this.currencies);

            const currency = list.find((item) => {
                if (!item) {
                    return false;
                }

                const symbols = [
                    item.symbol,
                    item.name,
                    item.code,
                    item.slug,
                ].filter(Boolean).map((value) => String(value).toUpperCase());

                return symbols.includes(target);
            });

            return currency ? currency.id : null;
        },

        findFirstDifferentCurrencyId(currencyId) {
            if (!this.currencies) {
                return null;
            }

            const list = Array.isArray(this.currencies)
                ? this.currencies
                : Object.values(this.currencies);

            const currency = list.find((item) => {
                return item && String(item.id) !== String(currencyId);
            });

            return currency ? currency.id : null;
        },

        setupInitialData() {
            this.loadExchangeRate();

            if (this.$store.getters.getUser) {
                this.getBalance();
            }
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            let number = Number(String(value).replace(/,/g, ''));

            if (!Number.isFinite(number)) {
                return 0;
            }

            return number;
        },

        trimTrailingZeros(value) {
            if (value === null || value === undefined || value === '') {
                return value;
            }

            let amount = String(value);

            if (amount.indexOf('e') !== -1 || amount.indexOf('E') !== -1) {
                amount = Number(amount).toFixed(this.fixedPrecision);
            }

            if (amount.indexOf('.') === -1) {
                return amount;
            }

            amount = amount.replace(/(\.\d*?[1-9])0+$/g, '$1');
            amount = amount.replace(/\.0+$/g, '');
            amount = amount.replace(/\.$/g, '');

            return amount === '' ? '0' : amount;
        },

        cleanAmount(value) {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            let amount = value.toString();

            // 只允许数字和小数点
            amount = amount.replace(/[^\d.]/g, '');

            // 只保留第一个小数点
            let firstDotIndex = amount.indexOf('.');
            if (firstDotIndex !== -1) {
                let beforeDot = amount.slice(0, firstDotIndex + 1);
                let afterDot = amount.slice(firstDotIndex + 1).replace(/\./g, '');
                amount = beforeDot + afterDot;
            }

            // .1 => 0.1
            if (amount.charAt(0) === '.') {
                amount = '0' + amount;
            }

            // 00012 => 12
            if (/^0+\d+/.test(amount)) {
                amount = amount.replace(/^0+/, '');
            }

            // 000.12 => 0.12
            if (/^0+\.\d+/.test(amount)) {
                amount = amount.replace(/^0+/, '0');
            }

            return amount;
        },

        limitAmountToEight(value) {
            if (value === null || value === undefined || value === '') {
                return value;
            }

            let amount = this.cleanAmount(value);

            if (amount === '') {
                return null;
            }

            if (amount.indexOf('.') === -1) {
                return amount;
            }

            let parts = amount.split('.');
            let integerPart = parts[0] || '0';
            let decimalPart = parts[1] || '';

            return integerPart + '.' + decimalPart.slice(0, this.fixedPrecision);
        },

        formatAmountToEight(value) {
            let amount = this.limitAmountToEight(value);

            if (amount === null || amount === undefined || amount === '') {
                return amount;
            }

            let numberAmount = Number(amount);

            if (isNaN(numberAmount)) {
                return null;
            }

            // 向下截断到 8 位，避免 Max 四舍五入导致余额不足
            let multiplier = Math.pow(10, this.fixedPrecision);
            let floored = Math.floor((numberAmount + Number.EPSILON) * multiplier) / multiplier;

            return this.trimTrailingZeros(floored.toFixed(this.fixedPrecision));
        },

        refreshQuoteAmount() {
            if (this.form.base_amount && !this.error && this.exchangeRate) {
                let quoteAmount = math_formatter(
                    parseFloat(this.form.base_amount) * parseFloat(this.exchangeRate),
                    this.fixedPrecision
                );

                this.form.quote_amount = this.trimTrailingZeros(quoteAmount);
            } else {
                this.form.quote_amount = '0';
            }
        },

        loadExchangeRate() {
            if (this.processing) return;

            this.error = null;
            this.processing = true;

            axios.get(this.route('market.exchange_rate'), {
                params: {
                    'base': this.basePair,
                    'quote': this.quotePair
                }
            }).then((res) => {
                this.processing = false;

                if (res.data && res.data.success) {
                    this.exchangeRate = res.data.rate;
                    this.selectedMarket = res.data.market;
                    this.selectedMarketBase = res.data.marketBase;

                    if (this.form.base_amount) {
                        this.form.base_amount = this.limitAmountToEight(this.form.base_amount);
                    }

                    this.refreshQuoteAmount();

                    return;
                }

                this.error = res.data.message;
                this.exchangeRate = null;
                this.form.base_amount = null;
                this.form.quote_amount = '0';
                this.selectedMarket = false;
                this.selectedMarketBase = false;
                this.placedOrder = false;

            }).catch((error) => {
                this.processing = false;
            });
        },

        swapPair() {
            this.reversed = !this.reversed;

            let base = this.basePair;

            this.basePair = this.quotePair;
            this.quotePair = base;

            this.setupInitialData();

            if (this.form.base_amount) {
                this.form.base_amount = this.limitAmountToEight(this.form.base_amount);
            }
        },

        getBalance() {
            if (this.processing_balance) return;

            this.processing_balance = true;
            this.error = null;

            /*
             * Swap 页面余额规则：
             * 1. 全部按交易账户显示。
             * 2. 如果 balance_in_virtual_trade > 0，显示并使用虚拟交易账户。
             * 3. 否则显示并使用真实交易账户 balance_in_trade。
             * 4. 不再使用 balance_in_virtual_wallet / balance_in_wallet 资金账户。
             */
            axios.get(this.route('wallets.index'), {
                params: {
                    context: 'trade',
                    _t: Date.now(),
                }
            }).then((res) => {
                const wallets = this.extractWalletsFromResponse(res);
                const wallet = this.findWalletByCurrencyId(wallets, this.basePair);

                if (wallet) {
                    this.applyWalletBalance(wallet);
                    return;
                }

                return this.getBalanceFallback();
            }).catch(() => {
                return this.getBalanceFallback();
            }).finally(() => {
                this.processing_balance = false;
            });
        },

        getBalanceFallback() {
            return axios.get(this.route('wallets.api.getBalance'), {
                params: {
                    'currency': this.basePair,
                    'type': 'trade',
                }
            }).then((res) => {
                if (res.data && res.data.success) {
                    this.balance = this.formatAmountToEight(res.data.balance || 0);
                    this.balanceSource = 'real';
                    this.balanceWallet = null;
                    return;
                }

                this.error = res.data.message;
            }).catch(() => {
                this.balance = '0';
                this.balanceSource = 'real';
                this.balanceWallet = null;
            });
        },

        extractWalletsFromResponse(response) {
            const payload = response && response.data ? response.data : response;

            if (!payload) {
                return [];
            }

            if (Array.isArray(payload)) {
                return payload;
            }

            if (payload.wallets) {
                return payload.wallets;
            }

            if (payload.data) {
                if (Array.isArray(payload.data)) {
                    return payload.data;
                }

                if (payload.data.wallets) {
                    return payload.data.wallets;
                }

                if (typeof payload.data === 'object') {
                    return payload.data;
                }
            }

            if (typeof payload === 'object') {
                return payload;
            }

            return [];
        },

        findWalletByCurrencyId(wallets, currencyId) {
            if (!wallets || !currencyId) {
                return null;
            }

            const targetCurrency = this.currencies && this.currencies[currencyId]
                ? this.currencies[currencyId]
                : null;

            const targetSymbols = [];

            if (targetCurrency) {
                if (targetCurrency.symbol) targetSymbols.push(String(targetCurrency.symbol));
                if (targetCurrency.name) targetSymbols.push(String(targetCurrency.name));
                if (targetCurrency.code) targetSymbols.push(String(targetCurrency.code));
            }

            const matchWallet = (wallet) => {
                if (!wallet) {
                    return false;
                }

                const walletCurrencyId = wallet.currency_id ||
                    wallet.currencyId ||
                    (wallet.currency ? wallet.currency.id : null);

                if (String(walletCurrencyId) === String(currencyId)) {
                    return true;
                }

                const walletSymbols = [
                    wallet.symbol,
                    wallet.currency_symbol,
                    wallet.currencySymbol,
                    wallet.currency,
                    wallet.code,
                    wallet.name,
                    wallet.currency ? wallet.currency.symbol : null,
                    wallet.currency ? wallet.currency.code : null,
                    wallet.currency ? wallet.currency.name : null,
                ].filter(Boolean).map((item) => String(item));

                return targetSymbols.some((symbol) => walletSymbols.includes(symbol));
            };

            if (Array.isArray(wallets)) {
                return wallets.find((wallet) => matchWallet(wallet)) || null;
            }

            if (typeof wallets === 'object') {
                if (wallets[currencyId] && matchWallet(wallets[currencyId])) {
                    return wallets[currencyId];
                }

                const values = Object.values(wallets);
                return values.find((wallet) => matchWallet(wallet)) || null;
            }

            return null;
        },

        applyWalletBalance(wallet) {
            const virtualTradeBalance = this.toNumber(wallet.balance_in_virtual_trade || 0);
            const realTradeBalance = this.toNumber(wallet.balance_in_trade || 0);

            this.balanceWallet = wallet;

            if (virtualTradeBalance > 0) {
                this.balance = this.formatAmountToEight(virtualTradeBalance);
                this.balanceSource = 'virtual';
                return;
            }

            this.balance = this.formatAmountToEight(realTradeBalance);
            this.balanceSource = 'real';
        },

        injectBalance() {
            this.form.base_amount = this.formatAmountToEight(this.balance);
        },

        submitExchange() {
            if (!this.$page.props.user) return;

            if (!this.selectedMarket || this.placingOrder) return;

            let amount = this.formatAmountToEight(this.form.base_amount);

            if (!amount || parseFloat(amount) <= 0) {
                return;
            }

            this.form.base_amount = amount;

            this.placingOrder = true;

            let action = this.currentAction;

            setTimeout(() => {
                this.exchangeState = 'searching';

                let data = {
                    market: this.selectedMarket,
                    type: 'market',
                    side: action,
                    quantity: amount,
                    swap: true,

                    /*
                     * 交易账户来源：
                     * virtual_balance_source = trade，表示使用 balance_in_virtual_trade。
                     */
                    account_type: this.balanceSource === 'virtual' ? 'virtual' : 'real',
                    use_virtual_wallet: this.balanceSource === 'virtual',
                    virtual_balance_source: this.balanceSource === 'virtual' ? 'trade' : null,
                    balance_context: 'trade',
                };

                if (action == "buy") {
                    data.quoteQuantity = amount;
                }

                const intentScope='spot-swap:'+this.$page.props.user.id;
                const intent=requestIntent(intentScope,spotIntent(data),data);
                data={...intent.payload,client_order_id:intent.key};
                axios.post(this.route('orders.store'), data, {timeout:20000}).then((response) => {
                    completeIntent(intentScope,intent.key);
                    this.placingOrder = false;
                    this.exchangeState = false;
                    this.placedOrder = true;

                    this.form.base_amount = null;
                    this.form.quote_amount = '0';

                    if (this.$store.getters.getUser) {
                        this.getBalance();
                    }
                }).catch(error => {
                    this.placingOrder = false;
                    this.exchangeState = false;
                    this.placedOrder = false;

                    if (error.response && error.response.data && error.response.data.errors) {
                        _.each(error.response.data.errors, (field, key) => {
                            this.$toast.error(field[0]);
                        });
                    } else if (error.response && error.response.data && error.response.data.message) {
                        this.$toast.error(error.response.data.message);
                    } else { this.$toast.error(this.$i18n.locale.startsWith('zh')?'订单结果尚未确认，请检查订单记录；重试将沿用本次请求。':'Order outcome is not confirmed. Check order history; retry will reuse this request.'); }
                });

            }, 1000);
        },

        handleInput($event) {
            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);
            let currentValue = this.form.base_amount ? this.form.base_amount.toString() : '';

            if (
                (keyCode < 48 || keyCode > 57) &&
                (keyCode !== 46 || currentValue.indexOf('.') != -1)
            ) {
                $event.preventDefault();
                return;
            }

            if (
                currentValue.indexOf(".") > -1 &&
                currentValue.split('.')[1] &&
                currentValue.split('.')[1].length >= this.fixedPrecision
            ) {
                $event.preventDefault();
            }
        },

        clearInput($event) {
            if (this.form.base_amount === null || this.form.base_amount === undefined || this.form.base_amount === '') {
                return;
            }

            this.form.base_amount = this.limitAmountToEight(this.form.base_amount);
        },

        startNewTrade() {
            this.placedOrder = false;
        }
    },
    watch: {
        'form.base_amount'(newVal) {
            if (newVal === null || newVal === undefined || newVal === '') {
                this.form.quote_amount = '0';
                return;
            }

            let limitedValue = this.limitAmountToEight(newVal);

            if (limitedValue !== newVal) {
                this.form.base_amount = limitedValue;
                return;
            }

            this.refreshQuoteAmount();
        },
    }
})
</script>