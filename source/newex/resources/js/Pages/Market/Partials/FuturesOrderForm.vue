<script>
import {requestIntent, completeIntent} from '@/Functions/RequestIntent.mjs';
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Market/Partials/FuturesOrderForm.template'
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
        fee: String,
        futuresTimeframeEnabled: { type: Boolean, default: false },
        maxLeverage: { type: [Number, String], default: null },
        userVipLevel: { type: [Number, String], default: null },
    },
    data() {
        return {
            balanceBuyError: false,
            balanceSellError: false,
            openForm: false,
            leverage: 1,
            balance: 0,
            orderType: 'limit',
            bid: {
                price: 0,
                quantity: 0,
                type: 'limit',
                side: 'buy',
                trigger_price: 0,
                total: 0,
                enable_tp_sl: false,
                take_profit_price: 0,
                stop_loss_price: 0,
            },
            ask: {
                price: 0,
                quantity: 0,
                type: 'limit',
                side: 'sell',
                trigger_price: 0,
                total: 0,
                enable_tp_sl: false,
                take_profit_price: 0,
                stop_loss_price: 0,
            },
            leverageSlider: {
                min: 1,
                max: 125,
                interval: 1,
                value: 1,
                formatter: '{value}%',
                disabled: false,
                adsorb: false,
                marks: [1, 125]
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
            timeframeSeconds: 60,
            timelineSlots: [],
            selectedTimelineStart: null,

            walletRefreshTimer: null,
            placeOrderHandler: null,
            futuresWalletRefreshHandler: null,
            walletContextLoaded: false,
            walletContextLoading: false,
            walletRequestPromise: null,
            futuresWallets: [],

            showLeverageQuizModal: false,
            pendingLeverageOrderSide: null,
            leverageQuizSelected: '',
            leverageQuizError: '',
            leverageQuizCurrentIndex: 0,
            leverageQuizAnswers: [],
            leverageQuizQuestions: [
                {
                    question: 'What is the main risk of futures leverage trading?',
                    correctAnswer: 'liquidation',
                    options: [
                        { value: 'no_risk', label: legacyText("There is no risk when using leverage.") },
                        { value: 'liquidation', label: legacyText("The position may be liquidated and losses may increase quickly.") },
                        { value: 'fixed_profit', label: legacyText("The profit is fixed once the order is opened.") }
                    ]
                },
                {
                    question: 'What happens when leverage is increased?',
                    correctAnswer: 'profit_loss_amplified',
                    options: [
                        { value: 'profit_loss_amplified', label: legacyText("Both potential profit and potential loss can be amplified.") },
                        { value: 'profit_only', label: legacyText("Only potential profit increases, while losses stay unchanged.") },
                        { value: 'no_fee', label: legacyText("Trading fees are automatically removed.") }
                    ]
                },
                {
                    question: 'Can a stop loss fully guarantee that losses will not exceed the expected amount?',
                    correctAnswer: 'not_guaranteed',
                    options: [
                        { value: 'guaranteed_price', label: legacyText("Yes, a stop loss always guarantees the exact exit price.") },
                        { value: 'not_guaranteed', label: legacyText("No, extreme volatility or market gaps may still cause larger losses.") },
                        { value: 'no_margin', label: legacyText("Yes, because margin is not required after setting stop loss.") }
                    ]
                },
                {
                    question: 'What may trigger forced liquidation?',
                    correctAnswer: 'insufficient_margin',
                    options: [
                        { value: 'insufficient_margin', label: legacyText("Insufficient margin when the market moves against the position.") },
                        { value: 'profitable', label: legacyText("The position becomes profitable.") },
                        { value: 'one_x_only', label: legacyText("Only using 1x leverage can trigger liquidation.") }
                    ]
                },
                {
                    question: 'How should users manage futures position size?',
                    correctAnswer: 'risk_tolerance',
                    options: [
                        { value: 'all_balance', label: legacyText("Use all available balance to maximize profit.") },
                        { value: 'risk_tolerance', label: legacyText("Only use funds and position sizes within personal risk tolerance.") },
                        { value: 'ignore_volatility', label: legacyText("Ignore volatility if the market looks stable.") }
                    ]
                },
                {
                    question: 'What should be checked before opening a futures position?',
                    correctAnswer: 'check_all_risks',
                    options: [
                        { value: 'check_all_risks', label: legacyText("Leverage, margin, fees, liquidation rules, TP/SL and market risk.") },
                        { value: 'pair_only', label: legacyText("Only the trading pair name.") },
                        { value: 'color_only', label: legacyText("Only whether the price is green or red.") }
                    ]
                },
                {
                    question: 'What can happen during high market volatility?',
                    correctAnswer: 'fast_liquidation_risk',
                    options: [
                        { value: 'fast_liquidation_risk', label: legacyText("Price may move quickly and increase liquidation risk.") },
                        { value: 'risk_disappears', label: legacyText("Liquidation risk disappears.") },
                        { value: 'fees_waived', label: legacyText("Trading fees are waived automatically.") }
                    ]
                },
                {
                    question: 'What does margin represent in a futures position?',
                    correctAnswer: 'collateral',
                    options: [
                        { value: 'bonus', label: legacyText("A bonus provided by the platform.") },
                        { value: 'collateral', label: legacyText("Collateral used to support the leveraged position.") },
                        { value: 'guaranteed_income', label: legacyText("Guaranteed income after opening the position.") }
                    ]
                },
                {
                    question: 'If the market moves opposite to your position, what should you understand?',
                    correctAnswer: 'losses_grow_quickly',
                    options: [
                        { value: 'losses_grow_quickly', label: legacyText("Losses can grow quickly, especially when leverage is high.") },
                        { value: 'platform_absorbs', label: legacyText("The system will absorb all losses for the user.") },
                        { value: 'never_closed', label: legacyText("The position can never be closed.") }
                    ]
                },
                {
                    question: 'What is the safest attitude toward futures trading?',
                    correctAnswer: 'understand_and_cautious',
                    options: [
                        { value: 'understand_and_cautious', label: legacyText("Understand the risks first and trade cautiously.") },
                        { value: 'guaranteed_profit', label: legacyText("Assume higher leverage guarantees higher profit.") },
                        { value: 'increase_after_loss', label: legacyText("Always increase leverage after a loss.") }
                    ]
                }
            ],
        }
    },

    created() {
        /**
         * 根据用户 VIP 等级限制最大杠杆。
         */
        this.syncLeverageLimit(false);

        /**
         * 合约页面不要先使用普通钱包余额。
         * 进入页面时先把钱包上下文锁定为 futures，等 /wallets?context=futures 返回后再显示余额。
         */
        if (this.$page.props.user) {
            this.fetchWallets(true).catch(() => {});
        }
    },

    mounted() {
        this.syncLeverageLimit(false);

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

                let balance = this.quoteWallet ? this.getAvailableFuturesBalance(this.quoteWallet) : 0;

                if(parseFloat(balance) < parseFloat(data.order.quantity)) {
                    this.ask.quantity = this.decimal_format(balance, this.market.base_precision);
                } else {
                    this.ask.quantity = this.decimal_format(data.order.quantity, this.market.base_precision);
                }

            }

            setTimeout(() => {
                this.changeBuySlider()
            }, 3000);
        };

        this.$worker.$on('place-order', this.placeOrderHandler);

        this.futuresWalletRefreshHandler = () => {
            this.refreshFuturesWalletsAfterOrder();
        };

        this.$worker.$on('refresh-futures-wallets', this.futuresWalletRefreshHandler);

        if(this.$page.props.user) {
            this.buySlider.disabled = false;
            this.sellSlider.disabled = false;

            // mounted 后再补一次，避免页面切换时父页面普通余额覆盖。
            this.fetchWallets(true).catch(() => {});

            // 定时兜底刷新即可，开仓/平仓会走事件立即刷新。
            this.walletRefreshTimer = setInterval(() => {
                if (document.hidden) {
                    return;
                }

                this.fetchWallets(true).catch(() => {});
            }, 30000);
        }

        // Default to market, but allow limit orders
        this.orderType = 'market';

        this.bid.price = this.market_stats.last;
        this.ask.price = this.market_stats.last;

        // Build initial timeline slots only when enable
        if (this.futuresTimeframeEnabled) {
            this.rebuildTimelines();
        }
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

        if(this.futuresWalletRefreshHandler) {
            this.$worker.$off('refresh-futures-wallets', this.futuresWalletRefreshHandler);
            this.futuresWalletRefreshHandler = null;
        }
    },

    computed: {
        ...mapGetters({
            wallets: 'getWallets'
        }),
        market_stats: function () {
            return this.$store.getters.getMarket(this.market.name) ?? this.market;
        },
        effectiveMaxLeverage: function () {
            let max = parseInt(
                this.maxLeverage ||
                this.$page?.props?.maxLeverage ||
                this.$page?.props?.futuresMaxLeverage ||
                5
            );

            if (!Number.isFinite(max) || max <= 0) {
                max = 5;
            }

            if (max <= 5) {
                return 5;
            }

            if (max <= 25) {
                return 25;
            }

            if (max <= 50) {
                return 50;
            }

            if (max <= 75) {
                return 75;
            }

            return 125;
        },
        currentUserVipLevel: function () {
            let level = parseInt(
                this.userVipLevel ||
                this.$page?.props?.userVipLevel ||
                this.$page?.props?.futuresUserVipLevel ||
                this.$page?.props?.user?.vip ||
                1
            );

            if (!Number.isFinite(level) || level <= 0) {
                level = 1;
            }

            return Math.min(level, 8);
        },
        baseWallet: function () {
            if(!this.walletContextLoaded) {
                return null;
            }

            return this.getFuturesWalletSnapshot(this.market.base_currency);
        },
        quoteWallet: function () {
            if(!this.walletContextLoaded) {
                return null;
            }

            return this.getFuturesWalletSnapshot(this.market.quote_currency);
        },
        tradeMaxBuy: function () {

            if(!this.bid.price || this.bid.price == 0) return 0;

            let balance = this.quoteWallet ? this.getAvailableFuturesBalance(this.quoteWallet) : 0;

            let availableAmount = balance / this.bid.price;

            let feeInAmount = ((this.fee / 100) * balance) / this.bid.price;

            return math_formatter(availableAmount - feeInAmount, this.market.base_precision);
        },
        estimateBidFee: function () {

            if(!this.bid.quantity || this.bid.quantity == 0) return 0;

            return math_formatter((this.fee / 100) * this.bid.total, 8);
        },
        estimateAskFee: function () {

            if(!this.ask.quantity || this.ask.quantity == 0) return 0;

            return math_formatter((this.ask.total) * (this.fee / 100), 8);
        },
        currentLeverageQuizQuestion: function () {
            return this.leverageQuizQuestions[this.leverageQuizCurrentIndex] || null;
        },
        leverageQuizProgressPercent: function () {
            if (!this.leverageQuizQuestions.length) {
                return 0;
            }

            return Math.round((this.leverageQuizCurrentIndex / this.leverageQuizQuestions.length) * 100);
        },
    },
    methods: {
        toNumber(value) {
            let number = parseFloat(value || 0);

            if(isNaN(number)) {
                return 0;
            }

            return number;
        },

        syncLeverageLimit(showToast = false) {
            const max = this.effectiveMaxLeverage;

            this.leverageSlider.max = max;
            this.leverageSlider.marks = [1, max];

            let current = parseInt(this.leverage || 1);

            if (!Number.isFinite(current) || current < 1) {
                current = 1;
            }

            if (current > max) {
                current = max;

                if (showToast) {
                    this.$toast.warning(this.$t('Your VIP level supports up to {max}x leverage.').replace('{max}', max));
                }
            }

            this.leverage = current;
            this.leverageSlider.value = current;
        },

        validateLeverageLimit() {
            const max = this.effectiveMaxLeverage;
            const current = parseInt(this.leverage || 1);

            if (current > max) {
                this.syncLeverageLimit(true);
                return false;
            }

            this.syncLeverageLimit(false);
            return true;
        },

        getFuturesVirtualBalance(wallet) {
            if(!wallet) {
                return 0;
            }

            /*
             * 合约余额只读取交易账户：
             * 真实账户：balance_in_trade
             * 虚拟账户：balance_in_virtual_trade
             * 不再读取 balance_in_virtual_wallet。
             */
            return this.toNumber(wallet.balance_in_virtual_trade);
        },

        getFuturesVirtualBalanceSource(wallet) {
            if(!wallet) {
                return null;
            }

            return this.toNumber(wallet.balance_in_virtual_trade) > 0 ? 'trade' : null;
        },

        getAvailableFuturesBalance(wallet) {
            if(!wallet) {
                return 0;
            }

            let virtualBalance = this.getFuturesVirtualBalance(wallet);

            if(virtualBalance > 0) {
                return virtualBalance;
            }

            return this.toNumber(wallet.balance_in_trade);
        },

        getDisplayFuturesBalance(wallet, precision) {
            return math_formatter(this.getAvailableFuturesBalance(wallet), precision);
        },

        getFuturesOrderAccountType(wallet) {
            return this.getFuturesVirtualBalance(wallet) > 0 ? 'virtual' : 'real';
        },

        fetchWallets(force = false) {
            if(!this.$page.props.user) {
                return Promise.resolve();
            }

            if (this.walletContextLoading && this.walletRequestPromise) {
                return this.walletRequestPromise;
            }

            const baseUrl = this.route('wallets.index');
            const separator = baseUrl.indexOf('?') === -1 ? '?' : '&';

            let url = baseUrl + separator + 'context=futures';

            if(force) {
                url += '&_t=' + Date.now();
            }

            this.walletContextLoading = true;

            /**
             * 这里不要再通过 Vuex 的 fetchWallets 取余额。
             *
             * 原因：下单后后端 WalletUpdated / 其他组件可能会把普通钱包余额写回 Vuex，
             * 导致合约页面先显示 futures 余额，然后被普通 balance_in_trade 覆盖成 0.1，
             * 等下一次 context=futures 请求回来又恢复。
             *
             * 这个页面的余额直接从 /wallets?context=futures 响应复制到本地 futuresWallets，
             * 页面显示、滑块、校验全部只读这个本地快照，不再被 Vuex 普通余额污染。
             */
            this.walletRequestPromise = axios.get(url).then((response) => {
                const wallets = this.extractWalletsFromResponse(response);

                this.futuresWallets = this.cloneWalletsSnapshot(wallets);
                this.walletContextLoaded = true;

                return response;
            }).catch((error) => {
                /**
                 * 请求失败时不要回退显示普通余额，避免合约页面展示错误余额。
                 */
                this.walletContextLoaded = false;
                throw error;
            }).finally(() => {
                this.walletContextLoading = false;
                this.walletRequestPromise = null;
            });

            return this.walletRequestPromise;
        },

        extractWalletsFromResponse(response) {
            const payload = response && response.data ? response.data : response;

            if(!payload) {
                return [];
            }

            /**
             * 兼容常见返回结构：
             * 1. [{...wallet}]
             * 2. {data: [{...wallet}]}
             * 3. {wallets: [{...wallet}]}
             * 4. {data: {wallets: [...]}}
             * 5. {USDT: {...wallet}}
             */
            if(Array.isArray(payload)) {
                return payload;
            }

            if(payload.wallets) {
                return payload.wallets;
            }

            if(payload.data) {
                if(Array.isArray(payload.data)) {
                    return payload.data;
                }

                if(payload.data.wallets) {
                    return payload.data.wallets;
                }

                return payload.data;
            }

            return payload;
        },

        cloneWalletsSnapshot(wallets) {
            if(!wallets) {
                return [];
            }

            try {
                return JSON.parse(JSON.stringify(wallets));
            } catch (e) {
                if(Array.isArray(wallets)) {
                    return wallets.slice();
                }

                return Object.assign({}, wallets);
            }
        },

        getFuturesWalletSnapshot(symbol) {
            if(!symbol || !this.futuresWallets) {
                return null;
            }

            const wallets = this.futuresWallets;

            if(!Array.isArray(wallets) && typeof wallets === 'object') {
                if(wallets[symbol]) {
                    return wallets[symbol];
                }

                const values = Object.values(wallets);
                return values.find((wallet) => this.isWalletSymbol(wallet, symbol)) || null;
            }

            if(Array.isArray(wallets)) {
                return wallets.find((wallet) => this.isWalletSymbol(wallet, symbol)) || null;
            }

            return null;
        },

        isWalletSymbol(wallet, symbol) {
            if(!wallet) {
                return false;
            }

            return wallet.symbol === symbol ||
                wallet.currency === symbol ||
                wallet.currency_symbol === symbol ||
                wallet.currencySymbol === symbol ||
                (wallet.currency && wallet.currency.symbol === symbol) ||
                (wallet.currency && wallet.currency.code === symbol);
        },

        refreshFuturesWalletsAfterOrder() {
            this.fetchWallets(true).catch(() => {});

            setTimeout(() => {
                this.fetchWallets(true).catch(() => {});
            }, 600);
        },

        getLeverageQuizStorageKey() {
            const user = this.$page && this.$page.props ? this.$page.props.user : null;
            const userId = user ? (user.id || user.uid || user.email || user.phone || 'user') : 'guest';

            return `Deepro:futures-risk-quiz:v1:${userId}`;
        },

        hasPassedLeverageQuiz() {
            if (typeof window === 'undefined' || !window.localStorage) {
                return false;
            }

            return window.localStorage.getItem(this.getLeverageQuizStorageKey()) === 'passed';
        },

        markLeverageQuizPassed() {
            if (typeof window === 'undefined' || !window.localStorage) {
                return;
            }

            window.localStorage.setItem(this.getLeverageQuizStorageKey(), 'passed');
        },

        shouldAskLeverageQuiz(side) {
            const leverage = Number(this.leverage || 1);

            /*
             * 只有 5 倍以上杠杆才需要风险问答。
             * 1x 到 5x 都不需要答题。
             */
            if (!Number.isFinite(leverage) || leverage <= 5) {
                return false;
            }

            if (this.hasPassedLeverageQuiz()) {
                return false;
            }

            this.pendingLeverageOrderSide = side;
            this.leverageQuizCurrentIndex = 0;
            this.leverageQuizSelected = '';
            this.leverageQuizError = '';
            this.leverageQuizAnswers = [];
            this.showLeverageQuizModal = true;

            return true;
        },

        cancelLeverageQuiz() {
            this.showLeverageQuizModal = false;
            this.pendingLeverageOrderSide = null;
            this.leverageQuizCurrentIndex = 0;
            this.leverageQuizSelected = '';
            this.leverageQuizError = '';
            this.leverageQuizAnswers = [];
        },

        resetLeverageQuizToStart() {
            this.leverageQuizCurrentIndex = 0;
            this.leverageQuizSelected = '';
            this.leverageQuizError = '';
            this.leverageQuizAnswers = [];
        },

        confirmLeverageQuiz() {
            const question = this.currentLeverageQuizQuestion;

            /*
             * 不提示错误。
             * 没有题目或没有选择时直接停止，不弹 toast，不显示错误文案。
             */
            if (!question || !this.leverageQuizSelected) {
                this.leverageQuizError = '';
                return;
            }

            this.$set(this.leverageQuizAnswers, this.leverageQuizCurrentIndex, this.leverageQuizSelected);
            this.leverageQuizError = '';

            if (this.leverageQuizCurrentIndex < this.leverageQuizQuestions.length - 1) {
                this.leverageQuizCurrentIndex++;
                this.leverageQuizSelected = this.leverageQuizAnswers[this.leverageQuizCurrentIndex] || '';
                this.leverageQuizError = '';
                return;
            }

            const allPassed = this.leverageQuizQuestions.every((item, index) => {
                return this.leverageQuizAnswers[index] === item.correctAnswer;
            });

            /*
             * 10 题全部答完后统一校验。
             * 只要有一题错误，不提示错误，直接回到第 1 题重新答。
             */
            if (!allPassed) {
                this.resetLeverageQuizToStart();
                return;
            }

            const side = this.pendingLeverageOrderSide;

            this.markLeverageQuizPassed();
            this.showLeverageQuizModal = false;
            this.pendingLeverageOrderSide = null;
            this.leverageQuizCurrentIndex = 0;
            this.leverageQuizSelected = '';
            this.leverageQuizError = '';
            this.leverageQuizAnswers = [];

            this.$toast.success(this.$t('Risk quiz passed'));

            this.$nextTick(() => {
                if (side === 'buy') {
                    this.placeBuyOrder();
                    return;
                }

                if (side === 'sell') {
                    this.placeSellOrder();
                }
            });
        },

        serverNow() {
            // Use client time; could be enhanced to sync with server
            return new Date();
        },
        alignToTimeframe(date, seconds) {
            const t = Math.ceil(date.getTime() / 1000);
            const aligned = Math.ceil(t / seconds) * seconds; // next boundary
            return aligned * 1000;
        },
        rebuildTimelines(side) {
            const seconds = this.timeframeSeconds || 60;
            const now = this.serverNow();
            const startMs = this.alignToTimeframe(now, seconds);
            const slots = [];
            for (let i = 0; i < 10; i++) {
                const s = startMs + i * seconds * 1000;
                const e = s + seconds * 1000;
                const sd = new Date(s);
                const ed = new Date(e);
                const pad = (n) => n.toString().padStart(2, '0');
                const label = `${pad(sd.getHours())}:${pad(sd.getMinutes())}:${pad(sd.getSeconds())} - ${pad(ed.getHours())}:${pad(ed.getMinutes())}:${pad(ed.getSeconds())}`;
                slots.push({ start: s, end: e, label });
            }
            this.timelineSlots = slots;
            if (!this.selectedTimelineStart || this.selectedTimelineStart < now.getTime()) {
                this.selectedTimelineStart = slots[0].start;
            }
        },
        onTimelineChange(side) {
            this.explicitTimelineStart=this.selectedTimelineStart;
        },
        scheduleOrSend(side, formRoute, payload) {
            const scope='futures:'+this.$page.props.user.id+':'+side;
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
        placeBuyOrder() {

            this.buyErrorField = false;

            // Ensure timeline is selected when feature enabled
            if (this.futuresTimeframeEnabled && !this.selectedTimelineStart) {
                this.rebuildTimelines('buy');
            }

            if(!this.bid.quantity || this.bid.quantity == 0) {
                this.buyErrorField = 'quantity';
            }

            if((!this.bid.price || this.bid.price == 0) && this.orderType != "market") {
                this.buyErrorField = 'price';
            }

            // Validate TP/SL if enabled
            if(this.bid.enable_tp_sl) {
                const entryPrice = this.orderType == "market" ? this.market_stats.last : this.bid.price;

                if(this.bid.take_profit_price && this.bid.take_profit_price > 0) {
                    // Long: TP must be above entry price
                    if(parseFloat(this.bid.take_profit_price) <= parseFloat(entryPrice)) {
                        this.buyErrorField = 'take_profit_price';
                        this.$toast.error(this.$t('Take Profit must be above entry price for Long positions'));
                        return;
                    }
                }

                if(this.bid.stop_loss_price && this.bid.stop_loss_price > 0) {
                    // Long: SL must be below entry price
                    if(parseFloat(this.bid.stop_loss_price) >= parseFloat(entryPrice)) {
                        this.buyErrorField = 'stop_loss_price';
                        this.$toast.error(this.$t('Stop Loss must be below entry price for Long positions'));
                        return;
                    }
                }

                // Validate TP > SL for Long
                if(this.bid.take_profit_price && this.bid.stop_loss_price &&
                   this.bid.take_profit_price > 0 && this.bid.stop_loss_price > 0) {
                    if(parseFloat(this.bid.take_profit_price) <= parseFloat(this.bid.stop_loss_price)) {
                        this.buyErrorField = 'take_profit_price';
                        this.$toast.error(this.$t('Take Profit must be above Stop Loss price'));
                        return;
                    }
                }
            }

            if(this.buyErrorField) return;

            if(this.placingBuyOrder) return;

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            if(!this.validateLeverageLimit()) {
                return;
            }

            if(this.shouldAskLeverageQuiz('buy')) {
                return;
            }

            this.buyErrorField = null;
            this.sellErrorField = null;
            this.placingBuyOrder = true;

            this.bid.market = this.market.name;
            this.bid.type = this.orderType;

            let formRoute = this.route('orders.store');

            this.bid.leverage = this.leverage;
            this.bid.balance = this.balance;
            formRoute = this.route('futures.store');

            // For market orders, use quoteQuantity (size in quote currency)
            // For limit orders, use quantity (size in base currency) and price
            if(this.bid.type == "market") {
                this.bid.quoteQuantity = this.bid.quantity;
                delete this.bid.price; // Market orders don't need price
            } else {
                // Limit order: use quantity and price
                delete this.bid.quoteQuantity;
            }
            if (this.futuresTimeframeEnabled) {
                this.bid.timeframeSeconds = this.timeframeSeconds;
            } else {
                delete this.bid.timeframeSeconds;
            }

            const payload = {...this.bid};
            payload.account_type = this.getFuturesOrderAccountType(this.quoteWallet);
            payload.use_virtual_wallet = payload.account_type === 'virtual';
            payload.virtual_balance_source = this.getFuturesVirtualBalanceSource(this.quoteWallet);

            if (this.futuresTimeframeEnabled && this.selectedTimelineStart) {
                payload.startAt = this.selectedTimelineStart; // ms timestamp
            }
            // Include TP/SL if enabled
            if (this.bid.enable_tp_sl) {
                payload.enable_tp_sl = true;
                if (this.bid.take_profit_price && this.bid.take_profit_price > 0) {
                    payload.take_profit_price = this.bid.take_profit_price;
                }
                if (this.bid.stop_loss_price && this.bid.stop_loss_price > 0) {
                    payload.stop_loss_price = this.bid.stop_loss_price;
                }
            }
            this.scheduleOrSend('buy', formRoute, payload).then(() => {
                this.placingBuyOrder = false;
                this.$toast.success(this.$t("Order Created"));

                if (this.$worker) {
                    this.$worker.$emit('refresh-futures-orders');
                }

                // 下单成功后强制刷新合约余额，避免普通 WalletUpdated 事件覆盖余额
                this.refreshFuturesWalletsAfterOrder();

                this.clearForm();
            }).catch(error => {
                this.placingBuyOrder = false;
                if (error && error.response && error.response.data && error.response.data.errors) {
                    _.each(error.response.data.errors, (field, key) => {

                        if(key == "quoteQuantity") key = "quantity";

                        this.buyErrorField = key;
                        this.$toast.error(field[0]);
                    });
                }
            });
        },
        placeSellOrder() {

            this.sellErrorField = false;

            if(!this.ask.quantity || this.ask.quantity == 0) {
                this.sellErrorField = 'quantity';
            }


            if((!this.ask.price || this.ask.price == 0) && this.orderType != "market") {
                this.sellErrorField = 'price';
            }

            // Validate TP/SL if enabled
            if(this.ask.enable_tp_sl) {
                const entryPrice = this.orderType == "market" ? this.market_stats.last : this.ask.price;

                if(this.ask.take_profit_price && this.ask.take_profit_price > 0) {
                    // Short: TP must be below entry price
                    if(parseFloat(this.ask.take_profit_price) >= parseFloat(entryPrice)) {
                        this.sellErrorField = 'take_profit_price';
                        this.$toast.error(this.$t('Take Profit must be below entry price for Short positions'));
                        return;
                    }
                }

                if(this.ask.stop_loss_price && this.ask.stop_loss_price > 0) {
                    // Short: SL must be above entry price
                    if(parseFloat(this.ask.stop_loss_price) <= parseFloat(entryPrice)) {
                        this.sellErrorField = 'stop_loss_price';
                        this.$toast.error(this.$t('Stop Loss must be above entry price for Short positions'));
                        return;
                    }
                }

                // Validate SL > TP for Short
                if(this.ask.take_profit_price && this.ask.stop_loss_price &&
                   this.ask.take_profit_price > 0 && this.ask.stop_loss_price > 0) {
                    if(parseFloat(this.ask.stop_loss_price) <= parseFloat(this.ask.take_profit_price)) {
                        this.sellErrorField = 'stop_loss_price';
                        this.$toast.error(this.$t('Stop Loss must be above Take Profit price'));
                        return;
                    }
                }
            }

            if(this.sellErrorField) return;

            if(this.placingSellOrder) return;

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            if(!this.validateLeverageLimit()) {
                return;
            }

            if(this.shouldAskLeverageQuiz('sell')) {
                return;
            }

            this.placingSellOrder = true;
            this.buyErrorField = null;
            this.sellErrorField = null;

            this.ask.market = this.market.name;
            this.ask.type = this.orderType;

            let formRoute = this.route('orders.store');

            this.ask.leverage = this.leverage;
            this.ask.balance = this.balance;
            formRoute = this.route('futures.store');

            // For market orders, use quoteQuantity (size in quote currency)
            // For limit orders, use quantity (size in base currency) and price
            if(this.ask.type == "market") {
                this.ask.quoteQuantity = this.ask.quantity;
                delete this.ask.price; // Market orders don't need price
            } else {
                // Limit order: use quantity and price
                delete this.ask.quoteQuantity;
            }

            if (this.futuresTimeframeEnabled) {
                this.ask.timeframeSeconds = this.timeframeSeconds;
            } else {
                delete this.ask.timeframeSeconds;
            }
            const payload = {...this.ask};
            payload.account_type = this.getFuturesOrderAccountType(this.quoteWallet);
            payload.use_virtual_wallet = payload.account_type === 'virtual';
            payload.virtual_balance_source = this.getFuturesVirtualBalanceSource(this.quoteWallet);

            if (this.futuresTimeframeEnabled && this.selectedTimelineStart) {
                payload.startAt = this.selectedTimelineStart; // ms timestamp
            }
            // Include TP/SL if enabled
            if (this.ask.enable_tp_sl) {
                payload.enable_tp_sl = true;
                if (this.ask.take_profit_price && this.ask.take_profit_price > 0) {
                    payload.take_profit_price = this.ask.take_profit_price;
                }
                if (this.ask.stop_loss_price && this.ask.stop_loss_price > 0) {
                    payload.stop_loss_price = this.ask.stop_loss_price;
                }
            }
            this.scheduleOrSend('sell', formRoute, payload).then(() => {
                this.placingSellOrder = false;
                this.$toast.success(this.$t("Order Created"));

                if (this.$worker) {
                    this.$worker.$emit('refresh-futures-orders');
                }

                // 下单成功后强制刷新合约余额，避免普通 WalletUpdated 事件覆盖余额
                this.refreshFuturesWalletsAfterOrder();

                this.clearForm();
            }).catch(error => {
                this.placingSellOrder = false;
                if (error && error.response && error.response.data && error.response.data.errors) {
                    _.each(error.response.data.errors, (field, key) => {
                        this.sellErrorField = key;
                        this.$toast.error(field[0]);
                    });
                }
            });
        },
        setOrderType(type) {

            if(this.orderType === type) return;

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
                amount = math_formatter((parseFloat(num1) * parseFloat(num2)) + parseFloat((num2 / this.fee) * num1), precision);
                this.bid[field] = amount;
            }
        },
        decimal_format(value, decimal) {
            return math_formatter(value, decimal);
        },
        normalizeSliderPercentage(percentage, fallback = 0) {
            let value = percentage;

            if(value === undefined || value === null || value === '') {
                value = fallback;
            }

            value = parseFloat(value || 0);

            if(isNaN(value) || !isFinite(value)) {
                value = 0;
            }

            if(value < 0) {
                value = 0;
            }

            if(value > 100) {
                value = 100;
            }

            return value;
        },
        getSliderCalculationPercentage(percentage, fallback = 0) {
            let value = this.normalizeSliderPercentage(percentage, fallback);

            /*
             * 前端拉满时显示 100%，实际计算使用 99.99%，避免满仓下单因为精度或手续费出现余额不足。
             */
            if(value >= 100) {
                return 99.99;
            }

            return value;
        },
        formatSliderTooltip(value) {
            let percentage = this.normalizeSliderPercentage(value, 0);

            if(percentage >= 100) {
                return '100%';
            }

            percentage = Math.round(percentage * 100) / 100;

            if(Number.isInteger(percentage)) {
                return percentage + '%';
            }

            return percentage.toFixed(2).replace(/\.?0+$/, '') + '%';
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
            let calculatePercentage = this.getSliderCalculationPercentage(percentage, this.buySlider.value);
            let balance = this.quoteWallet ? this.getAvailableFuturesBalance(this.quoteWallet) : 0;
            let amountWithPercentage = math_percentage(balance, calculatePercentage);

            this.bid.quantity = math_formatter(amountWithPercentage, this.market.quote_precision);
        },
        changeSellSlider(percentage) {
            let calculatePercentage = this.getSliderCalculationPercentage(percentage, this.sellSlider.value);
            let balance = this.quoteWallet ? this.getAvailableFuturesBalance(this.quoteWallet) : 0;

            this.ask.quantity = math_formatter(math_percentage(balance, calculatePercentage), this.market.quote_precision);
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

            if(this.activeTab === side) return;

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
        maxLeverage: function() {
            this.syncLeverageLimit(false);
        },
        leverage: function() {
            this.syncLeverageLimit(false);
        },
        orderType: function(type) {
            if(type == 'market') {
                this.bid.price = this.market_stats.last;
                this.ask.price = this.market_stats.last;
            }
        },
        'market_stats.last': function(price) {
            if(this.orderType == 'market') {
                this.bid.price = price;
                this.ask.price = price;
            }
        },
        'ask.quantity'(newVal){

            let balance = this.quoteWallet ? this.getAvailableFuturesBalance(this.quoteWallet) : 0;

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            //this.multiplier(this.ask.price, this.ask.quantity, 'ask', 'total', this.market.quote_precision)

            if (newVal.includes('.')) {
                this.ask.quantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }

            if(this.ask.quantity > parseFloat(balance)) {
                this.sellErrorField = "quantity";
                this.balanceSellError = true;
            } else {
                this.balanceSellError = false;
                this.sellErrorField = null;
            }
        },
        'ask.take_profit_price'(newVal) {
            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if (newVal.includes('.')) {
                this.ask.take_profit_price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }
        },
        'ask.stop_loss_price'(newVal) {
            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if (newVal.includes('.')) {
                this.ask.stop_loss_price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }
        },
        'ask.price'(newVal){

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            //this.multiplier(this.ask.price, this.ask.quantity, 'ask', 'total', this.market.quote_precision)

            if (newVal.includes('.')) {
                this.ask.price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }

            if(parseFloat(newVal) > 0 && this.sellErrorField == 'price') {
                this.sellErrorField = '';
            }
        },
        'bid.take_profit_price'(newVal) {
            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if (newVal.includes('.')) {
                this.bid.take_profit_price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }
        },
        'bid.stop_loss_price'(newVal) {
            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            if (newVal.includes('.')) {
                this.bid.stop_loss_price = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }
        },
        'bid.quantity'(newVal){

            let balance = this.quoteWallet ? this.getAvailableFuturesBalance(this.quoteWallet) : 0;

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            //this.multiplier(this.bid.price, this.bid.quantity, 'bid', 'total', this.market.quote_precision)

            if (newVal.includes('.')) {
                this.bid.quantity = newVal.split('.')[0] + '.' + newVal.split('.')[1].slice(0, this.market.quote_precision)
            }

            if (this.bid.quantity > parseFloat(balance)) {
                this.buyErrorField = "quantity";
                this.balanceBuyError = true;
            } else {
                this.balanceBuyError = false;
                this.buyErrorField = null;
            }
        },
        'bid.price'(newVal){

            newVal = String(newVal ?? '');

            if(newVal.charAt(0) === '.') {
                return;
            }

            //this.multiplier(this.bid.price, this.bid.quantity, 'bid', 'total', this.market.quote_precision)

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
