<script>
import {percentageBalance} from '@/Functions/WalletBalance.mjs';
import {stepDecimal,saveTradeDraft,takeTradeDraft} from '@/Functions/TradeFormDraft.mjs';
import {spotOrderPayload} from "@/Functions/SpotOrderPayload.mjs";
import {requestIntent, completeIntent, spotIntent} from '@/Functions/RequestIntent.mjs';
import MarketSession from "@/Mixins/Market/MarketSession";

import Template from '{Template}/Web/Pages/Market/Partials/OrderForm.template'
import TextInput from "@/Jetstream/TextInput";
import SelectInput from "@/Jetstream/SelectInput";
import {mapGetters} from "vuex";
import VueSlider from 'vue-slider-component'
import '@/../css/progress-slider/default.css'
import {math_formatter, math_percentage} from "@/Functions/Math";

import OrderFeedback from '@/Mixins/Market/OrderFeedback';
import OrderEstimate from "@/Mixins/Market/OrderEstimate";
import {estimateBuyQuantity} from "@/Functions/OrderEstimate.mjs";

export default Template({
    mixins: [MarketSession, OrderEstimate, OrderFeedback],
    components: {
        TextInput,
        SelectInput,
        VueSlider
    },

    props: {
        market: Object,
        fee: String
    },

    data() {
        return {
            fundingMenu: false,
            openForm: false,
            leverage: 25,
            balance: 0,
            balanceBuyError: false,
            balanceSellError: false,
            orderType: 'limit',

            bid: {
                price: 0,
                quantity: 0,
                quoteQuantity: 0,
                type: 'limit',
                side: 'buy',
                trigger_price: 0,
                total: 0,
            },

            ask: {
                price: 0,
                quantity: 0,
                quoteQuantity: 0,
                type: 'limit',
                side: 'sell',
                trigger_price: 0,
                total: 0,
            },

            buySlider: {
                min: 0,
                max: 100,
                interval: 0.01,
                value: 0,
                disabled: false,
                adsorb: false,
            },

            sellSlider: {
                min: 0,
                max: 100,
                interval: 0.01,
                value: 0,
                disabled: false,
                adsorb: false,
            },

            errors: null,
            placingBuyOrder: false,
            placingSellOrder: false,
            buyErrorField: false,
            sellErrorField: false,
            activeTab: 'buy',

            walletRefreshTimer: null,
            placeOrderHandler: null,

            syncingBuy: false,
            syncingSell: false,
            lastBuyInput: 'quote',
            lastSellInput: 'quantity',
        }
    },

    mounted() {
        this.placeOrderHandler = (data) => {
            let orderPrice = parseFloat(data.order.price || 0);
            let orderQuantity = parseFloat(data.order.quantity || 0);

            if(this.orderType != "market") {
                this.bid.price = this.decimal_format(orderPrice, this.market.quote_precision);
                this.ask.price = this.decimal_format(orderPrice, this.market.quote_precision);
            }

            if(orderPrice > 0 && orderQuantity > 0) {
                if(this.activeTab == "buy") {
                    let quoteAmount = this.buyBaseToQuote(orderQuantity, orderPrice);
                    let balance = this.getEffectiveTradeBalance(this.quoteWallet);

                    if(parseFloat(quoteAmount) > parseFloat(balance)) {
                        this.lastBuyInput = 'quote';
                        this.bid.quoteQuantity = this.decimal_format(balance, this.market.quote_precision);
                        this.syncBuyQuantityFromQuote();
                    } else {
                        this.lastBuyInput = 'quantity';
                        this.bid.quantity = this.decimal_format(orderQuantity, this.market.base_precision);
                        this.syncBuyQuoteFromQuantity();
                    }
                }

                if(this.activeTab == "sell") {
                    let balance = this.getEffectiveTradeBalance(this.baseWallet);

                    if(parseFloat(orderQuantity) > parseFloat(balance)) {
                        this.lastSellInput = 'quantity';
                        this.ask.quantity = this.decimal_format(balance, this.market.base_precision);
                        this.syncSellQuoteFromQuantity();
                    } else {
                        this.lastSellInput = 'quantity';
                        this.ask.quantity = this.decimal_format(orderQuantity, this.market.base_precision);
                        this.syncSellQuoteFromQuantity();
                    }
                }
            }
        };

        this.$worker.$on('place-order', this.placeOrderHandler);

        if(this.$page.props.user) {
            this.buySlider.disabled = false;
            this.sellSlider.disabled = false;

            this.fetchWallets();

            this.walletRefreshTimer = setInterval(() => {
                this.fetchWallets();
            }, 10000);
        }

        this.bid.price = this.market.last ?? '';
        this.ask.price = this.market.last ?? '';
        try {
            const draft=takeTradeDraft(this.$page.props.user?.id,this.market.name);
            if(draft){this.activeTab=draft.activeTab;this.orderType=draft.orderType;this.lastBuyInput=draft.lastBuyInput;this.lastSellInput=draft.lastSellInput;Object.assign(this.bid,draft.bid);Object.assign(this.ask,draft.ask);}
        }catch(_){}
    },

    beforeDestroy() {
        if(this.walletRefreshTimer) {
            clearInterval(this.walletRefreshTimer);
            this.walletRefreshTimer = null;
        }

        if(this.placeOrderHandler) {
            this.$worker.$off('place-order', this.placeOrderHandler);
            this.placeOrderHandler = null;
        }
    },

    computed: {
        ...mapGetters({
            wallets: 'getWallets'
        }),

        market_stats: function () {
            return this.estimateMarket;
        },

        baseWallet: function () {
            if(this.$store.getters.getUser) {
                return this.$store.getters.getWallet(this.market.base_currency);
            }
        },

        quoteWallet: function () {
            if(this.$store.getters.getUser) {
                return this.$store.getters.getWallet(this.market.quote_currency);
            }
        },

        quoteTradeBalance: function () {
            return math_formatter(this.getEffectiveTradeBalance(this.quoteWallet), this.market.quote_precision);
        },

        baseTradeBalance: function () {
            return math_formatter(this.getEffectiveTradeBalance(this.baseWallet), this.market.base_precision);
        },

        useVirtualQuoteBalance: function () {
            return this.shouldUseVirtualTradeBalance(this.quoteWallet);
        },

        useVirtualBaseBalance: function () {
            return this.shouldUseVirtualTradeBalance(this.baseWallet);
        },

        feeRate: function () {
            return parseFloat(this.fee || 0) / 100;
        },

        tradeMaxBuyQuote: function () {
            let balance = this.getEffectiveTradeBalance(this.quoteWallet);
            return math_formatter(balance, this.market.quote_precision);
        },

        tradeMaxBuy: function () {
            let price = this.getCurrentPrice();

            if(!price || price == 0) return 0;

            let balance = this.getEffectiveTradeBalance(this.quoteWallet);

            return this.buyQuoteToBase(balance, price);
        },

        tradeMaxSell: function () {
            let balance = this.getEffectiveTradeBalance(this.baseWallet);
            return math_formatter(balance, this.market.base_precision);
        },

        tradeMaxSellQuote: function () {
            let balance = this.getEffectiveTradeBalance(this.baseWallet);
            let price = this.getCurrentPrice();

            if(!price || price == 0) return 0;

            return math_formatter(this.sellBaseToQuote(balance, price), this.market.quote_precision);
        },

        estimateBidFee: function () {
            // Market buys charge the submitted quote amount, independent of price availability.
            return math_formatter(parseFloat(this.bid.quoteQuantity || 0) * this.feeRate, 8);
        },

        estimateAskFee: function () {
            let price = this.getCurrentPrice();

            if(!price || !this.ask.quantity) return 0;

            let grossQuote = parseFloat(this.ask.quantity) * price;
            return math_formatter(grossQuote * this.feeRate, 8);
        },
    },

    methods: {
        stepField(side,field,direction) {
            if(field==='price' && this.orderType==='market')return;
            const form=side==='buy'?this.bid:this.ask;
            if(field==='quantity'){if(side==='buy')this.lastBuyInput='quantity';else this.lastSellInput='quantity';}
            form[field]=stepDecimal(form[field],field==='price'?this.market.quote_precision:this.market.base_precision,direction);
        },
        openFunding(kind) {
            this.fundingMenu=false;
            try {saveTradeDraft(this.$page.props.user?.id,this.market.name,this);}catch(_){}
            const symbol=this.activeTab==='buy'?this.market.quote_currency:this.market.base_currency;
            const params={symbol,trade_return:this.market.name,side:this.activeTab};
            this.$inertia.visit(kind==='deposit'?this.route('wallets.deposit.crypto',params):this.route('wallets.transfer',{...params,from:'funding'}));
        },
        fetchWallets() {
            if(!this.$page.props.user) return;
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        },

        toNumber(value) {
            if(value === null || value === undefined || value === '') return 0;

            let number = parseFloat(value);

            if(isNaN(number) || !isFinite(number)) return 0;

            return number;
        },

        getRealTradeBalance(wallet) {
            if(!wallet) return 0;
            return this.toNumber(wallet.balance_in_trade || 0);
        },

        getVirtualWalletBalance(wallet) {
            if(!wallet) return 0;
            return this.toNumber(wallet.balance_in_virtual_wallet || 0);
        },

        getVirtualTradeBalance(wallet) {
            if(!wallet) return 0;
            return this.toNumber(wallet.balance_in_virtual_trade || 0);
        },

        getVirtualBalance(wallet) {
            if(!wallet) return 0;

            /*
             * 现货余额只读取交易账户：
             * 真实账户：balance_in_trade
             * 虚拟账户：balance_in_virtual_trade
             * 不再读取 balance_in_virtual_wallet。
             */
            return this.getVirtualTradeBalance(wallet);
        },

        getVirtualBalanceSource(wallet) {
            if(!wallet) return null;

            return this.getVirtualTradeBalance(wallet) > 0 ? 'trade' : null;
        },

        shouldUseVirtualTradeBalance(wallet) {
            return this.getVirtualBalance(wallet) > 0;
        },

        getEffectiveTradeBalance(wallet) {
            if(!wallet) return 0;

            let virtualBalance = this.getVirtualBalance(wallet);

            if(virtualBalance > 0) {
                return virtualBalance;
            }

            return this.getRealTradeBalance(wallet);
        },

        getOrderAccountType(side) {
            if(side == 'buy') {
                return this.shouldUseVirtualTradeBalance(this.quoteWallet) ? 'virtual' : 'real';
            }

            return this.shouldUseVirtualTradeBalance(this.baseWallet) ? 'virtual' : 'real';
        },

        getCurrentPrice() {
            let price = 0;

            if(this.activeTab == 'sell') {
                price = this.orderType == 'market'
                    ? this.estimatePrice
                    : this.ask.price;
            } else {
                price = this.orderType == 'market'
                    ? this.estimatePrice
                    : this.bid.price;
            }

            return parseFloat(price || 0);
        },

        buyQuoteToBase(quoteAmount, price) {
            return estimateBuyQuantity(quoteAmount, price, this.fee, this.market.base_precision);
        },

        buyBaseToQuote(baseAmount, price) {
            if(!baseAmount || !price || price == 0) return 0;

            let denominator = 1 - this.feeRate;

            if(denominator <= 0) return 0;

            let quoteAmount = (parseFloat(baseAmount) * parseFloat(price)) / denominator;

            if(quoteAmount < 0) quoteAmount = 0;

            return math_formatter(quoteAmount, this.market.quote_precision);
        },

        sellBaseToQuote(baseAmount, price) {
            if(!baseAmount || !price || price == 0) return 0;

            let grossQuote = parseFloat(baseAmount) * parseFloat(price);
            let quoteAmount = grossQuote - (grossQuote * this.feeRate);

            if(quoteAmount < 0) quoteAmount = 0;

            return math_formatter(quoteAmount, this.market.quote_precision);
        },

        sellQuoteToBase(quoteAmount, price) {
            if(!quoteAmount || !price || price == 0) return 0;

            let denominator = parseFloat(price) * (1 - this.feeRate);

            if(denominator <= 0) return 0;

            let baseAmount = parseFloat(quoteAmount) / denominator;

            if(baseAmount < 0) baseAmount = 0;

            return math_formatter(baseAmount, this.market.base_precision);
        },

        syncBuyQuantityFromQuote() {
            if(this.syncingBuy) return;

            let price = this.getCurrentPrice();

            this.syncingBuy = true;

            if(!this.bid.quoteQuantity || !price) {
                this.bid.quantity = 0;
                this.bid.total = 0;
                this.syncingBuy = false;
                return;
            }

            this.bid.quantity = this.buyQuoteToBase(this.bid.quoteQuantity, price);
            this.bid.total = math_formatter(this.bid.quoteQuantity, this.market.quote_precision);

            this.syncingBuy = false;
        },

        syncBuyQuoteFromQuantity() {
            if(this.syncingBuy) return;

            let price = this.getCurrentPrice();

            this.syncingBuy = true;

            if(!this.bid.quantity || !price) {
                this.bid.quoteQuantity = 0;
                this.bid.total = 0;
                this.syncingBuy = false;
                return;
            }

            this.bid.quoteQuantity = this.buyBaseToQuote(this.bid.quantity, price);
            this.bid.total = math_formatter(this.bid.quoteQuantity, this.market.quote_precision);

            this.syncingBuy = false;
        },

        syncSellQuoteFromQuantity() {
            if(this.syncingSell) return;

            let price = this.getCurrentPrice();

            this.syncingSell = true;

            if(!this.ask.quantity || !price) {
                this.ask.quoteQuantity = 0;
                this.ask.total = 0;
                this.syncingSell = false;
                return;
            }

            this.ask.quoteQuantity = this.sellBaseToQuote(this.ask.quantity, price);
            this.ask.total = math_formatter(this.ask.quoteQuantity, this.market.quote_precision);

            this.syncingSell = false;
        },

        syncSellQuantityFromQuote() {
            if(this.syncingSell) return;

            let price = this.getCurrentPrice();

            this.syncingSell = true;

            if(!this.ask.quoteQuantity || !price) {
                this.ask.quantity = 0;
                this.ask.total = 0;
                this.syncingSell = false;
                return;
            }

            this.ask.quantity = this.sellQuoteToBase(this.ask.quoteQuantity, price);
            this.ask.total = math_formatter(this.ask.quoteQuantity, this.market.quote_precision);

            this.syncingSell = false;
        },

        placeBuyOrder() {
            if (this.sessionBlocked || !this.canSubmitOrder('buy')) return;
            if(this.lastBuyInput == 'quantity') {
                this.syncBuyQuoteFromQuantity();
            } else {
                this.syncBuyQuantityFromQuote();
            }

            if(!this.bid.quantity || this.bid.quantity == 0) {
                this.buyErrorField = 'quantity';
            }

            if(!this.bid.quoteQuantity || this.bid.quoteQuantity == 0) {
                this.buyErrorField = 'quoteQuantity';
            }

            if(this.orderType != "market" && (!this.bid.price || this.bid.price == 0)) {
                this.buyErrorField = 'price';
            }

            if(this.buyErrorField) return;
            if(this.placingBuyOrder) return;

            this.buyErrorField = null;
            this.sellErrorField = null;
            this.placingBuyOrder = true;

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            this.bid.market = this.market.name;
            this.bid.type = this.orderType;

            let payload = spotOrderPayload(this.bid);

            payload.account_type = this.getOrderAccountType('buy');
            payload.use_virtual_wallet = payload.account_type === 'virtual';
            payload.virtual_balance_source = this.getVirtualBalanceSource(this.quoteWallet);

            if(payload.type == "market") {
                payload.quoteQuantity = this.bid.quoteQuantity;
            } else {
                delete payload.quoteQuantity;
            }

            if(payload.type == "stop_limit") {
                payload.trigger_condition = 'down';
                payload.trigger_price = this.bid.trigger_price;
            }

            let formRoute = this.route('orders.store');

            const intentScope='spot:'+this.$page.props.user.id+':buy';
            const intent=requestIntent(intentScope,spotIntent(payload),payload);
            payload={...intent.payload,client_order_id:intent.key};
            axios.post(formRoute, payload, {timeout: 20000}).then((response) => {
                completeIntent(intentScope,intent.key);
                this.placingBuyOrder = false;
                this.$toast.success(this.$t("Order Created"));

                this.$store.dispatch('fetchOpenOrders', {
                    market: this.market.name,
                    route: this.route('orders.api.open')
                });

                this.fetchWallets();
                this.clearForm();
            }).catch(error => {
                this.placingBuyOrder = false;
                this.showOrderFailure(error, 'buy', false);
            });
        },

        placeSellOrder() {
            if (this.sessionBlocked || !this.canSubmitOrder('sell')) return;
            if(this.lastSellInput == 'quote') {
                this.syncSellQuantityFromQuote();
            } else {
                this.syncSellQuoteFromQuantity();
            }

            if(!this.ask.quantity || this.ask.quantity == 0) {
                this.sellErrorField = 'quantity';
            }

            if(!this.ask.quoteQuantity || this.ask.quoteQuantity == 0) {
                this.sellErrorField = 'quoteQuantity';
            }

            if(this.orderType != "market" && (!this.ask.price || this.ask.price == 0)) {
                this.sellErrorField = 'price';
            }

            if(this.sellErrorField) return;
            if(this.placingSellOrder) return;

            this.placingSellOrder = true;
            this.buyErrorField = null;
            this.sellErrorField = null;

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            this.ask.market = this.market.name;
            this.ask.type = this.orderType;

            let payload = spotOrderPayload(this.ask);

            payload.account_type = this.getOrderAccountType('sell');
            payload.use_virtual_wallet = payload.account_type === 'virtual';
            payload.virtual_balance_source = this.getVirtualBalanceSource(this.baseWallet);

            delete payload.quoteQuantity;

            if(payload.type == "stop_limit") {
                payload.trigger_condition = 'down';
                payload.trigger_price = this.ask.trigger_price;
            }

            let formRoute = this.route('orders.store');

            const intentScope='spot:'+this.$page.props.user.id+':sell';
            const intent=requestIntent(intentScope,spotIntent(payload),payload);
            payload={...intent.payload,client_order_id:intent.key};
            axios.post(formRoute, payload, {timeout: 20000}).then((response) => {
                completeIntent(intentScope,intent.key);
                this.placingSellOrder = false;
                this.$toast.success(this.$t("Order Created"));

                this.$store.dispatch('fetchOpenOrders', {
                    market: this.market.name,
                    route: this.route('orders.api.open')
                });

                this.fetchWallets();
                this.clearForm();
            }).catch(error => {
                this.placingSellOrder = false;
                this.showOrderFailure(error, 'sell', false);
            });
        },

        setOrderType(type) {
            if(this.orderType == type) return;

            this.orderType = type;
            this.buySlider.value = 0;
            this.sellSlider.value = 0;
            this.buyErrorField = null;
            this.sellErrorField = null;

            this.clearForm();
        },

        calculateFee(size) {
            if(size == 0) return 0;
            return math_formatter(this.feeRate * size, 8);
        },

        multiplier(num1, num2, side, field, precision) {
            if(!num1 || !num2) {
                if (side == "ask") {
                    this.ask.total = 0;
                } else {
                    this.bid.total = 0;
                }
                return;
            }

            let amount = math_formatter((parseFloat(num1) * parseFloat(num2)), precision);

            if(side == "ask") {
                this.ask[field] = amount;
            } else {
                amount = math_formatter(
                    (parseFloat(num1) * parseFloat(num2)) + ((this.feeRate * num2) * num1),
                    precision
                );
                this.bid[field] = amount;
            }
        },

        decimal_format(value, decimal) {
            return math_formatter(value, decimal);
        },

        divider(num1, num2, side, field, precision) {
            if(!num1 || !num2 || num2 == 0) {
                if(side == "ask") {
                    this.ask.total = 0;
                } else {
                    this.bid.total = 0;
                }
                return;
            }

            let amount = math_formatter((parseFloat(num1) / parseFloat(num2)) - (this.calculateFee(num1) / num2), precision);

            if(side == "ask") {
                this.ask[field] = amount;
            } else {
                this.bid[field] = amount;
            }
        },

        formatSliderTooltip(value) {
            let percentage = this.normalizeSliderPercentage(value);

            if(percentage >= 100) {
                return '100%';
            }

            let text = percentage.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');

            return text + '%';
        },

        normalizeSliderPercentage(percentage) {
            let value = this.toNumber(percentage);

            if(value > 100) value = 100;
            if(value < 0) value = 0;

            return parseFloat(value.toFixed(2));
        },

        getOrderSliderPercentage(percentage) {
            let value = this.normalizeSliderPercentage(percentage);

            return value;
        },

        changeBuySlider(percentage) {
            percentage = this.normalizeSliderPercentage(percentage);

            if(Math.abs(this.toNumber(this.buySlider.value) - percentage) > 0.000001) {
                this.buySlider.value = percentage;
            }

            let orderPercentage = this.getOrderSliderPercentage(percentage);

            this.lastBuyInput = 'quote';
            this.bid.quoteQuantity = percentageBalance(this.useVirtualQuoteBalance ? this.quoteWallet?.balance_in_virtual_trade : this.quoteWallet?.balance_in_trade, orderPercentage, this.market.quote_precision);
            this.syncBuyQuantityFromQuote();
        },

        changeSellSlider(percentage) {
            percentage = this.normalizeSliderPercentage(percentage);

            if(Math.abs(this.toNumber(this.sellSlider.value) - percentage) > 0.000001) {
                this.sellSlider.value = percentage;
            }

            let orderPercentage = this.getOrderSliderPercentage(percentage);

            this.lastSellInput = 'quantity';
            this.ask.quantity = percentageBalance(this.useVirtualBaseBalance ? this.baseWallet?.balance_in_virtual_trade : this.baseWallet?.balance_in_trade, orderPercentage, this.market.base_precision);
            this.syncSellQuoteFromQuantity();
        },

        clearInput($event, side, field) {
            if(this.getFormData(side, field).charAt(0) == '.') {
                if(field == 'balance') {
                    this.balance = 0;
                    return;
                }

                this.setFormData(side, field, 0);
                return;
            }

            this.setFormData(side, field, this.getFormData(side, field).replace(/[^0-9.]/g, ''));

            const firstDotIndex = this.getFormData(side, field).indexOf('.');

            if(firstDotIndex !== -1) {
                this.setFormData(
                    side,
                    field,
                    this.getFormData(side, field).slice(0, firstDotIndex + 1) +
                    this.getFormData(side, field).slice(firstDotIndex + 1).replace(/\./g, '')
                );
            }

            if(/^0+\.\d+/.test(this.getFormData(side, field))) {
                this.setFormData(side, field, this.getFormData(side, field).replace(/^0+/, '0'));
            }

            if(/^0+\d+/.test(this.getFormData(side, field))) {
                this.setFormData(side, field, this.getFormData(side, field).replace(/^0+/, ''));
            }

            if(side == 'buy' && field == 'quoteQuantity') {
                this.lastBuyInput = 'quote';
                this.syncBuyQuantityFromQuote();
            }

            if(side == 'buy' && field == 'quantity') {
                this.lastBuyInput = 'quantity';
                this.syncBuyQuoteFromQuantity();
            }

            if(side == 'ask' && field == 'quoteQuantity') {
                this.lastSellInput = 'quote';
                this.syncSellQuantityFromQuote();
            }

            if(side == 'ask' && field == 'quantity') {
                this.lastSellInput = 'quantity';
                this.syncSellQuoteFromQuantity();
            }
        },

        getFormData(side, field) {
            if(field == 'balance') {
                return this.balance.toString();
            }

            if(side == 'buy') {
                return this.bid[field].toString();
            }

            return this.ask[field].toString();
        },

        setFormData(side, field, value) {
            if(side == 'buy') {
                this.bid[field] = value;
                return;
            }

            this.ask[field] = value;
        },

        setTab(side) {
            if(this.activeTab == side) return;

            this.openForm = true;
            this.activeTab = side;
            this.buyErrorField = null;
            this.sellErrorField = null;

            this.clearForm();
        },

        clearForm() {
            this.bid.quantity = 0;
            this.bid.quoteQuantity = 0;
            this.bid.total = 0;

            this.ask.quantity = 0;
            this.ask.quoteQuantity = 0;
            this.ask.total = 0;

            this.buySlider.value = 0;
            this.sellSlider.value = 0;

            this.lastBuyInput = 'quote';
            this.lastSellInput = 'quantity';
        },

        setLeverage(value) {
            this.leverage = value;
        }
    },

    watch: {
        'market.name'() {
            this.fundingMenu=false;this.buySlider.value=0;this.sellSlider.value=0;
            this.bid={...this.bid,price:this.market.last || '',quantity:0,quoteQuantity:0,trigger_price:0};
            this.ask={...this.ask,price:this.market.last || '',quantity:0,quoteQuantity:0,trigger_price:0};
            this.buyErrorField=false;this.sellErrorField=false;this.errors=null;
        },
        estimatePrice() {
            if (this.isStockEstimate && this.estimatePrice > 0) {
                if (Number(this.bid.price) <= 0) this.bid.price = this.decimal_format(this.estimatePrice,this.market.quote_precision);
                if (Number(this.ask.price) <= 0) this.ask.price = this.decimal_format(this.estimatePrice,this.market.quote_precision);
            }
            if (this.orderType !== 'market') return;
            if (this.lastBuyInput === 'quote') this.syncBuyQuantityFromQuote();
            else this.syncBuyQuoteFromQuantity();
            if (this.lastSellInput === 'quote') this.syncSellQuantityFromQuote();
            else this.syncSellQuoteFromQuantity();
        },
        orderType: function(type) {
            if(type == 'market') {
                // this.bid.price = '';
                // this.ask.price = '';
            }

            if(this.lastBuyInput == 'quote') {
                this.syncBuyQuantityFromQuote();
            } else {
                this.syncBuyQuoteFromQuantity();
            }

            if(this.lastSellInput == 'quote') {
                this.syncSellQuantityFromQuote();
            } else {
                this.syncSellQuoteFromQuantity();
            }
        },

        'ask.price'(newVal) {
            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if(newVal.includes('.')) {
                this.ask.price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision);
            }

            if(this.lastSellInput == 'quote') {
                this.syncSellQuantityFromQuote();
            } else {
                this.syncSellQuoteFromQuantity();
            }

            if(parseFloat(newVal) > 0 && this.sellErrorField == 'price') {
                this.sellErrorField = '';
            }
        },

        'bid.price'(newVal) {
            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if(newVal.includes('.')) {
                this.bid.price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision);
            }

            if(this.lastBuyInput == 'quote') {
                this.syncBuyQuantityFromQuote();
            } else {
                this.syncBuyQuoteFromQuantity();
            }

            if(parseFloat(newVal) > 0 && this.buyErrorField == 'price') {
                this.buyErrorField = '';
            }
        },

        'bid.quoteQuantity'(newVal) {
            let balance = this.getEffectiveTradeBalance(this.quoteWallet);

            if(this.syncingBuy) return;

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if(newVal.includes('.')) {
                this.bid.quoteQuantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision);
            }

            if(this.lastBuyInput == 'quote') {
                this.syncBuyQuantityFromQuote();
            }

            if(parseFloat(this.bid.quoteQuantity || 0) > parseFloat(balance || 0)) {
                this.buyErrorField = "quoteQuantity";
                this.balanceBuyError = true;
            } else {
                if(this.buyErrorField == "quoteQuantity") this.buyErrorField = null;
                this.balanceBuyError = false;
            }
        },

        'bid.quantity'(newVal) {
            if(this.syncingBuy) return;

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if(newVal.includes('.')) {
                this.bid.quantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.base_precision);
            }

            if(this.lastBuyInput == 'quantity') {
                this.syncBuyQuoteFromQuantity();
            }
        },

        'ask.quoteQuantity'(newVal) {
            if(this.syncingSell) return;

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if(newVal.includes('.')) {
                this.ask.quoteQuantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision);
            }

            if(this.lastSellInput == 'quote') {
                this.syncSellQuantityFromQuote();
            }

            let needBaseAmount = parseFloat(this.ask.quantity || 0);

            if(needBaseAmount > parseFloat(balance || 0)) {
                this.sellErrorField = "quoteQuantity";
                this.balanceSellError = true;
            } else {
                if(this.sellErrorField == "quoteQuantity") this.sellErrorField = null;
                this.balanceSellError = false;
            }
        },

        'ask.quantity'(newVal) {
            if(this.syncingSell) return;

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if(newVal.includes('.')) {
                this.ask.quantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.base_precision);
            }

            if(this.lastSellInput == 'quantity') {
                this.syncSellQuoteFromQuantity();
            }


            if(parseFloat(this.ask.quantity || 0) > parseFloat(balance || 0)) {
                this.sellErrorField = "quantity";
                this.balanceSellError = true;
            } else {
                if(this.sellErrorField == "quantity") this.sellErrorField = null;
                this.balanceSellError = false;
            }
        },
    }
})
</script>
