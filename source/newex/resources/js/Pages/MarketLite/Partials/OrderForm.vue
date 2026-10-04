<script>
import {spotOrderPayload} from "@/Functions/SpotOrderPayload.mjs";
import {requestIntent, completeIntent, spotIntent} from '@/Functions/RequestIntent.mjs';
import MarketSession from "@/Mixins/Market/MarketSession";
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/MarketLite/Partials/OrderForm.template'
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
            openForm: false,
            leverage: 25,
            balance: 0,
            balanceBuyError: false,
            balanceSellError: false,
            orderType: 'limit',
            bid: {
                price: 0,
                quantity: 0,
                type: 'limit',
                side: 'buy',
                trigger_price: 0,
                total: 0,
            },
            ask: {
                price: 0,
                quantity: 0,
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
                formatter: '{value}%',
                disabled: false,
            },
            sellSlider: {
                min: 0,
                max: 100,
                interval: 0.01,
                value: 0,
                formatter: '{value}%',
                disabled: false,
            },
            errors: null,
            placingBuyOrder: false,
            placingSellOrder: false,
            buyErrorField: false,
            sellErrorField: false,
            activeTab: 'buy',
        }
    },
    mounted() {

        /*
        Order click event listener
         */
        this.placeOrderHandler = (data) => {

            // Set price
            if(this.orderType != "market") {
                this.bid.price = this.decimal_format(data.order.price, this.market.quote_precision);
                this.ask.price = this.decimal_format(data.order.price, this.market.quote_precision);
            }

            if(data.order.quantity > this.tradeMaxBuy) {
                this.bid.quantity = this.decimal_format(this.tradeMaxBuy, this.market.base_precision);
            } else {
                this.bid.quantity = this.decimal_format(data.order.quantity, this.market.base_precision);
            }

            if(this.activeTab == "sell") {

                let balance = this.getEffectiveTradeBalance(this.baseWallet);

                if(parseFloat(balance) < parseFloat(data.order.quantity)) {
                    this.ask.quantity = this.decimal_format(balance, this.market.base_precision);
                } else {
                    this.ask.quantity = this.decimal_format(data.order.quantity, this.market.base_precision);
                }

            }
        };
        this.$worker.$on('place-order', this.placeOrderHandler);

        if(_.isEmpty(this.wallets) && this.$page.props.user) {
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        }

        if(this.$page.props.user) {
            this.buySlider.disabled = false;
            this.sellSlider.disabled = false;
        }

        this.bid.price = this.market.last ?? '';
        this.ask.price = this.market.last ?? '';
    },

    beforeDestroy() {
        this.$worker.$off('place-order', this.placeOrderHandler);
    },
    computed: {
        ...mapGetters({
            wallets: 'getWallets'
        }),
        market_stats: function () {
            return this.estimateMarket;
        },
        rawBaseWallet: function () {
            if(this.$store.getters.getUser) {
                return this.$store.getters.getWallet(this.market.base_currency);
            }
        },
        rawQuoteWallet: function () {
            if(this.$store.getters.getUser) {
                return this.$store.getters.getWallet(this.market.quote_currency);
            }
        },
        baseWallet: function () {
            return this.decorateTradeWalletForDisplay(this.rawBaseWallet, this.market.base_precision);
        },
        quoteWallet: function () {
            return this.decorateTradeWalletForDisplay(this.rawQuoteWallet, this.market.quote_precision);
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
        estimatedMarketBuyQuantity() {
            return estimateBuyQuantity(this.bid.quantity, this.estimatePrice, this.fee, this.market.base_precision);
        },
        tradeMaxBuy: function () {
            const price = this.orderType === 'market' ? this.estimatePrice : this.bid.price;
            return estimateBuyQuantity(this.getEffectiveTradeBalance(this.quoteWallet), price, this.fee, this.market.base_precision);
        },
        tradeMaxSell: function () {

            let balance = this.getEffectiveTradeBalance(this.baseWallet);

            return math_formatter(balance, this.market.base_precision);

        },
        estimateBidFee: function () {
            if (this.orderType === 'market') return math_formatter(Number(this.bid.quantity || 0) * Number(this.fee || 0) / 100, 8);

            if(!this.bid.quantity || this.bid.quantity == 0) return 0;

            return math_formatter((this.fee / 100) * this.bid.total, 8);
        },
        estimateAskFee: function () {

            if(!this.ask.quantity || this.ask.quantity == 0) return 0;

            return math_formatter((this.orderType === 'market' ? this.ask.quantity * this.estimatePrice : this.ask.total) * (this.fee / 100), 8);
        },
    },
    methods: {
        fetchWallets() {
            if(!this.$page.props.user) return;
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        },
        toNumber(value) {
            if(value === null || value === undefined || value === '') {
                return 0;
            }

            let number = parseFloat(String(value).replace(/,/g, ''));

            if(isNaN(number) || !isFinite(number)) {
                return 0;
            }

            return number;
        },
        normalizeSliderPercentage(percentage) {
            let percent = this.toNumber(percentage);

            if(percent >= 100) {
                return 99.99;
            }

            if(percent < 0) {
                return 0;
            }

            return percent;
        },
        getRealTradeBalance(wallet) {
            if(!wallet) return 0;
            return this.toNumber(wallet.real_balance_in_trade !== undefined ? wallet.real_balance_in_trade : wallet.balance_in_trade);
        },
        getVirtualTradeBalance(wallet) {
            if(!wallet) return 0;
            return this.toNumber(wallet.balance_in_virtual_trade || 0);
        },
        getVirtualBalance(wallet) {
            if(!wallet) return 0;

            /*
             * 跟 PC 端一致：现货只读取交易账户虚拟余额。
             * 真实账户：balance_in_trade
             * 虚拟账户：balance_in_virtual_trade
             * 不读取 balance_in_virtual_wallet。
             */
            return this.getVirtualTradeBalance(wallet);
        },
        getVirtualBalanceSource(wallet) {
            if(!wallet) return null;

            return this.getVirtualTradeBalance(wallet) > 0 ? legacyText("trade") : null;
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
        decorateTradeWalletForDisplay(wallet, precision = 8) {
            if(!wallet) {
                return wallet;
            }

            const displayWallet = {
                ...wallet,
                real_balance_in_trade: wallet.balance_in_trade,
                real_balance_in_trade_usd: wallet.balance_in_trade_usd,
            };

            const virtualBalance = this.getVirtualTradeBalance(wallet);

            if(virtualBalance > 0) {
                displayWallet.balance_in_trade = math_formatter(virtualBalance, precision);

                if(wallet.balance_in_virtual_trade_usd !== undefined && wallet.balance_in_virtual_trade_usd !== null) {
                    displayWallet.balance_in_trade_usd = wallet.balance_in_virtual_trade_usd;
                }
            }

            return displayWallet;
        },
        placeBuyOrder() {
            if (this.sessionBlocked || !this.canSubmitOrder('buy')) return;

            if(!this.bid.quantity || this.bid.quantity == 0) {
                this.buyErrorField = 'quantity';
            }

            if(!this.bid.price || this.bid.price == 0) {
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
                payload.quoteQuantity = this.bid.quantity;
            }

            if(payload.type == "stop_limit") {
                payload.trigger_condition = 'down'; //this.bid.trigger_condition;
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
                this.$store.dispatch('fetchOpenOrders', { market: this.market.name, route: this.route('orders.api.open') });

                this.fetchWallets();
                this.clearForm();
            }).catch(error => {
                this.placingBuyOrder = false;
                this.showOrderFailure(error, 'buy', true);
            });
        },
        placeSellOrder() {
            if (this.sessionBlocked || !this.canSubmitOrder('sell')) return;

            if(!this.ask.quantity || this.ask.quantity == 0) {
                this.sellErrorField = 'quantity';
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

            if(payload.type == "market") {
                payload.quoteQuantity = this.ask.quantity;
            }

            if(payload.type == "stop_limit") {
                payload.trigger_condition = 'down'; //this.ask.trigger_condition;
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
                this.$store.dispatch('fetchOpenOrders', { market: this.market.name, route: this.route('orders.api.open') });

                this.fetchWallets();
                this.clearForm();
            }).catch(error => {
                this.placingSellOrder = false;
                this.showOrderFailure(error, 'sell', true);
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

            return math_formatter((this.fee / 100) * size, 8);
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
                amount = math_formatter((parseFloat(num1) * parseFloat(num2)) + (((this.fee / 100) * num2) * num1), precision);
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

                return
            };

            let amount = math_formatter((parseFloat(num1) / parseFloat(num2)) - (this.calculateFee(num1) / num2), precision);

            if(side == "ask") {
                this.ask[field] = amount;
            } else {
                this.bid[field] = amount;
            }
        },
        changeBuySlider(percentage) {

            let safePercentage = this.normalizeSliderPercentage(percentage);
            let balance = this.getEffectiveTradeBalance(this.quoteWallet);

            let amountWithPercentage = math_percentage(balance, safePercentage);

            if(this.orderType == 'market') {
                this.bid.quantity = math_formatter(amountWithPercentage, this.market.quote_precision);
            } else {

                if(safePercentage > 0) {
                    this.bid.total = math_formatter(amountWithPercentage, this.market.quote_precision);

                } else {
                    this.bid.total = 0;
                    this.bid.quantity = 0;
                }
                this.divider(this.bid.total, this.bid.price, 'bid', 'quantity', this.market.base_precision)
            }
        },
        changeSellSlider(percentage) {

            let safePercentage = this.normalizeSliderPercentage(percentage);
            let balance = this.getEffectiveTradeBalance(this.baseWallet);

            this.ask.quantity = math_formatter(math_percentage(balance, safePercentage), this.market.base_precision);
        },
        clearInput ($event, side, field) {
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
            if (firstDotIndex !== -1) {
                this.setFormData(side, field,
                    this.getFormData(side, field).slice(0, firstDotIndex + 1) +
                    this.getFormData(side, field).slice(firstDotIndex + 1).replace(/\./g, '')
                );
            }

            if (/^0+\.\d+/.test(this.getFormData(side, field))) {
                this.setFormData(side, field, this.getFormData(side, field).replace(/^0+/, '0'));
            }

            if (/^0+\d+/.test(this.getFormData(side, field))) {
                this.setFormData(side, field, this.getFormData(side, field).replace(/^0+/, ''));
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
            this.ask.quantity = 0;

            this.buySlider.value = 0;
            this.sellSlider.value = 0;
        },
        setLeverage(value) {
            this.leverage = value;
        }
    },
    watch: {
        orderType: function(type) {
            if(type == 'market') {
                //this.bid.price = '';
                //this.ask.price = '';
            }
        },
        'ask.quantity'(newVal){

            let balance = this.getEffectiveTradeBalance(this.baseWallet);

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            this.multiplier(this.ask.price, this.ask.quantity, 'ask', 'total', this.market.quote_precision)

            if (newVal.includes('.')) {
                this.ask.quantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.base_precision)
            }

            if(parseFloat(this.ask.quantity) > parseFloat(balance)) {

                this.sellErrorField = "quantity";
                this.balanceSellError = true;
            } else {
                this.sellErrorField = null;
                this.balanceSellError = false;
            }
        },
        'ask.price'(newVal){

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            this.multiplier(this.ask.price, this.ask.quantity, 'ask', 'total', this.market.quote_precision)

            if (newVal.includes('.')) {
                this.ask.price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }

            if(parseFloat(newVal) > 0 && this.sellErrorField == 'price') {
                this.sellErrorField = '';
            }
        },
        'bid.quantity'(newVal){

            let balance = this.getEffectiveTradeBalance(this.quoteWallet);

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            this.multiplier(this.bid.price, this.bid.quantity, 'bid', 'total', this.market.quote_precision)

            if (newVal.includes('.')) {
                this.bid.quantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.orderType === 'market' ? this.market.quote_precision : this.market.base_precision)
            }


            if(this.orderType !== 'market') {
                if (parseFloat(this.bid.quantity) > parseFloat(this.tradeMaxBuy) && this.bid.price) {
                    this.buyErrorField = "quantity";
                    this.balanceBuyError = true;
                } else {
                    this.buyErrorField = null;
                    this.balanceBuyError = false;
                }
            }
            if(this.orderType === 'market') {
                if (parseFloat(this.bid.quantity) > parseFloat(balance)) {
                    this.buyErrorField = "quantity";
                    this.balanceBuyError = true;
                } else {
                    this.buyErrorField = null;
                    this.balanceBuyError = false;
                }
            }
        },
        'bid.price'(newVal){

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            this.multiplier(this.bid.price, this.bid.quantity, 'bid', 'total', this.market.quote_precision)

            if (newVal.includes('.')) {
                this.bid.price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }

            if(parseFloat(newVal) > 0 && this.buyErrorField == 'price') {
                this.buyErrorField = '';
            }
        },
    }
})
</script>
