<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Wallet/AutoInvest.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
    components: {
        AppLayout
    },

    props: {
        autoInvestFunding: {
            type: Boolean,
            default: false
        },
        autoInvestDays: {
            type: Number,
            default: 30
        },
        autoInvestType: {
            type: String,
            default: 'fixed'
        },
        autoInvestAmount: {
            type: [Number, String],
            default: ''
        },
        lcSettings: {
            type: Object,
            default: () => ({
                lc30: '0',
                lc90: '0',
                lc180: '0',
                lc365: '0',
                lc_dq30: '0',
                lc_dq90: '0',
                lc_dq180: '0',
                lc_dq365: '0'
            })
        },
        autoInvestRates: {
            type: Object,
            default: () => ({
                raw: {},
                flexible: {},
                fixed: {},
                vip_boosts: {}
            })
        },
        autoInvestEarnings: {
            type: Object,
            default: () => ({
                enabled: false,
                symbol: 'USD',
                principal: 0,
                days: 30,
                rate: 0,
                start_at: null,
                maturity_date: null,
                elapsed_days: 0,
                earning_days: 0,
                earned_rate: 0,
                current_earnings: 0,
                quant_current_earnings: 0,
                futures_pnl: 0,
                futures_total_profit: 0,
                total_current_earnings: 0,
                maturity_earnings: 0,
                maturity_total: 0,
                progress_percent: 0,
                assets: [],
                orders: [],
                candles: []
            })
        }
    },

    data() {
        return {
            autoInvest: {
                funding: this.autoInvestFunding,
                days: this.autoInvestDays || 30,
                type: this.autoInvestType || 'fixed',
                amount: ''
            },
            selectedAutoInvestSymbol: '',
            autoInvestTokenDropdownVisible: false,
            savedAutoInvest: {
                funding: this.autoInvestFunding,
                days: this.autoInvestDays || 30,
                type: this.autoInvestType || 'fixed',
                amount: ''
            },
            autoInvestSaving: false,
            autoInvestRiskAccepted: false,
            showAutoInvestRiskModal: false,
            showAutoInvestYieldSourceModal: false,
            showAutoInvestInfoModal: false,
            autoInvestInfoTab: 'overview',
            openedAutoInvestFaq: ['idle_assets'],
            redeemingOrderIds: [],
            showAutoInvestStrategyModal: false,
            selectedAutoInvestStrategy: null,
            autoInvestStrategyCards: []
        }
    },

    mounted() {
        try {
            const wallets = this.$store && this.$store.getters ? this.$store.getters.getWallets : [];

            if (!wallets || !wallets.length) {
                this.$store.dispatch('fetchWallets', this.route('wallets.index'));
            }

            this.$nextTick(() => {
                this.initializeAutoInvestSymbol();
            });
        } catch (e) {}

        this.generateAutoInvestStrategies();

        if (typeof document !== 'undefined') {
            document.addEventListener('click', this.handleAutoInvestTokenOutsideClick);
        }
    },

    beforeDestroy() {
        if (typeof document !== 'undefined') {
            document.removeEventListener('click', this.handleAutoInvestTokenOutsideClick);
        }
    },

    computed: {
        investTypeOptions() {
            return [
                {
                    value: 'flexible',
                    title: legacyText("Flexible Term"),
                    shortTitle: 'Flexible',
                    tag: 'Daily yield, withdraw anytime',
                    description: legacyText("Flexible Auto Invest uses the flexible daily yield range. Orders may be redeemed anytime, and each order settles independently."),
                    rule: 'Flexible plans use the flexible daily yield range. Estimated total yield equals daily yield multiplied by selected days.'
                },
                {
                    value: 'fixed',
                    title: legacyText("Fixed Term"),
                    shortTitle: 'Fixed',
                    tag: 'Higher daily yield, locked term',
                    description: legacyText("Fixed Auto Invest uses the fixed daily yield range. Assets are locked until maturity and usually receive a higher daily yield range."),
                    rule: 'Fixed plans use the fixed daily yield range. Estimated total yield equals daily yield multiplied by selected days.'
                }
            ];
        },

        selectedInvestTypeOption() {
            const current = this.investTypeOptions.find(item => item.value === this.autoInvest.type);
            return current || this.investTypeOptions[1];
        },

        normalizedLcSettings() {
            const rawRates = this.autoInvestRates && this.autoInvestRates.raw
                ? this.autoInvestRates.raw
                : {};

            const flexibleRates = this.autoInvestRates && this.autoInvestRates.flexible
                ? this.autoInvestRates.flexible
                : {};

            const fixedRates = this.autoInvestRates && this.autoInvestRates.fixed
                ? this.autoInvestRates.fixed
                : {};

            return {
                lc30: this.lcSettings.lc30 || rawRates.lc30 || flexibleRates[30] || flexibleRates['30'] || '0',
                lc90: this.lcSettings.lc90 || rawRates.lc90 || flexibleRates[90] || flexibleRates['90'] || '0',
                lc180: this.lcSettings.lc180 || rawRates.lc180 || flexibleRates[180] || flexibleRates['180'] || '0',
                lc365: this.lcSettings.lc365 || rawRates.lc365 || flexibleRates[365] || flexibleRates['365'] || '0',

                lc_dq30: this.lcSettings.lc_dq30 || rawRates.lc_dq30 || fixedRates[30] || fixedRates['30'] || '0',
                lc_dq90: this.lcSettings.lc_dq90 || rawRates.lc_dq90 || fixedRates[90] || fixedRates['90'] || '0',
                lc_dq180: this.lcSettings.lc_dq180 || rawRates.lc_dq180 || fixedRates[180] || fixedRates['180'] || '0',
                lc_dq365: this.lcSettings.lc_dq365 || rawRates.lc_dq365 || fixedRates[365] || fixedRates['365'] || '0',
            };
        },

        investOptions() {
            const type = this.autoInvest.type === 'flexible' ? 'flexible' : 'fixed';

            return [30, 90, 180, 365].map((days) => {
                const dailyRate = this.getDailyYieldRate(type, days);
                const totalRate = this.getTotalYieldRate(dailyRate, days);

                let tag = 'Short-term plan';
                let description = 'Daily yield is settled once per day. Estimated total yield is calculated from the daily yield range and selected days.';

                if (days === 90) {
                    tag = 'Balanced plan';
                    description = 'A balanced strategy period with daily settlement and clearer total yield expectations.';
                }

                if (days === 180) {
                    tag = 'Long-term plan';
                    description = 'A longer strategy period with daily settlement and a higher estimated total yield range.';
                }

                if (days === 365) {
                    tag = 'Annual plan';
                    description = 'A 365-day strategy period designed for longer-term allocation, with daily settlement and the highest estimated total yield range.';
                }

                return {
                    days: days,

                    /**
                     * rate 保留为预计总收益的中间值，兼容旧模板和旧计算。
                     */
                    rate: this.getRateMidValue(totalRate),
                    daily_rate: this.getRateMidValue(dailyRate),
                    total_rate: this.getRateMidValue(totalRate),

                    dailyRateRaw: dailyRate,
                    totalRateRaw: totalRate,
                    dailyRateLabel: this.formatRateRangePercent(dailyRate),
                    totalRateLabel: this.formatRateRangePercent(totalRate),

                    tag: tag,
                    description: description
                };
            });
        },

        selectedInvestOption() {
            const current = this.investOptions.find(item => Number(item.days) === Number(this.autoInvest.days));
            return current || this.investOptions[0];
        },

        vipYieldBoostOptions() {
            const rates = this.autoInvestRates || {};
            const vipBoosts = rates.vip_boosts || rates.vipBoosts || {};
            const rawRates = rates.raw || {};

            return [1, 2, 3, 4, 5, 6, 7, 8].map((level) => {
                const defaultBoost = level * 10;
                const value = vipBoosts[level] !== undefined
                    ? vipBoosts[level]
                    : (
                        vipBoosts[String(level)] !== undefined
                            ? vipBoosts[String(level)]
                            : (
                                rawRates['lc_vip_' + level + '_boost_percent'] !== undefined
                                    ? rawRates['lc_vip_' + level + '_boost_percent']
                                    : defaultBoost
                            )
                    );

                return {
                    level: level,
                    boost: this.toNumber(value),
                };
            });
        },

        wallets() {
            return (this.$store && this.$store.getters && this.$store.getters.getWallets)
                ? this.$store.getters.getWallets
                : [];
        },

        autoInvestWalletOptions() {
            const wallets = this.wallets || [];

            return wallets
                .map((wallet, index) => {
                    const symbol = this.getWalletSymbol(wallet);

                    if (!symbol) {
                        return null;
                    }

                    return {
                        key: this.getWalletKey(wallet, index),
                        symbol: symbol,
                        currencyId: this.getWalletCurrencyId(wallet),
                        availableAmount: this.getWalletInvestableAmount(wallet),
                        hasVirtual: this.getWalletVirtualAmount(wallet) > 0,
                        wallet: wallet,
                    };
                })
                .filter(item => item !== null)
                .sort((a, b) => {
                    if (a.hasVirtual && !b.hasVirtual) {
                        return -1;
                    }

                    if (!a.hasVirtual && b.hasVirtual) {
                        return 1;
                    }

                    if (a.availableAmount > 0 && b.availableAmount <= 0) {
                        return -1;
                    }

                    if (a.availableAmount <= 0 && b.availableAmount > 0) {
                        return 1;
                    }

                    return String(a.symbol).localeCompare(String(b.symbol));
                });
        },

        selectedAutoInvestOption() {
            const currentSymbol = String(this.autoInvestDisplaySymbol || '').toUpperCase();

            return this.autoInvestWalletOptions.find(item => String(item.symbol || '').toUpperCase() === currentSymbol)
                || this.autoInvestWalletOptions[0]
                || null;
        },

        autoInvestDisplaySymbol() {
            if (this.selectedAutoInvestSymbol) {
                return String(this.selectedAutoInvestSymbol).toUpperCase();
            }

            const firstAvailableOption = this.autoInvestWalletOptions.find(item => Number(item.availableAmount || 0) > 0);
            if (firstAvailableOption) {
                return String(firstAvailableOption.symbol).toUpperCase();
            }

            const earningsSymbol = this.backendAutoInvestEarnings && this.backendAutoInvestEarnings.symbol
                ? String(this.backendAutoInvestEarnings.symbol).toUpperCase()
                : '';

            if (earningsSymbol && earningsSymbol !== 'USD') {
                return earningsSymbol;
            }

            const usdtOption = this.autoInvestWalletOptions.find(item => String(item.symbol || '').toUpperCase() === 'USDT');
            if (usdtOption) {
                return 'USDT';
            }

            return this.autoInvestWalletOptions.length
                ? String(this.autoInvestWalletOptions[0].symbol || 'USDT').toUpperCase()
                : 'USDT';
        },

        preferredAutoInvestWallet() {
            const wallets = this.wallets || [];

            if (!wallets.length) {
                return null;
            }

            const selectedSymbol = String(this.autoInvestDisplaySymbol || '').toUpperCase();

            if (selectedSymbol) {
                const selectedWallet = wallets.find(item => this.getWalletSymbol(item) === selectedSymbol);

                if (selectedWallet) {
                    return selectedWallet;
                }
            }

            const virtualWallet = wallets.find(item => this.getWalletVirtualAmount(item) > 0);
            if (virtualWallet) {
                return virtualWallet;
            }

            return wallets.find(item => this.getWalletSymbol(item) === 'USDT')
                || wallets[0]
                || null;
        },

        autoInvestSelectedCurrencyId() {
            const wallet = this.preferredAutoInvestWallet;

            return this.getWalletCurrencyId(wallet);
        },

        autoInvestMaxAmount() {
            const wallet = this.preferredAutoInvestWallet;

            if (!wallet) {
                return 0;
            }

            return this.getWalletInvestableAmount(wallet);
        },

        formattedAutoInvestMaxAmount() {
            return this.formatMoney(this.autoInvestMaxAmount);
        },

        currentUserVipLevel() {
            const pageProps = this.$page && this.$page.props ? this.$page.props : {};
            const user = pageProps.user || (pageProps.auth ? pageProps.auth.user : null) || null;

            if (!user) {
                return 0;
            }

            const earnings = this.backendAutoInvestEarnings || {};

            const directCandidates = [
                earnings.vip_level,
                earnings.current_vip_level,
                earnings.vipLevel,
                earnings.currentVipLevel,
                earnings.vip_boost_level,
                earnings.vipBoostLevel,
                user.vip_level,
                user.vipLevel,
                user.current_vip_level,
                user.currentVipLevel,
                user.vip_grade,
                user.vipGrade,
                user.member_level,
                user.memberLevel,
                user.membership_level,
                user.membershipLevel,
                user.account_level,
                user.accountLevel,
                user.level,
                user.vip,
                user.user_vip,
                user.member,
                user.membership,
                user.group,
                user.grade,
                user.tier,
                user.rank,
                user.vip_name,
                user.vipName,
                user.vip_title,
                user.vipTitle,
                user.vip && user.vip.level ? user.vip.level : null,
                user.vip && user.vip.vip_level ? user.vip.vip_level : null,
                user.vip && user.vip.name ? user.vip.name : null,
                user.membership && user.membership.level ? user.membership.level : null,
                user.member && user.member.level ? user.member.level : null,
                user.group && user.group.level ? user.group.level : null,
                user.group && user.group.name ? user.group.name : null,
            ];

            const collected = [...directCandidates, ...this.collectVipCandidates(user)];

            for (let i = 0; i < collected.length; i++) {
                const level = this.parseVipLevel(collected[i]);

                if (level >= 1 && level <= 8) {
                    return level;
                }
            }

            return 0;
        },

        currentVipYieldBoost() {
            const current = this.vipYieldBoostOptions.find(item => Number(item.level) === Number(this.currentUserVipLevel));

            return current ? Number(current.boost) : 0;
        },

        currentVipYieldBoostLabel() {
            return (this.currentVipYieldBoost >= 0 ? '+' : '') + this.currentVipYieldBoost + '%';
        },

        autoInvestVipBoostRate() {
            const earnings = this.backendAutoInvestEarnings || {};

            const candidates = [
                earnings.vip_boost_rate,
                earnings.vip_boost_percent,
            ];

            for (let i = 0; i < candidates.length; i++) {
                if (candidates[i] !== null && candidates[i] !== undefined && candidates[i] !== '') {
                    const rate = this.toNumber(candidates[i]);

                    if (rate > 0) {
                        return rate;
                    }
                }
            }

            const fallbackRate = this.toNumber(this.currentVipYieldBoost);

            return fallbackRate > 0 ? fallbackRate : 0;
        },

        autoInvestBaseCurrentEarnings() {
            const earnings = this.backendAutoInvestEarnings || {};
            const directValue = earnings.base_current_earnings !== undefined
                ? earnings.base_current_earnings
                : earnings.base_current_earnings_usd;

            if (directValue !== undefined && directValue !== null && directValue !== '') {
                return Math.max(0, this.toNumber(directValue));
            }

            const quantCurrentEarnings = earnings.quant_current_earnings !== undefined
                ? this.toNumber(earnings.quant_current_earnings)
                : 0;
            const boostRate = this.autoInvestVipBoostRate;

            if (quantCurrentEarnings === 0 || boostRate <= -100) {
                return Math.max(0, quantCurrentEarnings);
            }

            return Math.max(0, quantCurrentEarnings / (1 + (boostRate / 100)));
        },

        autoInvestVipCurrentBonus() {
            const earnings = this.backendAutoInvestEarnings || {};
            const directValue = earnings.vip_current_bonus !== undefined
                ? earnings.vip_current_bonus
                : earnings.vip_current_bonus_usd;
            const boostRate = this.autoInvestVipBoostRate;

            if (directValue !== undefined && directValue !== null && directValue !== '') {
                const directBonus = this.toNumber(directValue);

                if (directBonus !== 0 || boostRate <= 0) {
                    return Math.max(0, directBonus);
                }

                return Math.max(0, this.autoInvestBaseCurrentEarnings * (boostRate / 100));
            }

            const quantCurrentEarnings = earnings.quant_current_earnings !== undefined
                ? this.toNumber(earnings.quant_current_earnings)
                : 0;

            if (quantCurrentEarnings !== 0) {
                const directBonus = quantCurrentEarnings - this.autoInvestBaseCurrentEarnings;

                if (directBonus !== 0 || boostRate <= 0) {
                    return Math.max(0, directBonus);
                }
            }

            return Math.max(0, this.autoInvestBaseCurrentEarnings * (boostRate / 100));
        },

        autoInvestQuantCurrentEarnings() {
            const earnings = this.backendAutoInvestEarnings || {};
            const computedEarnings = this.autoInvestBaseCurrentEarnings + this.autoInvestVipCurrentBonus;

            if (earnings.quant_current_earnings !== undefined && earnings.quant_current_earnings !== null && earnings.quant_current_earnings !== '') {
                return Math.max(0, this.toNumber(earnings.quant_current_earnings));
            }

            if (computedEarnings !== 0) {
                return Math.max(0, computedEarnings);
            }

            return 0;
        },

        autoInvestYieldSourceItems() {
            return [
                {
                    label: legacyText("Source 1"),
                    title: legacyText("AI Market Scanning"),
                    shortText: 'AI models scan eligible markets and identify potential trading opportunities.',
                    detailText: 'The AI engine continuously monitors eligible spot and derivative markets, tracking liquidity, volatility, spreads, funding changes, and short-term price deviations to identify potential strategy windows.'
                },
                {
                    label: legacyText("Source 2"),
                    title: legacyText("Quantitative Spread Capture"),
                    shortText: 'The strategy seeks return from short-term price differences and market spreads.',
                    detailText: 'When market conditions meet predefined rules, the quantitative model may capture small price spreads across eligible trading pairs or liquidity pools while controlling exposure through position sizing and execution limits.'
                },
                {
                    label: legacyText("Source 3"),
                    title: legacyText("Liquidity Optimization"),
                    shortText: 'Idle assets may be allocated to liquidity-based strategy modules.',
                    detailText: 'Eligible idle assets can be routed into liquidity optimization modules. These modules aim to improve capital utilization by participating in controlled liquidity allocation, market depth support, and settlement-based reward opportunities.'
                },
                {
                    label: legacyText("Source 4"),
                    title: legacyText("Risk-Controlled Rebalancing"),
                    shortText: 'The system adjusts allocation according to market risk and settlement rules.',
                    detailText: 'The strategy includes automated rebalancing and risk filters. When abnormal volatility, liquidity shortage, or unfavorable market conditions appear, the system may reduce allocation, pause participation, or adjust settlement expectations.'
                }
            ];
        },

        backendAutoInvestEarnings() {
            return this.autoInvestEarnings || {
                enabled: false,
                symbol: 'USD',
                principal: 0,
                days: this.autoInvest.days,
                rate: 0,
                start_at: null,
                maturity_date: null,
                elapsed_days: 0,
                earning_days: 0,
                earned_rate: 0,
                current_earnings: 0,
                quant_current_earnings: 0,
                futures_pnl: 0,
                futures_total_profit: 0,
                total_current_earnings: 0,
                maturity_earnings: 0,
                maturity_total: 0,
                progress_percent: 0,
                assets: [],
                orders: [],
                candles: []
            };
        },

        autoInvestCurrentTotal() {
            const earnings = this.backendAutoInvestEarnings || {};

            return this.toNumber(earnings.principal) +
                this.autoInvestQuantCurrentEarnings;
        },

        autoInvestEarningPrincipal() {
            const earnings = this.backendAutoInvestEarnings || {};

            return this.toNumber(earnings.earning_principal || earnings.principal);
        },

        autoInvestUsedMargin() {
            const earnings = this.backendAutoInvestEarnings || {};

            return this.toNumber(earnings.used_margin);
        },

        autoInvestRedeemableAmount() {
            const earnings = this.backendAutoInvestEarnings || {};

            return this.toNumber(earnings.redeemable_amount);
        },

        autoInvestFlexibleRedeemableAmount() {
            return (this.autoInvestOrders || []).reduce((total, order) => {
                if (this.getOrderTypeValue(order) !== 'flexible') {
                    return total;
                }

                return total + this.toNumber(order.redeemable_amount_usd || order.redeemable_amount || 0);
            }, 0);
        },

        autoInvestFixedRedeemableAmount() {
            return (this.autoInvestOrders || []).reduce((total, order) => {
                if (this.getOrderTypeValue(order) !== 'fixed') {
                    return total;
                }

                return total + this.toNumber(order.redeemable_amount_usd || order.redeemable_amount || 0);
            }, 0);
        },

        /**
         * 新逻辑：量化改成订单结算后，页面不再用“已有计划”锁住表单。
         * 这里保留字段只是为了兼容模板里的旧判断。
         */
        isAutoInvestAlreadyEnabled() {
            return false;
        },

        hasActiveAutoInvestOrders() {
            return !!this.autoInvestFunding || Number(this.backendAutoInvestEarnings.principal || 0) > 0;
        },

        isFlexibleAutoInvest() {
            return String(this.savedAutoInvest.type || this.autoInvest.type) === 'flexible';
        },

        isFixedAutoInvest() {
            return String(this.savedAutoInvest.type || this.autoInvest.type) === 'fixed';
        },

        maturityTimestamp() {
            const value = this.backendAutoInvestEarnings.maturity_date;

            if (!value) {
                return null;
            }

            const normalized = String(value).replace(' ', 'T');
            const timestamp = Date.parse(normalized);

            return Number.isFinite(timestamp) ? timestamp : null;
        },

        isAutoInvestMatured() {
            if (!this.isAutoInvestAlreadyEnabled) {
                return false;
            }

            if (this.isFlexibleAutoInvest) {
                return true;
            }

            if (!this.maturityTimestamp) {
                return false;
            }

            return this.maturityTimestamp <= Date.now();
        },

        canRedeemAutoInvest() {
            if (!this.isAutoInvestAlreadyEnabled || this.autoInvestSaving) {
                return false;
            }

            if (this.isFlexibleAutoInvest) {
                return true;
            }

            return this.isAutoInvestMatured;
        },

        redeemDisabledReason() {
            if (!this.isAutoInvestAlreadyEnabled) {
                return '';
            }

            if (this.isFlexibleAutoInvest) {
                return legacyText("Flexible Auto Invest can be redeemed anytime.");
            }

            if (this.isAutoInvestMatured) {
                return legacyText("The fixed term has matured and can now be redeemed.");
            }

            if (this.backendAutoInvestEarnings.maturity_date) {
                return legacyText("Fixed Term assets can be redeemed after the maturity date.");
            }

            return legacyText("Fixed Term assets cannot be redeemed before maturity.");
        },

        autoInvestDisplayStatus() {
            if (!this.autoInvest.funding) {
                return legacyText("Auto Invest Paused");
            }

            if (this.hasActiveAutoInvestOrders) {
                return legacyText("Auto Invest Enabled");
            }

            return legacyText("Pending confirmation");
        },

        confirmAutoInvestButtonText() {
            return this.hasActiveAutoInvestOrders
                ? legacyText("Create Another Auto Invest Order")
                : legacyText("Confirm and Start Auto Invest");
        },

        confirmBoxTitle() {
            return this.hasActiveAutoInvestOrders
                ? legacyText("Ready to create another Auto Invest order?")
                : legacyText("Ready to start Auto Invest?");
        },

        confirmBoxDescription() {
            return legacyText("Each confirmation creates a new independent Auto Invest order. Existing orders continue to settle by their own start time, amount, period, and rules.");
        },

        shouldShowAutoInvestEarnings() {
            return Number(this.backendAutoInvestEarnings.principal || 0) > 0 ||
                this.autoInvestOrders.length > 0 ||
                this.autoInvestCandles.length > 0;
        },

        normalizedAutoInvestAmount() {
            const amount = Number(this.autoInvest.amount);
            return Number.isFinite(amount) ? amount : 0;
        },

        canConfirmAutoInvest() {
            return this.autoInvest.funding &&
                !this.autoInvestSaving &&
                this.normalizedAutoInvestAmount > 0;
        },

        autoInvestOrders() {
            const earnings = this.backendAutoInvestEarnings || {};

            if (Array.isArray(earnings.orders) && earnings.orders.length) {
                return earnings.orders.filter(order => this.isActiveAutoInvestOrder(order));
            }

            if (Array.isArray(earnings.assets) && earnings.assets.length) {
                return earnings.assets.filter(order => this.isActiveAutoInvestOrder(order));
            }

            return [];
        },

        autoInvestCandles() {
            const earnings = this.backendAutoInvestEarnings || {};
            const rawCandles = Array.isArray(earnings.candles)
                ? earnings.candles
                : (
                    Array.isArray(earnings.kline)
                        ? earnings.kline
                        : (
                            Array.isArray(earnings.chart_points)
                                ? earnings.chart_points
                                : []
                        )
                );

            if (rawCandles.length) {
                return rawCandles
                    .map((item, index) => this.normalizeAutoInvestCandle(item, index))
                    .filter(item => item !== null);
            }

            if (this.autoInvestOrders.length) {
                return this.buildCandlesFromOrders(this.autoInvestOrders);
            }

            return this.buildCandlesFromSingleSummary(earnings);
        },

        autoInvestChartWidth() {
            return 960;
        },

        autoInvestChartHeight() {
            return 320;
        },

        autoInvestChartPadding() {
            return {
                top: 24,
                right: 70,
                bottom: 44,
                left: 58,
            };
        },

        autoInvestChartInnerWidth() {
            return this.autoInvestChartWidth - this.autoInvestChartPadding.left - this.autoInvestChartPadding.right;
        },

        autoInvestChartInnerHeight() {
            return this.autoInvestChartHeight - this.autoInvestChartPadding.top - this.autoInvestChartPadding.bottom;
        },

        autoInvestChartViewBox() {
            return `0 0 ${this.autoInvestChartWidth} ${this.autoInvestChartHeight}`;
        },

        autoInvestChartValues() {
            const values = [];

            this.autoInvestCandles.forEach((item) => {
                values.push(item.open, item.high, item.low, item.close);
            });

            if (!values.length) {
                values.push(0, 1);
            }

            return values.filter(value => Number.isFinite(Number(value)));
        },

        autoInvestChartMin() {
            const min = Math.min.apply(null, this.autoInvestChartValues);
            const max = Math.max.apply(null, this.autoInvestChartValues);
            const gap = Math.max(1, (max - min) * 0.12);

            return Math.max(0, min - gap);
        },

        autoInvestChartMax() {
            const min = Math.min.apply(null, this.autoInvestChartValues);
            const max = Math.max.apply(null, this.autoInvestChartValues);
            const gap = Math.max(1, (max - min) * 0.12);

            return max + gap;
        },

        autoInvestChartYTicks() {
            const ticks = [];
            const min = this.autoInvestChartMin;
            const max = this.autoInvestChartMax;
            const count = 4;

            for (let i = 0; i <= count; i++) {
                const value = max - ((max - min) / count) * i;

                ticks.push({
                    value: value,
                    y: this.valueToChartY(value),
                    label: this.formatMoney(value),
                });
            }

            return ticks;
        },

        autoInvestCandleBodyWidth() {
            if (!this.autoInvestCandles.length) {
                return 10;
            }

            return Math.max(6, Math.min(22, this.autoInvestChartInnerWidth / this.autoInvestCandles.length * 0.52));
        },

        autoInvestChartLinePoints() {
            if (!this.autoInvestCandles.length) {
                return '';
            }

            return this.autoInvestCandles
                .map((candle, index) => `${this.candleX(index)},${this.valueToChartY(candle.close)}`)
                .join(' ');
        },

        autoInvestChartLatest() {
            if (!this.autoInvestCandles.length) {
                return null;
            }

            return this.autoInvestCandles[this.autoInvestCandles.length - 1];
        },

        autoInvestChartFirst() {
            if (!this.autoInvestCandles.length) {
                return null;
            }

            return this.autoInvestCandles[0];
        },

        autoInvestChartChange() {
            if (!this.autoInvestChartLatest || !this.autoInvestChartFirst) {
                return 0;
            }

            return this.autoInvestChartLatest.close - this.autoInvestChartFirst.open;
        },

        autoInvestChartChangePercent() {
            if (!this.autoInvestChartFirst || Number(this.autoInvestChartFirst.open || 0) <= 0) {
                return 0;
            }

            return (this.autoInvestChartChange / Number(this.autoInvestChartFirst.open)) * 100;
        },

        autoInvestChartTrendClass() {
            return this.autoInvestChartChange >= 0 ? 'text-green-400' : 'text-red-400';
        },

        autoInvestChartSummary() {
            return {
                startDate: this.autoInvestChartFirst ? this.autoInvestChartFirst.date : '-',
                latestDate: this.autoInvestChartLatest ? this.autoInvestChartLatest.date : '-',
                startValue: this.autoInvestChartFirst ? this.autoInvestChartFirst.open : 0,
                latestValue: this.autoInvestChartLatest ? this.autoInvestChartLatest.close : 0,
                change: this.autoInvestChartChange,
                changePercent: this.autoInvestChartChangePercent,
            };
        },

        autoInvestOverviewItems() {
            return [
                {
                    title: legacyText("What are idle assets?"),
                    text: legacyText("Idle assets refer to funds in your Funding Account that have not been used for deposits, redemptions, purchases, rewards, or other balance changes for the past 24 hours or longer.")
                },
                {
                    title: legacyText("Supported assets"),
                    text: legacyText("Auto Invest supports USDT and other eligible assets in the Funding Account. You may enable Auto Invest for all eligible assets or manage assets individually.")
                },
                {
                    title: legacyText("How activation works"),
                    text: legacyText("After Auto Invest is enabled, each confirmation creates an independent order. Flexible orders use flexible daily yield ranges, while fixed orders use fixed daily yield ranges.")
                },
                {
                    title: legacyText("Yield sources"),
                    text: legacyText("Auto Invest earnings are shown as daily yield and estimated total yield. Estimated total yield equals the selected daily yield range multiplied by the selected number of days, with eligible VIP settlement boosts displayed separately.")
                }
            ];
        },

        autoInvestFaqSections() {
            return [
                {
                    key: 'idle_assets',
                    title: legacyText("What are idle assets?"),
                    content: [
                        legacyText("Idle assets are funds that have remained unused in the Funding Account for the past 24 hours or longer."),
                        legacyText("This includes assets that have not been affected by deposits, redemption, purchases, or reward distributions during the selected period.")
                    ]
                },
                {
                    key: 'supported_assets',
                    title: legacyText("Which assets are supported?"),
                    content: [
                        legacyText("Auto Invest supports USDT and other eligible assets in the Funding Account."),
                        legacyText("When Auto Invest is enabled for all supported assets, current and future eligible assets may be included automatically.")
                    ]
                },
                {
                    key: 'activation_rules',
                    title: legacyText("How do I enable Auto Invest?"),
                    content: [
                        legacyText("Turn on Auto Invest, select Flexible Term or Fixed Term, choose the strategy period, enter the amount, then confirm the plan rules."),
                        legacyText("Flexible products display flexible daily yield. Fixed products display fixed daily yield."),
                        legacyText("Assets in the Funding Account should have no new balance changes for the past 24 hours or longer.")
                    ]
                },
                {
                    key: 'yield_source',
                    title: legacyText("Where does the yield come from?"),
                    content: [
                        legacyText("The yield source is mainly the Deepro AI Quant Yield Pool."),
                        legacyText("The strategy may include AI market scanning, quantitative spread capture, liquidity optimization, and risk-controlled rebalancing."),
                        legacyText("Eligible users may also receive a VIP settlement boost. Level 1 to Level 8 correspond to additional settlement boosts from 10% to 80%.")
                    ]
                },
                {
                    key: 'redeem_rules',
                    title: legacyText("Can I turn off Auto Invest?"),
                    content: [
                        legacyText("Yes. Flexible Term Auto Invest can be redeemed anytime."),
                        legacyText("Fixed Term Auto Invest cannot be redeemed before maturity. Once the maturity date is reached, the Redeem button will become available."),
                        legacyText("Turning off Auto Invest will not automatically redeem active fixed-term orders before maturity.")
                    ]
                },
                {
                    key: 'earnings',
                    title: legacyText("Where can I view earnings?"),
                    content: [
                        legacyText("After assets are successfully allocated, you can view the plan, principal, daily yield range, estimated total yield, accrued earnings, and maturity date on this page."),
                        legacyText("You can also check related records under Assets and Earn.")
                    ]
                }
            ];
        }
    },

    watch: {
        wallets: {
            handler() {
                this.initializeAutoInvestSymbol();
            },
            deep: true,
            immediate: true,
        },
    },

    methods: {
        generateAutoInvestStrategies() {
            // No verified per-strategy performance feed is connected.
            this.autoInvestStrategyCards = [];
        },

        buildStrategyDescription(strategy) {
            const symbol = String(strategy.symbol || '').toUpperCase();
            const baseAsset = symbol.replace('USDT', '');
            const type = String(strategy.type || 'AI Momentum');
            const executionStyle = String(strategy.executionStyle || 'TWAP');
            const riskLevel = String(strategy.riskLevel || 'Balanced');

            const assetNameMap = {
                BTC: 'Bitcoin',
                ETH: 'Ethereum',
                SOL: 'Solana',
                BNB: 'BNB',
                XRP: 'XRP',
                DOGE: 'DOGE',
                ADA: 'Cardano',
                AVAX: 'Avalanche',
                LINK: 'Chainlink',
                DOT: 'Polkadot',
                NEAR: 'NEAR',
                OP: 'Optimism',
                ARB: 'Arbitrum',
                INJ: 'Injective',
                FIL: 'Filecoin',
                MATIC: 'Polygon',
                APT: 'Aptos',
                SUI: 'Sui',
                TON: 'TON',
                LTC: 'Litecoin',
                BCH: 'BCH',
                ETC: 'ETC',
                UNI: 'Uniswap',
                AAVE: 'AAVE',
                ATOM: 'Cosmos',
                TRX: 'TRON',
                HYPE: 'HYPE',
                XAU: 'Gold',
                XAG: 'Silver',
                PEPE: 'PEPE',
                SEI: 'SEI',
                WIF: 'WIF',
                ORDI: 'ORDI',
                TIA: 'TIA',
                JUP: 'Jupiter',
                FET: 'FET',
                RNDR: 'Render'
            };

            const assetName = assetNameMap[baseAsset] || baseAsset || symbol;

            const descriptionMap = {
                /*
                 * 4句：网格类会多说明网格宽度和资金分层。
                 */
                'Grid Arbitrage': [
                    'This strategy builds a layered grid around {asset} to capture repeated price oscillations.',
                    'Grid width is adjusted by volatility, spread quality and available market depth.',
                    'Capital is divided into smaller tranches so entries are not concentrated at a single level.',
                    '{execution} execution is used to reduce slippage, while {risk} risk controls limit drawdown expansion.'
                ],

                /*
                 * 3句：中性策略保持简短，突出对冲和保证金。
                 */
                'Neutral Hedge': [
                    'This strategy focuses on market-neutral exposure for {asset}, reducing dependence on one-way price movement.',
                    'Long and short signals are balanced through volatility filters, funding pressure and momentum deviation.',
                    '{risk} risk controls monitor hedge ratio, margin usage and exposure drift.'
                ],

                /*
                 * 2句：价差策略短一些，更像交易台摘要。
                 */
                'Spread Capture': [
                    'This strategy tracks short-term spread changes on {asset} and waits for efficient entry windows.',
                    '{execution} execution is selected to control slippage while the model defines exit and rebalancing bands.'
                ],

                /*
                 * 4句：轮动策略解释资金为什么换仓。
                 */
                'Liquidity Rotation': [
                    'This strategy rotates allocation into {asset} when liquidity improves and volatility stays within the target band.',
                    'The model reviews depth changes, volume acceleration and cross-market activity before increasing weight.',
                    'If liquidity weakens, allocation is reduced instead of forcing continuous exposure.',
                    '{risk} risk controls keep the rotation inside predefined exposure limits.'
                ],

                /*
                 * 5句：趋势类给更完整的判断逻辑。
                 */
                'Trend Follow': [
                    'This strategy follows the dominant trend of {asset} after momentum and volatility confirmation.',
                    'The model avoids weak breakouts by checking volume expansion, pullback strength and short-term structure.',
                    'Entries are split across the signal window rather than placed all at once.',
                    'If momentum fails to continue, exposure is reduced before the trend fully reverses.',
                    '{execution} execution supports order pacing while {risk} risk controls protect against trend failure.'
                ],

                /*
                 * 3句：均值回归突出偏离、恢复、止损区。
                 */
                'Mean Reversion': [
                    'This strategy looks for mean-reversion opportunities when {asset} deviates from its recent fair-value range.',
                    'The model checks overshoot distance, liquidity recovery and volatility contraction before allocation.',
                    '{execution} execution helps avoid chasing extremes, while {risk} risk controls define rebound and stop zones.'
                ],

                /*
                 * 4句：突破策略更像交易模型说明。
                 */
                'Breakout Guard': [
                    'This strategy monitors {asset} for breakout continuation while filtering false momentum bursts.',
                    'The model scores volume expansion, volatility compression and post-breakout holding strength.',
                    'A weak breakout will lower the allocation score even if the latest candle is positive.',
                    '{risk} risk controls reduce exposure when breakout quality weakens.'
                ],

                /*
                 * 2句：深度再平衡简短直接。
                 */
                'Depth Rebalance': [
                    'This strategy adjusts {asset} allocation according to order-book depth and short-term liquidity imbalance.',
                    '{execution} execution reduces market impact during reallocation while {risk} risk controls monitor margin and drawdown.'
                ],

                /*
                 * 5句：短线波段写得更活跃一些。
                 */
                'Micro Swing': [
                    'This strategy targets short-cycle swings on {asset} using compact signal windows.',
                    'The model focuses on quick momentum shifts, local support and resistance, and intraday volatility changes.',
                    'Entries are kept smaller because the strategy reacts to shorter market structures.',
                    'Profit-taking zones are reviewed more frequently than long-term allocation strategies.',
                    '{risk} risk controls limit single-signal exposure and avoid over-allocation during fast reversals.'
                ],

                /*
                 * 3句：默认AI动能。
                 */
                'AI Momentum': [
                    'This strategy uses AI momentum scoring to evaluate {asset} across liquidity, volatility and short-term trend strength.',
                    'The model confirms allocation through volume quality, spread stability and signal persistence.',
                    '{execution} execution is used for order pacing, while {risk} risk controls manage drawdown and exposure.'
                ]
            };

            const lines = descriptionMap[type] || descriptionMap['AI Momentum'];

            return lines.map(text => this.formatStrategyDescriptionText(
                this.translateStrategyDescriptionTemplate(text),
                assetName,
                executionStyle,
                riskLevel
            ));
        },

        translateStrategyDescriptionTemplate(text) {
            if (typeof this.$t === 'function') {
                return this.$t(text);
            }

            return text;
        },

        formatStrategyDescriptionText(text, assetName, executionStyle, riskLevel) {
            return String(text || '')
                .replace(/\{asset\}/g, assetName)
                .replace(/\{execution\}/g, executionStyle)
                .replace(/\{risk\}/g, this.$t ? this.$t(riskLevel) : riskLevel);
        },

        strategyCandleRange(candles) {
            const values = [];

            (candles || []).forEach((item) => {
                values.push(
                    Number(item.open || 0),
                    Number(item.high || 0),
                    Number(item.low || 0),
                    Number(item.close || 0)
                );
            });

            if (!values.length) {
                return {
                    min: -1,
                    max: 1,
                    range: 2
                };
            }

            let min = Math.min.apply(null, values);
            let max = Math.max.apply(null, values);

            const gap = Math.max(1, (max - min) * 0.16);
            min -= gap;
            max += gap;

            return {
                min: min,
                max: max,
                range: Math.max(1, max - min)
            };
        },

        strategyCandleX(index, total, width = 128, padding = 7) {
            const innerWidth = width - padding * 2;

            return padding + (innerWidth / Math.max(1, Number(total || 1) - 1)) * Number(index || 0);
        },

        strategyCandleWidth(candles, width = 128, padding = 7, maxWidth = 5) {
            const total = Math.max(1, (candles || []).length);
            const innerWidth = width - padding * 2;

            return Math.max(1.6, Math.min(maxWidth, innerWidth / total * 0.56));
        },

        strategyValueToY(value, candles, height = 46, padding = 6) {
            const range = this.strategyCandleRange(candles);
            const innerHeight = height - padding * 2;

            return padding + (range.max - Number(value || 0)) / range.range * innerHeight;
        },

        strategyCandleBodyY(candle, candles, height = 46, padding = 6) {
            const openY = this.strategyValueToY(candle.open, candles, height, padding);
            const closeY = this.strategyValueToY(candle.close, candles, height, padding);

            return Math.min(openY, closeY);
        },

        strategyCandleBodyHeight(candle, candles, height = 46, padding = 6) {
            const openY = this.strategyValueToY(candle.open, candles, height, padding);
            const closeY = this.strategyValueToY(candle.close, candles, height, padding);

            return Math.max(1.4, Math.abs(openY - closeY));
        },

        strategyCandleColor(candle) {
            return Number(candle.close || 0) >= Number(candle.open || 0)
                ? '#10b981'
                : '#f43f5e';
        },

        strategyCandlesToLineSegments(candles, width = 168, height = 62, padding = 8, leftPadding = null, rightPadding = null) {
            const items = candles || [];
            const segments = [];

            if (items.length < 2) {
                return segments;
            }

            const left = leftPadding === null ? padding : Number(leftPadding || 0);
            const right = rightPadding === null ? padding : Number(rightPadding || 0);
            const innerWidth = Math.max(1, width - left - right);
            const total = Math.max(1, items.length - 1);

            const xAt = (index) => {
                return left + (innerWidth / total) * Number(index || 0);
            };

            for (let i = 1; i < items.length; i++) {
                const previous = items[i - 1];
                const current = items[i];

                segments.push({
                    key: i,
                    x1: xAt(i - 1),
                    y1: this.strategyValueToY(previous.close, items, height, padding),
                    x2: xAt(i),
                    y2: this.strategyValueToY(current.close, items, height, padding),
                    color: Number(current.close || 0) >= Number(previous.close || 0) ? 'var(--ui-success)' : 'var(--ui-danger)'
                });
            }

            return segments;
        },

        strategySeriesToPoints(series, width = 128, height = 46, padding = 6) {
            if (!series || !series.length) {
                return '';
            }

            const min = Math.min.apply(null, series);
            const max = Math.max.apply(null, series);
            const range = Math.max(1, max - min);
            const innerWidth = width - padding * 2;
            const innerHeight = height - padding * 2;

            return series.map((value, index) => {
                const x = padding + (innerWidth / Math.max(1, series.length - 1)) * index;
                const y = padding + (max - value) / range * innerHeight;

                return x.toFixed(2) + ',' + y.toFixed(2);
            }).join(' ');
        },

        strategyDateLabel(offsetDays = 0) {
            const date = new Date();
            date.setDate(date.getDate() - Number(offsetDays || 0));

            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');

            return month + '-' + day;
        },

        formatStrategyMoney(value) {
            const number = Number(value || 0);
            const sign = number > 0 ? '+' : (number < 0 ? '-' : '');

            return sign + Math.abs(number).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },

        formatStrategyPercent(value) {
            const number = Number(value || 0);
            const sign = number > 0 ? '+' : (number < 0 ? '-' : '');

            return sign + Math.abs(number).toFixed(2) + '%';
        },

        openAutoInvestStrategyModal(strategy) {
            this.selectedAutoInvestStrategy = strategy;
            this.showAutoInvestStrategyModal = true;
        },

        closeAutoInvestStrategyModal() {
            this.showAutoInvestStrategyModal = false;
        },

        copyAutoInvestStrategy(strategy) {
            const item = strategy || this.selectedAutoInvestStrategy;

            if (!item) {
                return;
            }

            const content = [
                item.symbol,
                item.name,
                item.sideLabel + ' ' + item.leverage + 'x',
                'PnL: ' + this.formatStrategyMoney(item.pnl) + ' USD',
                'Return: ' + item.roiLabel,
                'Runtime: ' + item.runtime,
                'Max Drawdown: ' + item.maxDrawdown + '%'
            ].join(' | ');

            if (typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(content).then(() => {
                    this.showToast('Strategy copied.', 'success');
                }).catch(() => {
                    this.showToast('Strategy copied.', 'success');
                });
                return;
            }

            this.showToast('Strategy copied.', 'success');
        },

        parseVipLevel(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            if (typeof value === 'number') {
                return Number.isFinite(value) ? Number(value) : 0;
            }

            if (typeof value === 'object') {
                const nestedCandidates = [
                    value.level,
                    value.vip_level,
                    value.vipLevel,
                    value.name,
                    value.title,
                    value.label,
                    value.code,
                    value.id,
                ];

                for (let i = 0; i < nestedCandidates.length; i++) {
                    const nestedLevel = this.parseVipLevel(nestedCandidates[i]);

                    if (nestedLevel >= 1 && nestedLevel <= 8) {
                        return nestedLevel;
                    }
                }

                return 0;
            }

            const text = String(value).trim();
            const match = text.match(/(?:vip\s*|v\s*)?(\d+)/i);

            if (!match) {
                return 0;
            }

            const level = Number(match[1]);
            return Number.isFinite(level) ? level : 0;
        },

        collectVipCandidates(source, depth = 0) {
            if (!source || typeof source !== 'object' || depth > 2) {
                return [];
            }

            const results = [];
            const keys = Object.keys(source);

            keys.forEach((key) => {
                const value = source[key];
                const loweredKey = String(key).toLowerCase();

                if (
                    loweredKey.includes('vip') ||
                    loweredKey.includes('member') ||
                    loweredKey.includes('level') ||
                    loweredKey.includes('group') ||
                    loweredKey.includes('tier') ||
                    loweredKey.includes('grade') ||
                    loweredKey.includes('rank')
                ) {
                    results.push(value);
                }

                if (value && typeof value === 'object') {
                    results.push(...this.collectVipCandidates(value, depth + 1));
                }
            });

            return results;
        },

        getAutoInvestSummarySymbol() {
            /*
             * 汇总区域统一使用 USDT 口径显示。
             * 订单列表仍然显示每一笔订单自己的资产代币。
             */
            return 'USDT';
        },

        getAutoInvestOrderSymbol(order = null) {
            const candidates = [];

            if (order) {
                candidates.push(
                    order.symbol,
                    order.currency_symbol,
                    order.currencySymbol,
                    order.asset_symbol,
                    order.assetSymbol,
                    order.currency,
                    order.asset,
                    order.currency && order.currency.symbol,
                    order.currency && order.currency.code
                );
            }

            candidates.push(
                this.autoInvestDisplaySymbol,
                this.selectedAutoInvestSymbol
            );

            for (let i = 0; i < candidates.length; i++) {
                const value = candidates[i];

                if (value !== null && value !== undefined && String(value).trim() !== '') {
                    return String(value).trim().toUpperCase();
                }
            }

            return 'USDT';
        },

        getAutoInvestOrderPrincipalAmount(order) {
            if (!order) {
                return 0;
            }

            /*
             * 订单列表优先显示原币种本金，不要优先 principal_usd。
             */
            const candidates = [
                order.principal_amount,
                order.principalAmount,
                order.amount,
                order.principal,
                order.principal_usd,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const value = this.toNumber(candidates[i]);

                if (value > 0) {
                    return value;
                }
            }

            return 0;
        },

        getAutoInvestOrderUsedMarginAmount(order) {
            if (!order) {
                return 0;
            }

            /*
             * 订单列表优先显示原币种已使用保证金，不要优先 used_margin_usd。
             */
            const candidates = [
                order.used_margin,
                order.used_margin_amount,
                order.usedMargin,
                order.used_margin_usd,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const value = this.toNumber(candidates[i]);

                if (value > 0) {
                    return value;
                }
            }

            return 0;
        },

        getAutoInvestOrderEarningAmount(order) {
            if (!order) {
                return 0;
            }

            const candidates = [
                order.earning_amount,
                order.earningAmount,
                order.earning_principal_amount,
                order.earning_principal,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const value = this.toNumber(candidates[i]);

                if (value > 0) {
                    return value;
                }
            }

            const principal = this.getAutoInvestOrderPrincipalAmount(order);
            const usedMargin = this.getAutoInvestOrderUsedMarginAmount(order);

            return Math.max(0, principal - usedMargin);
        },

        getAutoInvestOrderVipLevel(order) {
            if (!order) {
                return this.currentUserVipLevel || 0;
            }

            const candidates = [
                order.vip_level,
                order.current_vip_level,
                order.vipLevel,
                order.currentVipLevel,
                this.currentUserVipLevel,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const level = this.parseVipLevel(candidates[i]);

                if (level >= 1 && level <= 8) {
                    return level;
                }
            }

            return 0;
        },

        getAutoInvestOrderVipBoostRate(order) {
            if (!order) {
                return this.autoInvestVipBoostRate;
            }

            const orderCandidates = [
                order.vip_boost_rate,
                order.vip_boost_percent,
                order.vipBoostRate,
                order.vipBoostPercent,
            ];

            for (let i = 0; i < orderCandidates.length; i++) {
                if (orderCandidates[i] !== null && orderCandidates[i] !== undefined && orderCandidates[i] !== '') {
                    const rate = this.toNumber(orderCandidates[i]);

                    if (rate > 0) {
                        return rate;
                    }
                }
            }

            const fallbackRate = this.toNumber(this.autoInvestVipBoostRate);

            return fallbackRate > 0 ? fallbackRate : 0;
        },

        getAutoInvestOrderBaseCurrentEarningsUsd(order) {
            if (!order) {
                return 0;
            }

            const candidates = [
                order.base_current_earnings_usd,
                order.base_current_earnings,
            ];

            for (let i = 0; i < candidates.length; i++) {
                if (candidates[i] !== null && candidates[i] !== undefined && candidates[i] !== '') {
                    const directBonus = this.toNumber(candidates[i]);

                    if (directBonus !== 0 || this.getAutoInvestOrderVipBoostRate(order) <= 0) {
                        return Math.max(0, directBonus);
                    }
                }
            }

            const quantCandidates = [
                order.quant_current_earnings_usd,
            ];
            let quantEarnings = 0;

            for (let i = 0; i < quantCandidates.length; i++) {
                if (quantCandidates[i] !== null && quantCandidates[i] !== undefined && quantCandidates[i] !== '') {
                    quantEarnings = this.toNumber(quantCandidates[i]);
                    break;
                }
            }

            if (!quantEarnings) {
                return 0;
            }

            const boostRate = this.getAutoInvestOrderVipBoostRate(order);

            if (boostRate <= -100) {
                return Math.max(0, quantEarnings);
            }

            return Math.max(0, quantEarnings / (1 + (boostRate / 100)));
        },

        getAutoInvestOrderVipCurrentBonusUsd(order) {
            if (!order) {
                return 0;
            }

            const boostRate = this.getAutoInvestOrderVipBoostRate(order);
            const baseEarnings = this.getAutoInvestOrderBaseCurrentEarningsUsd(order);
            const candidates = [
                order.vip_current_bonus_usd,
                order.vip_current_bonus,
            ];

            for (let i = 0; i < candidates.length; i++) {
                if (candidates[i] !== null && candidates[i] !== undefined && candidates[i] !== '') {
                    const directBonus = this.toNumber(candidates[i]);

                    if (directBonus !== 0 || boostRate <= 0) {
                        return Math.max(0, directBonus);
                    }

                    return Math.max(0, baseEarnings * (boostRate / 100));
                }
            }

            const quantCandidates = [
                order.quant_current_earnings_usd,
            ];

            for (let i = 0; i < quantCandidates.length; i++) {
                if (quantCandidates[i] !== null && quantCandidates[i] !== undefined && quantCandidates[i] !== '') {
                    const directBonus = this.toNumber(quantCandidates[i]) - baseEarnings;

                    if (directBonus !== 0 || boostRate <= 0) {
                        return Math.max(0, directBonus);
                    }
                }
            }

            return Math.max(0, baseEarnings * (boostRate / 100));
        },

        getAutoInvestOrderQuantEarningsUsd(order) {
            if (!order) {
                return 0;
            }

            const candidates = [
                order.quant_current_earnings_usd,
            ];

            for (let i = 0; i < candidates.length; i++) {
                if (candidates[i] !== null && candidates[i] !== undefined && candidates[i] !== '') {
                    return Math.max(0, this.toNumber(candidates[i]));
                }
            }

            return Math.max(0, this.getAutoInvestOrderBaseCurrentEarningsUsd(order) + this.getAutoInvestOrderVipCurrentBonusUsd(order));
        },

        getAutoInvestOrderYieldPercent(order) {
            if (!order) {
                return 0;
            }

            const dailyValue = order.daily_profit_percent;

            if (dailyValue !== null && dailyValue !== undefined && dailyValue !== '') {
                const dailyPercent = this.toNumber(dailyValue);

                if (dailyPercent !== 0) {
                    return dailyPercent;
                }
            }

            const configuredDailyValue = order.boosted_rate !== null && order.boosted_rate !== undefined && order.boosted_rate !== ''
                ? order.boosted_rate
                : order.earned_rate;

            if (configuredDailyValue !== null && configuredDailyValue !== undefined && configuredDailyValue !== '') {
                const configuredDailyPercent = this.toNumber(configuredDailyValue);

                if (configuredDailyPercent !== 0) {
                    return configuredDailyPercent;
                }
            }

            if (dailyValue !== null && dailyValue !== undefined && dailyValue !== '') {
                return this.toNumber(dailyValue);
            }

            const fallbackCandidates = [
                order.current_total_profit_percent,
                order.current_profit_percent,
                order.total_profit_percent,
            ];

            for (let i = 0; i < fallbackCandidates.length; i++) {
                if (fallbackCandidates[i] !== null && fallbackCandidates[i] !== undefined && fallbackCandidates[i] !== '') {
                    return this.toNumber(fallbackCandidates[i]);
                }
            }

            return 0;
        },

        formatPercentValue(value, fractionDigits = 2) {
            const number = this.toNumber(value);
            const sign = number > 0 ? '+' : '';

            return sign + number.toLocaleString(undefined, {
                minimumFractionDigits: fractionDigits,
                maximumFractionDigits: fractionDigits,
            }) + '%';
        },

        formatSignedMoney(value) {
            const number = this.toNumber(value);
            const sign = number > 0 ? '+' : (number < 0 ? '-' : '');
            return sign + this.formatMoney(Math.abs(number));
        },

        goBack() {
            if (window.history.length > 1) {
                window.history.back();
                return;
            }

            this.$inertia.visit('/wallets');
        },

        openAutoInvestRiskModal() {
            if (!this.autoInvest.days) {
                this.autoInvest.days = 30;
            }

            if (!this.autoInvest.type) {
                this.autoInvest.type = 'fixed';
            }

            this.showAutoInvestRiskModal = true;
        },

        closeAutoInvestRiskModal() {
            this.showAutoInvestRiskModal = false;
        },

        openAutoInvestYieldSourceModal() {
            this.showAutoInvestYieldSourceModal = true;
        },

        closeAutoInvestYieldSourceModal() {
            this.showAutoInvestYieldSourceModal = false;
        },

        openAutoInvestInfoModal(tab = 'overview') {
            this.autoInvestInfoTab = tab;
            this.showAutoInvestInfoModal = true;
        },

        closeAutoInvestInfoModal() {
            this.showAutoInvestInfoModal = false;
        },

        setAutoInvestInfoTab(tab) {
            this.autoInvestInfoTab = tab;
        },

        toggleAutoInvestFaq(key) {
            if (this.openedAutoInvestFaq.includes(key)) {
                this.openedAutoInvestFaq = this.openedAutoInvestFaq.filter(item => item !== key);
                return;
            }

            this.openedAutoInvestFaq.push(key);
        },

        isAutoInvestFaqOpen(key) {
            return this.openedAutoInvestFaq.includes(key);
        },

        acceptAutoInvestRisk() {
            this.autoInvestRiskAccepted = true;
            this.closeAutoInvestRiskModal();

            this.$nextTick(() => {
                this.confirmAutoInvest();
            });
        },

        onToggleAutoInvest() {
            if (this.autoInvestSaving) {
                return;
            }

            if (this.autoInvest.funding) {
                if (!this.autoInvest.days) {
                    this.autoInvest.days = 30;
                }

                if (!this.autoInvest.type) {
                    this.autoInvest.type = 'fixed';
                }
            }

            /**
             * 订单结算模式下，关闭开关只隐藏新增表单，不再触发统一赎回。
             * 多笔订单需要按各自订单规则单独结算。
             */
            this.autoInvestRiskAccepted = false;
        },

        selectInvestType(type) {
            if (!this.autoInvest.funding || this.autoInvestSaving) {
                return;
            }

            this.autoInvest.type = type;
            this.autoInvestRiskAccepted = false;
        },

        selectInvestDays(days) {
            if (!this.autoInvest.funding || this.autoInvestSaving) {
                return;
            }

            this.autoInvest.days = days;
            this.autoInvestRiskAccepted = false;
        },

        onAmountInput() {
            let value = String(this.autoInvest.amount || '');

            value = value.replace(/[^\d.]/g, '');

            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }

            if (value.indexOf('.') !== -1) {
                const decimalParts = value.split('.');
                value = decimalParts[0] + '.' + decimalParts[1].slice(0, 8);
            }

            this.autoInvest.amount = value;
            this.autoInvestRiskAccepted = false;
        },

        initializeAutoInvestSymbol() {
            if (this.selectedAutoInvestSymbol) {
                return;
            }

            if (!this.autoInvestWalletOptions.length) {
                return;
            }

            /*
             * 进入页面默认选中有余额的代币。
             * autoInvestWalletOptions 已经把有虚拟余额、有可用余额的排在前面，
             * 这里再明确优先选 availableAmount > 0 的币种。
             */
            const firstAvailableOption = this.autoInvestWalletOptions.find(item => Number(item.availableAmount || 0) > 0);
            const usdtOption = this.autoInvestWalletOptions.find(item => String(item.symbol || '').toUpperCase() === 'USDT');

            const selected = firstAvailableOption || usdtOption || this.autoInvestWalletOptions[0];

            if (selected && selected.symbol) {
                this.selectedAutoInvestSymbol = String(selected.symbol).toUpperCase();
            }
        },

        toggleAutoInvestTokenDropdown() {
            this.autoInvestTokenDropdownVisible = !this.autoInvestTokenDropdownVisible;
        },

        closeAutoInvestTokenDropdown() {
            this.autoInvestTokenDropdownVisible = false;
        },

        handleAutoInvestTokenOutsideClick(event) {
            if (!this.autoInvestTokenDropdownVisible) {
                return;
            }

            const dropdown = this.$refs.autoInvestTokenDropdown;

            if (dropdown && dropdown.contains(event.target)) {
                return;
            }

            this.closeAutoInvestTokenDropdown();
        },

        selectAutoInvestSymbol(symbol) {
            this.selectedAutoInvestSymbol = String(symbol || '').toUpperCase();
            this.autoInvest.amount = '';
            this.autoInvestRiskAccepted = false;
            this.closeAutoInvestTokenDropdown();
        },

        onAutoInvestSymbolChange() {
            this.autoInvest.amount = '';
            this.autoInvestRiskAccepted = false;
        },

        getWalletSymbol(wallet) {
            if (!wallet) {
                return '';
            }

            const candidates = [
                wallet.symbol,
                wallet.currency_symbol,
                wallet.currency && wallet.currency.symbol,
                wallet.currency && wallet.currency.code,
                wallet.code,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const value = candidates[i];

                if (value !== null && value !== undefined && String(value).trim() !== '') {
                    return String(value).trim().toUpperCase();
                }
            }

            return '';
        },

        getWalletKey(wallet, index = 0) {
            if (!wallet) {
                return 'wallet-' + index;
            }

            return wallet.id
                || wallet.wallet_id
                || wallet.currency_id
                || (wallet.currency && wallet.currency.id)
                || this.getWalletSymbol(wallet)
                || ('wallet-' + index);
        },

        getWalletCurrencyId(wallet) {
            if (!wallet) {
                return null;
            }

            return wallet.currency_id
                || wallet.currencyId
                || (wallet.currency && wallet.currency.id)
                || wallet.id
                || null;
        },

        getWalletRealAmount(wallet) {
            if (!wallet) {
                return 0;
            }

            /*
             * 量化页面可投入余额展示交易账户余额。
             * 这里不要读取 balance_in_wallet，否则页面会显示资金账户余额。
             */
            const candidates = [
                wallet.available_auto_invest_trade_amount,
                wallet.available_auto_invest_trade,
                wallet.auto_invest_available_trade_amount,
                wallet.auto_invest_available_trade,
                wallet.available_in_trade,
                wallet.available_trade_balance,
                wallet.trade_available_balance,
                wallet.trade_available,
                wallet.balance_in_trade,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const value = this.toNumber(candidates[i]);

                if (value > 0) {
                    return value;
                }
            }

            return 0;
        },

        getWalletInvestableAmount(wallet) {
            if (!wallet) {
                return 0;
            }

            const virtualAmount = this.getWalletVirtualAmount(wallet);

            if (virtualAmount > 0) {
                return virtualAmount;
            }

            return this.getWalletRealAmount(wallet);
        },

        getWalletVirtualAmount(wallet) {
            if (!wallet) {
                return 0;
            }

            /*
             * 量化页面如果有虚拟账户，也优先展示虚拟交易账户余额。
             * 这里不要优先读取 balance_in_virtual_wallet，避免显示资金账户虚拟余额。
             */
            const candidates = [
                wallet.balance_in_virtual_trade,
                wallet.virtual_trade_balance,
                wallet.balance_virtual_trade,
                wallet.virtual_trade,
                wallet.virtual_balance_in_trade,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const value = this.toNumber(candidates[i]);

                if (value > 0) {
                    return value;
                }
            }

            return 0;
        },

        formatAmountInputValue(value) {
            const number = this.toNumber(value);

            if (number <= 0) {
                return '';
            }

            return String(number.toFixed(8)).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
        },

        fillMaxAutoInvestAmount() {
            if (this.autoInvestSaving) {
                return;
            }

            if (this.autoInvestMaxAmount <= 0) {
                this.showToast('No available balance for Auto Invest.', 'error');
                return;
            }

            this.autoInvest.amount = this.formatAmountInputValue(this.autoInvestMaxAmount);
            this.autoInvestRiskAccepted = false;
        },

        confirmAutoInvest() {
            if (this.autoInvestSaving) {
                return;
            }

            if (!this.autoInvest.funding) {
                return;
            }

            if (!this.autoInvest.days) {
                this.autoInvest.days = 30;
            }

            if (!this.autoInvest.type) {
                this.autoInvest.type = 'fixed';
            }

            if (this.normalizedAutoInvestAmount <= 0) {
                this.showToast('Please enter a valid investment amount.', 'error');
                return;
            }

            if (!this.autoInvestRiskAccepted) {
                this.openAutoInvestRiskModal();
                return;
            }

            const previousState = {
                funding: this.savedAutoInvest.funding,
                days: this.savedAutoInvest.days,
                type: this.savedAutoInvest.type,
                amount: this.savedAutoInvest.amount
            };

            this.saveAutoInvest(previousState);
        },

        saveAutoInvest(previousState = null) {
            if (this.autoInvestSaving) {
                return;
            }

            this.autoInvestSaving = true;

            axios.post('/wallets/saveAutoInvest', {
                status: this.autoInvest.funding ? 1 : 0,
                days: this.autoInvest.funding ? this.autoInvest.days : 0,
                investment_type: this.autoInvest.funding ? this.autoInvest.type : null,
                amount: this.autoInvest.funding ? this.normalizedAutoInvestAmount : 0,
                symbol: this.autoInvest.funding ? this.autoInvestDisplaySymbol : null,
                currency: this.autoInvest.funding ? this.autoInvestDisplaySymbol : null,
                currency_id: this.autoInvest.funding ? this.autoInvestSelectedCurrencyId : null
            }).then(() => {
                this.savedAutoInvest = {
                    funding: true,
                    days: this.autoInvest.days,
                    type: this.autoInvest.type,
                    amount: ''
                };

                this.autoInvest.funding = true;
                this.autoInvest.amount = '';
                this.autoInvestRiskAccepted = false;

                this.showToast('Saved successfully', 'success');

                this.$inertia.reload({
                    preserveScroll: true
                });
            }).catch((error) => {
                if (previousState) {
                    this.autoInvest.funding = previousState.funding;
                    this.autoInvest.days = previousState.days;
                    this.autoInvest.type = previousState.type;
                    this.autoInvest.amount = previousState.amount;
                }

                this.autoInvestRiskAccepted = false;

                let message = 'Save failed';

                if (
                    error &&
                    error.response &&
                    error.response.data &&
                    error.response.data.message
                ) {
                    message = error.response.data.message;
                }

                this.showToast(message, 'error');
            }).finally(() => {
                this.autoInvestSaving = false;
            });
        },

        getDailyYieldRate(type, days) {
            const settings = this.normalizedLcSettings || {};
            const normalizedType = type === 'flexible' ? 'flexible' : 'fixed';

            if (normalizedType === 'flexible') {
                return settings['lc' + days] || '0';
            }

            return settings['lc_dq' + days] || '0';
        },

        parseRateRange(value) {
            if (value === null || value === undefined || value === '') {
                return {
                    min: 0,
                    max: 0
                };
            }

            if (typeof value === 'object') {
                return {
                    min: this.toNumber(value.min),
                    max: this.toNumber(value.max !== undefined ? value.max : value.min),
                };
            }

            let text = String(value)
                .replace(/%/g, '')
                .replace(/－/g, '-')
                .replace(/–/g, '-')
                .replace(/—/g, '-')
                .replace(/\s+/g, '')
                .trim();

            if (!text) {
                return {
                    min: 0,
                    max: 0
                };
            }

            const parts = text.split('-').filter(item => item !== '');

            if (parts.length >= 2) {
                const min = this.toNumber(parts[0]);
                const max = this.toNumber(parts[1]);

                return {
                    min: Math.min(min, max),
                    max: Math.max(min, max),
                };
            }

            const number = this.toNumber(text);

            return {
                min: number,
                max: number,
            };
        },

        getTotalYieldRate(dailyRate, days) {
            const range = this.parseRateRange(dailyRate);
            const periodDays = Math.max(1, this.toNumber(days));

            return {
                min: range.min * periodDays,
                max: range.max * periodDays,
            };
        },

        getRateMidValue(rate) {
            const range = this.parseRateRange(rate);

            return (range.min + range.max) / 2;
        },

        formatRateNumber(value) {
            const number = this.toNumber(value);

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        formatRateRangePercent(value) {
            const range = this.parseRateRange(value);

            if (Math.abs(range.min - range.max) < 0.00000001) {
                return this.formatRateNumber(range.min) + '%';
            }

            return this.formatRateNumber(range.min) + '% - ' + this.formatRateNumber(range.max) + '%';
        },

        toNumber(value) {
            const number = Number(value);
            return Number.isFinite(number) ? number : 0;
        },

        formatMoney(value) {
            return this.toNumber(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        formatChartDate(value) {
            if (!value) {
                return '-';
            }

            const raw = String(value).split(' ')[0];
            const parts = raw.split('-');

            if (parts.length >= 3) {
                return parts[1] + '/' + parts[2];
            }

            return raw;
        },

        formatAutoInvestDate(value) {
            if (!value) {
                return '-';
            }

            return String(value).replace('T', ' ').slice(0, 19);
        },

        normalizeAutoInvestCandle(item, index = 0) {
            if (!item) {
                return null;
            }

            const close = this.toNumber(item.close !== undefined ? item.close : (item.value !== undefined ? item.value : item.amount));
            const open = this.toNumber(item.open !== undefined ? item.open : (index > 0 ? close : close));
            const high = this.toNumber(item.high !== undefined ? item.high : Math.max(open, close));
            const low = this.toNumber(item.low !== undefined ? item.low : Math.min(open, close));

            if (!Number.isFinite(close)) {
                return null;
            }

            return {
                date: item.date || item.time || item.day || item.created_at || '-',
                label: item.label || this.formatChartDate(item.date || item.time || item.day || item.created_at || ''),
                open: open,
                high: Math.max(high, open, close),
                low: Math.min(low, open, close),
                close: close,
                principal: this.toNumber(item.principal),
                earnings: this.toNumber(item.earnings),
                order_count: this.toNumber(item.order_count || item.orders_count),
            };
        },

        buildCandlesFromOrders(orders) {
            const dailyMap = {};
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            orders.forEach((order) => {
                const startValue = order.start_at || order.started_at || order.created_at;

                if (!startValue) {
                    return;
                }

                const startDate = new Date(String(startValue).replace(' ', 'T'));

                if (!Number.isFinite(startDate.getTime())) {
                    return;
                }

                startDate.setHours(0, 0, 0, 0);

                const amount = this.toNumber(
                    order.principal_usd !== undefined
                        ? order.principal_usd
                        : (order.amount_usd !== undefined ? order.amount_usd : order.amount)
                );
                const earningPrincipal = this.toNumber(
                    order.earning_principal_usd !== undefined
                        ? order.earning_principal_usd
                        : (order.earning_amount !== undefined ? order.earning_amount : amount)
                );
                const days = Math.max(1, this.toNumber(order.days || this.autoInvest.days || 30));
                const baseRate = this.toNumber(
                    order.base_rate !== undefined
                        ? order.base_rate
                        : (order.rate !== undefined ? order.rate : this.selectedInvestOption.total_rate)
                );
                const storedVipBoostRate = this.toNumber(order.vip_boost_rate);
                const vipBoostRate = storedVipBoostRate > 0
                    ? storedVipBoostRate
                    : this.toNumber(this.currentVipYieldBoost || 0);

                if (amount <= 0) {
                    return;
                }

                const diffDays = Math.max(0, Math.floor((today.getTime() - startDate.getTime()) / 86400000));
                const visibleDays = Math.max(0, Math.min(diffDays, days));

                for (let i = 0; i <= visibleDays; i++) {
                    const currentDate = new Date(startDate.getTime() + i * 86400000);
                    const key = currentDate.toISOString().slice(0, 10);
                    const earningDays = Math.min(i, days);
                    const baseProfit = earningPrincipal * (baseRate / 100) * (earningDays / days);
                    const vipProfit = baseProfit * (vipBoostRate / 100);
                    const close = amount + baseProfit + vipProfit;

                    if (!dailyMap[key]) {
                        dailyMap[key] = {
                            date: key,
                            value: 0,
                            principal: 0,
                            earnings: 0,
                            order_count: 0,
                        };
                    }

                    dailyMap[key].value += close;
                    dailyMap[key].principal += amount;
                    dailyMap[key].earnings += baseProfit + vipProfit;
                    dailyMap[key].order_count += 1;
                }
            });

            const days = Object.keys(dailyMap).sort();
            let previousClose = null;

            return days.map((date) => {
                const item = dailyMap[date];
                const close = item.value;
                const open = previousClose === null ? close : previousClose;
                const candle = {
                    date: date,
                    label: this.formatChartDate(date),
                    open: open,
                    high: Math.max(open, close),
                    low: Math.min(open, close),
                    close: close,
                    principal: item.principal,
                    earnings: item.earnings,
                    order_count: item.order_count,
                };

                previousClose = close;

                return candle;
            });
        },

        buildCandlesFromSingleSummary(earnings) {
            const principal = this.toNumber(earnings.principal || 0);

            if (principal <= 0) {
                return [];
            }

            const startAt = earnings.start_at || new Date().toISOString().slice(0, 10);
            const startDate = new Date(String(startAt).replace(' ', 'T'));

            if (!Number.isFinite(startDate.getTime())) {
                return [];
            }

            startDate.setHours(0, 0, 0, 0);

            const days = Math.max(1, this.toNumber(earnings.days || this.autoInvest.days || 30));
            const earningDays = Math.max(0, Math.min(this.toNumber(earnings.earning_days || 0), days));
            const totalEarnings = this.toNumber(earnings.quant_current_earnings || 0);
            const candles = [];
            let previousClose = principal;

            for (let i = 0; i <= earningDays; i++) {
                const currentDate = new Date(startDate.getTime() + i * 86400000);
                const key = currentDate.toISOString().slice(0, 10);
                const close = principal + (earningDays > 0 ? totalEarnings * (i / earningDays) : 0);

                candles.push({
                    date: key,
                    label: this.formatChartDate(key),
                    open: i === 0 ? close : previousClose,
                    high: Math.max(i === 0 ? close : previousClose, close),
                    low: Math.min(i === 0 ? close : previousClose, close),
                    close: close,
                    principal: principal,
                    earnings: close - principal,
                    order_count: 1,
                });

                previousClose = close;
            }

            return candles;
        },

        valueToChartY(value) {
            const min = this.autoInvestChartMin;
            const max = this.autoInvestChartMax;
            const ratio = max === min ? 0.5 : (this.toNumber(value) - min) / (max - min);

            return this.autoInvestChartPadding.top + this.autoInvestChartInnerHeight * (1 - ratio);
        },

        candleX(index) {
            if (this.autoInvestCandles.length <= 1) {
                return this.autoInvestChartPadding.left + this.autoInvestChartInnerWidth / 2;
            }

            return this.autoInvestChartPadding.left + (this.autoInvestChartInnerWidth / (this.autoInvestCandles.length - 1)) * index;
        },

        candleBodyY(candle) {
            return Math.min(this.valueToChartY(candle.open), this.valueToChartY(candle.close));
        },

        candleBodyHeight(candle) {
            return Math.max(2, Math.abs(this.valueToChartY(candle.open) - this.valueToChartY(candle.close)));
        },

        getOrderTypeValue(order) {
            const raw = String(
                order.investment_type ||
                order.type ||
                order.order_type ||
                order.plan_type ||
                order.mode ||
                ''
            ).toLowerCase();

            if (raw.includes('flex')) {
                return 'flexible';
            }

            if (raw.includes('fix') || raw.includes('term') || raw.includes('dq')) {
                return 'fixed';
            }

            const days = this.toNumber(order.days || order.period || order.duration_days || 0);

            if (days > 0 && order.maturity_date) {
                return 'fixed';
            }

            return String(this.savedAutoInvest.type || this.autoInvest.type) === 'flexible' ? 'flexible' : 'fixed';
        },

        getOrderTypeLabel(order) {
            return this.getOrderTypeValue(order) === 'flexible' ? legacyText("Flexible Term") : legacyText("Fixed Term");
        },

        isFlexibleOrder(order) {
            return this.getOrderTypeValue(order) === 'flexible';
        },

        getOrderMaturityTimestamp(order) {
            const value = order.maturity_date || order.matured_at || order.end_at || order.end_date;

            if (!value) {
                return null;
            }

            const normalized = String(value).replace(' ', 'T');
            const timestamp = Date.parse(normalized);

            return Number.isFinite(timestamp) ? timestamp : null;
        },

        isOrderMatured(order) {
            if (this.isFlexibleOrder(order)) {
                return true;
            }

            const timestamp = this.getOrderMaturityTimestamp(order);

            if (!timestamp) {
                return false;
            }

            return timestamp <= Date.now();
        },

        isActiveAutoInvestOrder(order) {
            if (!order) {
                return false;
            }

            const status = String(order.status || (order.is_active === false ? 'closed' : legacyText("active"))).toLowerCase();

            return status === 'active' && order.is_active !== false;
        },

        canRedeemOrder(order) {
            if (!order || this.autoInvestSaving || this.isRedeemingOrder(order)) {
                return false;
            }

            if (!this.isActiveAutoInvestOrder(order)) {
                return false;
            }

            if (order.is_redeemable === false) {
                return false;
            }

            if (this.isFlexibleOrder(order)) {
                return true;
            }

            return this.isOrderMatured(order);
        },

        isRedeemingOrder(order) {
            const orderKey = String(order.order_id || order.id || order.order_no || '');
            return this.redeemingOrderIds.includes(orderKey);
        },

        orderRedeemButtonText(order) {
            if (this.isRedeemingOrder(order)) {
                return legacyText("Redeeming...");
            }

            if (this.canRedeemOrder(order)) {
                return legacyText("Redeem");
            }

            if (!this.isActiveAutoInvestOrder(order)) {
                return legacyText("Closed");
            }

            return legacyText("Locked");
        },

        redeemAutoInvestOrder(order) {
            if (!this.canRedeemOrder(order)) {
                if (!this.isFlexibleOrder(order)) {
                    this.showToast('Fixed Term orders can only be redeemed after maturity.', 'error');
                }
                return;
            }

            const orderKey = String(order.order_id || order.id || order.order_no || '');

            if (!orderKey) {
                this.showToast('Order ID not found.', 'error');
                return;
            }

            this.redeemingOrderIds = [...this.redeemingOrderIds, orderKey];

            axios.post('/wallets/redeemAutoInvestOrder', {
                order_id: order.order_id || order.id || order.order_no,
                id: order.id || order.order_id || order.order_no
            }).then((response) => {
                const message = response && response.data && response.data.msg
                    ? response.data.msg
                    : legacyText("Redeemed successfully");

                this.showToast(message, 'success');

                this.$inertia.reload({
                    preserveScroll: true
                });
            }).catch((error) => {
                let message = 'Redeem failed';

                if (error && error.response && error.response.data) {
                    message = error.response.data.msg || error.response.data.message || message;
                }

                this.showToast(message, 'error');
            }).finally(() => {
                this.redeemingOrderIds = this.redeemingOrderIds.filter(item => item !== orderKey);
            });
        },

        showToast(message, type = 'success') {
            if (!this.$toast) {
                return;
            }

            this.$toast.open({
                message: this.$t ? this.$t(message) : message,
                type: type,
                duration: 2500
            });
        }
    }
})
</script>
