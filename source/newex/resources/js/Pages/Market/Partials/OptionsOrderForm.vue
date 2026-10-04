<script>
import {requestIntent, completeIntent} from '@/Functions/RequestIntent.mjs';
import {displayDecimal, optionsLimitsReady} from '@/Functions/UserDisplay.mjs';
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Market/Partials/OptionsOrderForm.template'
import TextInput from "@/Jetstream/TextInput";
import SelectInput from "@/Jetstream/SelectInput";
import {mapGetters} from "vuex";
import VueSlider from 'vue-slider-component'
import '@/../css/progress-slider/default.css'
import {math_formatter, math_percentage} from "@/Functions/Math";

export default Template({
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
            balance: 0,
            orderType: 'limit',
            balanceError: false,

            bid: {
                type: 1,
                quantity: '',
                side: 'buy',
            },

            ask: {
                type: 1,
                quantity: '',
                side: 'sell',
            },

            buySlider: {
                min: 0,
                max: 100,
                interval: 0.01,
                value: 1,
                disabled: false,
            },

            sellSlider: {
                min: 0,
                max: 100,
                interval: 0.01,
                value: 1,
                disabled: false,
            },

            errors: null,
            placingBuyOrder: false,
            placingSellOrder: false,
            buyErrorField: false,
            sellErrorField: false,
            activeTab: 'buy',

            /*
             * Timeline support
             */
            timeframeSeconds: 60,
            timelineSlots: [],
            selectedTimelineStart: null,

            /*
             * 后端服务器时间支持
             */
            serverTimeOffset: 0,
            serverTimezone: null,

            walletRefreshTimer: null,
            timelineRefreshTimer: null,
        }
    },

    mounted() {
        this.initServerClock();

        if (this.$page.props.user) {
            this.buySlider.disabled = false;
            this.sellSlider.disabled = false;

            this.fetchWallets();

            this.walletRefreshTimer = setInterval(() => {
                this.fetchWallets();
            }, 10000);
        }

        if (this.$page.props.user && this.quoteWallet) {
            this.changeBuySlider(this.buySlider.value || 1);
        }

        this.timeframeSeconds = this.typeToSeconds(this.bid.type);
        this.rebuildTimelines();

        /*
         * 每秒刷新时间段，避免页面打开太久后 selectedTimelineStart 过期。
         */
        this.timelineRefreshTimer = setInterval(() => {
            this.rebuildTimelines();
        }, 1000);
    },

    beforeDestroy() {
        if (this.walletRefreshTimer) {
            clearInterval(this.walletRefreshTimer);
            this.walletRefreshTimer = null;
        }

        if (this.timelineRefreshTimer) {
            clearInterval(this.timelineRefreshTimer);
            this.timelineRefreshTimer = null;
        }
    },

    computed: {
        ...mapGetters({
            wallets: 'getWallets'
        }),

        market_stats: function () {
            return this.$store.getters.getMarket(this.market.name) ?? this.market;
        },

        quoteWallet: function () {
            if (this.$store.getters.getUser) {
                return this.$store.getters.getWallet(this.market.quote_currency);
            }

            return null;
        },

        availableQuoteBalance() {
            return this.getAvailableOptionsBalance(this.quoteWallet);
        },

        formattedAvailableQuoteBalance() {
            return this.formatBalance(this.availableQuoteBalance, this.market.quote_precision || 8);
        },

        useVirtualOptionsBalance() {
            return this.getVirtualOptionsBalance(this.quoteWallet) > 0;
        },

        virtualOptionsBalanceSource() {
            return this.getVirtualOptionsBalanceSource(this.quoteWallet);
        },

        estimatedReturn: function () {
            let field = this.ask.quantity;
            let type = this.ask.type;
            let expectedReturn = 0;

            if (this.activeTab == "buy") {
                field = this.bid.quantity;
                type = this.bid.type;
            }

            if (field) {
                let percentage = 0;

                if (type == 1) {
                    percentage = 59;
                } else if (type == 2) {
                    percentage = 59;
                } else if (type == 3) {
                    percentage = 59;
                } else if (type == 4) {
                    percentage = 59;
                } else if (type == 5) {
                    percentage = 59;
                }

                expectedReturn = parseFloat(field) + math_percentage(field, percentage);
            }

            return expectedReturn;
        },

        limitsReady() { return optionsLimitsReady(this.market.opt_min, this.market.opt_max); },
        minPositionSize: function () {
            return this.market.opt_min == null ? this.$t("Not configured") : Number(this.market.opt_min) === 0 ? this.$t("Positive amount required") : displayDecimal(this.market.opt_min);
        },

        maxPositionSize: function () {
            return this.market.opt_max == null ? this.$t("Not configured") : Number(this.market.opt_max) === 0 ? this.$t("No limit") : displayDecimal(this.market.opt_max);
        },
    },

    methods: {
        initServerClock() {
            const serverTimestamp = Number(
                this.$page.props.serverTimestamp ||
                this.$page.props.server_timestamp ||
                0
            );

            if (Number.isFinite(serverTimestamp) && serverTimestamp > 0) {
                this.serverTimeOffset = serverTimestamp - Date.now();
            } else {
                this.serverTimeOffset = 0;
            }

            this.serverTimezone = this.$page.props.serverTimezone ||
                this.$page.props.server_timezone ||
                null;
        },

        fetchWallets() {
            if (!this.$page.props.user) {
                return;
            }

            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        formatBalance(value, precision = 8) {
            const number = this.toNumber(value);

            if (number <= 0) {
                return '0';
            }

            return math_formatter(number, precision);
        },

        getVirtualOptionsBalance(wallet) {
            if (!wallet) {
                return 0;
            }

            /*
             * 期权余额只读取交易账户：
             * 真实账户：balance_in_trade
             * 虚拟账户：balance_in_virtual_trade
             * 不再读取 balance_in_virtual_wallet。
             */
            return this.toNumber(wallet.balance_in_virtual_trade);
        },

        getVirtualOptionsBalanceSource(wallet) {
            if (!wallet) {
                return null;
            }

            return this.toNumber(wallet.balance_in_virtual_trade) > 0 ? legacyText("trade") : null;
        },

        getAvailableOptionsBalance(wallet) {
            if (!wallet) {
                return 0;
            }

            const virtualBalance = this.getVirtualOptionsBalance(wallet);

            if (virtualBalance > 0) {
                return virtualBalance;
            }

            return this.toNumber(wallet.balance_in_trade);
        },

        typeToSeconds(id) {
            switch (Number(id)) {
                case 1:
                    return 60;
                case 2:
                    return 120;
                case 3:
                    return 180;
                case 4:
                    return 300;
                case 5:
                    return 600;
                default:
                    return 60;
            }
        },

        /*
         * 使用后端服务器时间，不再使用浏览器本地时间。
         */
        serverNow() {
            return new Date(Date.now() + this.serverTimeOffset);
        },

        alignToTimeframe(date, seconds) {
            const t = Math.ceil(date.getTime() / 1000);
            const aligned = Math.ceil(t / seconds) * seconds;

            return aligned * 1000;
        },

        formatServerTime(milliseconds) {
            const date = new Date(milliseconds);

            if (this.serverTimezone && typeof Intl !== 'undefined') {
                try {
                    return new Intl.DateTimeFormat('en-GB', {
                        timeZone: this.serverTimezone,
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false,
                    }).format(date);
                } catch (e) {
                    //
                }
            }

            const pad = (n) => n.toString().padStart(2, '0');

            return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
        },

        rebuildTimelines() {
            const seconds = this.timeframeSeconds || 60;
            const now = this.serverNow();
            const startMs = this.alignToTimeframe(now, seconds);
            const slots = [];

            for (let i = 0; i < 10; i++) {
                const s = startMs + i * seconds * 1000;
                const e = s + seconds * 1000;

                slots.push({
                    start: s,
                    end: e,
                    label: `${this.formatServerTime(s)} - ${this.formatServerTime(e)}`
                });
            }

            this.timelineSlots = slots;

            /*
             * 当前选择为空，或已过期，就自动选第一个后端时间段。
             */
            if (!this.selectedTimelineStart || this.selectedTimelineStart < now.getTime()) {
                this.selectedTimelineStart = slots[0].start;
            }
        },

        onTimelineChange() {
            this.explicitTimelineStart=this.selectedTimelineStart;
        },

        scheduleOrSend(side, formRoute, payload) {
            const scope='options:'+this.$page.props.user.id+':'+side;
            const semantic={...payload};
            delete semantic.balance; delete semantic.total;
            // Auto-selected time advancing is not a new user intent after an uncertain response.
            if(payload.startAt)semantic.startAt=this.explicitTimelineStart||'automatic';
            const intent=requestIntent(scope,semantic,payload);
            return axios.post(formRoute,{...intent.payload,client_order_id:intent.key},{timeout:20000})
                .then(response=>{completeIntent(scope,intent.key);return response})
                .catch(error=>{
                    if(!error.response)this.$toast.error(this.$i18n.locale.startsWith('zh')?'订单结果尚未确认，请查看订单记录；重试将沿用本次请求。':'Order outcome is not confirmed. Check order history; retry will reuse this request.');
                    throw error;
                });
        },
        placeBuyOrder(side) {
            if (!this.limitsReady) return this.$toast.error(this.$t("Position limits are not configured. Orders are temporarily unavailable."));
            if (!this.bid.quantity || this.bid.quantity == 0) {
                this.buyErrorField = 'quantity';
            }

            if (this.buyErrorField) {
                return;
            }

            if (this.placingBuyOrder) {
                return;
            }

            this.buyErrorField = null;
            this.sellErrorField = null;
            this.placingBuyOrder = true;

            if (!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            this.bid.side = side;
            this.bid.market = this.market.name;

            this.timeframeSeconds = this.typeToSeconds(this.bid.type);
            this.rebuildTimelines();

            const payload = {
                ...this.bid,
                timeframeSeconds: this.timeframeSeconds,
                account_type: this.useVirtualOptionsBalance ? 'virtual' : 'real',
                use_virtual_wallet: this.useVirtualOptionsBalance,
                virtual_balance_source: this.virtualOptionsBalanceSource,
            };

            /*
             * 现在这个 startAt 已经是以后端服务器时间偏移计算出来的时间戳。
             */
            if (this.selectedTimelineStart) {
                payload.startAt = this.selectedTimelineStart;
            }

            let formRoute = this.route('options.store');

            this.scheduleOrSend(side, formRoute, payload).then(() => {
                this.placingBuyOrder = false;

                if (side == "buy") {
                    this.$toast.success(this.$t("Long Position was opened"));
                } else {
                    this.$toast.success(this.$t("Short Position was opened"));
                }

                this.$store.dispatch('fetchOpenOrders', {
                    market: this.market.name,
                    route: this.route('orders.api.open')
                });

                this.fetchWallets();

                this.bid.quantity = null;
            }).catch(error => {
                this.placingBuyOrder = false;

                if (error && error.response && error.response.data && error.response.data.errors) {
                    _.each(error.response.data.errors, (field, key) => {
                        if (key !== 'startAt') {
                            this.buyErrorField = key;
                        }

                        this.$toast.error(field[0]);
                    });
                }

                if (error && error.response && error.response.data && error.response.data.message) {
                    this.$toast.error(error.response.data.message);
                }
            });
        },

        decimal_format(value, decimal) {
            return math_formatter(value, decimal);
        },

        normalizeSliderPercentage(percentage) {
            let number = this.toNumber(percentage);

            if (number < 0) {
                number = 0;
            }

            if (number > 100) {
                number = 100;
            }

            return number;
        },

        getSliderCalculationPercentage(percentage) {
            const number = this.normalizeSliderPercentage(percentage);

            /*
             * 页面拉满显示 100%，但实际计算使用 99.99%，避免满仓计算时因为精度或手续费导致余额不足。
             */
            if (number >= 100) {
                return 99.99;
            }

            return number;
        },

        formatSliderTooltip(value) {
            const number = this.normalizeSliderPercentage(value);

            if (number >= 100) {
                return '100%';
            }

            const formatted = number
                .toFixed(2)
                .replace(/\.00$/, '')
                .replace(/(\.\d)0$/, '$1');

            return `${formatted}%`;
        },

        changeBuySlider(percentage) {
            let balance = this.availableQuoteBalance;
            let calculationPercentage = this.getSliderCalculationPercentage(percentage);
            let amountWithPercentage = math_percentage(balance, calculationPercentage);

            this.bid.quantity = math_formatter(amountWithPercentage, this.market.quote_precision || 8);
        },

        changeSellSlider(percentage) {
            let balance = this.availableQuoteBalance;
            let calculationPercentage = this.getSliderCalculationPercentage(percentage);

            this.ask.quantity = math_formatter(
                math_percentage(balance, calculationPercentage),
                this.market.quote_precision || 8
            );
        },

        clearInput($event, side, field) {
            if (this.getFormData(side, field).charAt(0) == '.') {
                if (field == 'balance') {
                    this.balance = 0;
                    return;
                }

                this.setFormData(side, field, 0);
                return;
            }

            this.setFormData(side, field, this.getFormData(side, field).replace(/[^0-9.]/g, ''));

            const firstDotIndex = this.getFormData(side, field).indexOf('.');

            if (firstDotIndex !== -1) {
                this.setFormData(
                    side,
                    field,
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
            if (field == 'balance') {
                return this.balance.toString();
            }

            if (side == 'buy') {
                return this.bid[field].toString();
            }

            return this.ask[field].toString();
        },

        setFormData(side, field, value) {
            if (side == 'buy') {
                this.bid[field] = value;
                return;
            }

            this.ask[field] = value;
        },

        setTab(side) {
            this.openForm = true;
            this.activeTab = side;
        },
    },

    watch: {
        'bid.quantity'(newVal) {
            let balance = this.availableQuoteBalance;

            newVal = (newVal ?? '').toString();

            if (newVal.includes('.')) {
                this.bid.quantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }

            if (this.toNumber(this.bid.quantity) > this.toNumber(balance)) {
                this.buyErrorField = "quantity";
                this.balanceError = true;
            } else {
                this.buyErrorField = null;
                this.balanceError = false;
            }
        },

        quoteWallet(newVal) {
            if (this.$page.props.user && newVal && (this.bid.quantity === '' || this.bid.quantity === null)) {
                this.changeBuySlider(this.buySlider.value || 1);
            }
        },

        availableQuoteBalance() {
            if (this.$page.props.user && (this.bid.quantity === '' || this.bid.quantity === null)) {
                this.changeBuySlider(this.buySlider.value || 1);
            }
        },

        'bid.type'(newVal) {
            this.timeframeSeconds = this.typeToSeconds(newVal);
            this.rebuildTimelines();
        },
    }
})
</script>