<script>
import AppLayout from '@/Layouts/AppLayout'
import DeleteUserForm from './DeleteUserForm'
import JetSectionBorder from '@/Jetstream/SectionBorder'
import LogoutOtherBrowserSessionsForm from './LogoutOtherBrowserSessionsForm'
import TwoFactorAuthenticationForm from './TwoFactorAuthenticationForm'
import UpdatePasswordForm from './UpdatePasswordForm'
import ProfileInformation from "./ProfileInformation";
import BankAccounts from "./BankAccounts";
import Vouchers from "./Vouchers";
import Template from '{Template}/Web/Pages/Profile/Show.template'
import SimplePie from '@/Components/Charts/SimplePie'

export default Template({
    props: ['sessions', 'slug'],

    data() {
        return {
            tabPage: '',
            page: 'info',
        }
    },

    components: {
        AppLayout,
        DeleteUserForm,
        JetSectionBorder,
        LogoutOtherBrowserSessionsForm,
        TwoFactorAuthenticationForm,
        UpdatePasswordForm,
        ProfileInformation,
        BankAccounts,
        Vouchers,
        SimplePie
    },

    mounted() {
        let slug = this.slug;

        if (!this.slug) {
            slug = this.page;
        }

        if (slug === 'email') {
            slug = 'info';
        }

        this.page = slug;
        this.tabPage = slug;

        /*
         * 获取钱包资产。
         */
        try {
            const wallets = this.$store.getters.getWallets;

            if (!wallets || !wallets.length) {
                this.$store.dispatch('fetchWallets', this.route('wallets.index'));
            }
        } catch (e) {

        }
    },

    computed: {
        wallets() {
            return (this.$store && this.$store.getters && this.$store.getters.getWallets)
                ? this.$store.getters.getWallets
                : [];
        },

        /*
         * 总资产估值：
         * 如果存在虚拟资产，显示虚拟资产估值；
         * 没有虚拟资产，才显示真实资产估值。
         */
        totalBalanceUSD() {
            if (!this.wallets || !this.wallets.length) {
                return 0;
            }

            const backendTotal = this.getBackendTotalAssetUsdValue();

            if (backendTotal > 0) {
                return backendTotal;
            }

            return this.wallets.reduce((sum, wallet) => {
                return sum + this.getWalletDisplayUsdValue(wallet);
            }, 0) + this.custodyBalanceUSD;
        },

        walletBalanceUSD() {
            const backendWalletTotal = this.getWalletSummaryNumber([
                'wallets_total_usdt',
                'wallet_total_usdt',
            ]);

            if (backendWalletTotal > 0) {
                return backendWalletTotal;
            }

            return (this.wallets || []).reduce((sum, wallet) => {
                return sum + this.getWalletDisplayUsdValue(wallet);
            }, 0);
        },

        autoInvestBalanceUSD() {
            return this.getWalletSummaryNumber([
                'auto_invest_total_usdt',
                'earn_total_usdt',
                'auto_invest_virtual_usdt',
                'auto_invest_real_usdt',
            ]);
        },

        stakingBalanceUSD() {
            return this.getWalletSummaryNumber([
                'staking_total_usdt',
            ]);
        },

        custodyBalanceUSD() {
            const backendCustody = this.getWalletSummaryNumber([
                'custody_total_usdt',
            ]);

            if (backendCustody > 0) {
                return backendCustody;
            }

            return this.autoInvestBalanceUSD + this.stakingBalanceUSD;
        },

        firstCryptoSymbol() {
            const crypto = (this.wallets || []).find(w => w.type !== 'fiat');

            return crypto ? crypto.symbol : null;
        },

        /*
         * 资产分布：
         * 如果该币种有虚拟资产，则该币种只按虚拟资产进入图表；
         * 如果没有虚拟资产，则按真实资产进入图表。
         */
        assetAllocationData() {
            const palette = [
                '#6366F1',
                '#22C55E',
                '#F59E0B',
                '#EF4444',
                '#06B6D4',
                '#A855F7',
                '#84CC16',
                '#F97316'
            ];

            const map = (this.wallets || []).map(wallet => {
                const value = this.getWalletDisplayUsdValue(wallet);

                return {
                    label: wallet.symbol,
                    symbol: wallet.symbol,
                    value,
                    isVirtual: this.hasVirtualBalance(wallet),
                };
            }).filter(item => item.value > 0)
                .sort((a, b) => b.value - a.value);

            const adjustment = this.assetAllocationAdjustmentUSD;

            if (adjustment > 0) {
                const stableIndex = map.findIndex(item => {
                    return ['USDT', 'USD', 'USDC', 'BUSD', 'DAI'].includes(String(item.symbol || '').toUpperCase());
                });

                if (stableIndex >= 0) {
                    map[stableIndex].value += adjustment;
                } else if (map.length > 0) {
                    map[0].value += adjustment;
                } else {
                    map.push({
                        label: 'USDT',
                        symbol: 'USDT',
                        value: adjustment,
                        isVirtual: false,
                    });
                }

                map.sort((a, b) => b.value - a.value);
            }

            /*
             * 只显示前 6 个，其余合并到 Others。
             */
            const top = map.slice(0, 6);
            const others = map.slice(6);
            const othersValue = others.reduce((sum, item) => sum + item.value, 0);

            if (othersValue > 0) {
                top.push({
                    label: 'Others',
                    symbol: 'Others',
                    value: othersValue,
                    isVirtual: false,
                });
            }

            return top.map((item, index) => ({
                ...item,
                color: palette[index % palette.length],
            }));
        },

        walletAllocationTotalUSD() {
            return (this.wallets || []).reduce((sum, wallet) => {
                return sum + this.getWalletDisplayUsdValue(wallet);
            }, 0);
        },

        assetAllocationAdjustmentUSD() {
            return Math.max(this.totalBalanceUSD - this.walletAllocationTotalUSD, 0);
        },

        assetTotalUSD() {
            return this.totalBalanceUSD;
        }
    },

    methods: {
        setPage(page, external = false) {
            if (page === 'email') {
                page = 'info';
            }

            if (external) {
                return this.$inertia.visit(this.route(page, { lite: 1 }));
            }

            this.$inertia.visit(this.route('profile.show', { slug: page, lite: 1 }));
        },

        setTabPage() {
            if (this.tabPage === 'email') {
                this.tabPage = 'info';
            }

            if (this.tabPage == "api_tokens") {
                this.$inertia.visit(this.route('api-tokens.index'));
            } else if (this.tabPage == "kyc") {
                this.$inertia.visit(this.route('user.kyc'));
            } else {
                this.$inertia.visit(this.route('profile.show', this.tabPage));
            }
        },

        toNumber(value) {
            const number = parseFloat(value);

            if (Number.isNaN(number) || !Number.isFinite(number)) {
                return 0;
            }

            return number;
        },

        getWalletSummarySource() {
            return (this.wallets || []).find(wallet => {
                return this.toNumber(wallet.all_assets_total_usdt) > 0
                    || this.toNumber(wallet.auto_invest_total_usdt) > 0
                    || this.toNumber(wallet.earn_total_usdt) > 0
                    || this.toNumber(wallet.staking_total_usdt) > 0
                    || this.toNumber(wallet.custody_total_usdt) > 0
                    || this.toNumber(wallet.wallets_total_usdt) > 0;
            }) || null;
        },

        getWalletSummaryNumber(fields = []) {
            const source = this.getWalletSummarySource();

            if (!source) {
                return 0;
            }

            for (let i = 0; i < fields.length; i++) {
                const value = this.toNumber(source[fields[i]]);

                if (value > 0) {
                    return value;
                }
            }

            return 0;
        },

        getBackendTotalAssetUsdValue() {
            return this.getWalletSummaryNumber([
                'all_assets_total_usdt',
                'total_usdt_balance',
                'display_usdt_balance',
                'converted_all_tokens_to_usdt',
            ]);
        },

        /*
         * 判断钱包是否存在虚拟资产。
         *
         * 兼容这些字段：
         * balance_in_virtual_wallet
         * balance_in_virtual_wallet_usd
         * balance_in_virtual_trade
         * balance_in_virtual_trade_usd
         */
        hasVirtualBalance(wallet) {
            if (!wallet) {
                return false;
            }

            const virtualWallet = this.toNumber(wallet.balance_in_virtual_wallet);
            const virtualWalletUsd = this.toNumber(wallet.balance_in_virtual_wallet_usd);
            const virtualTrade = this.toNumber(wallet.balance_in_virtual_trade);
            const virtualTradeUsd = this.toNumber(wallet.balance_in_virtual_trade_usd);

            return virtualWallet > 0
                || virtualWalletUsd > 0
                || virtualTrade > 0
                || virtualTradeUsd > 0;
        },

        /*
         * 真实资产 USD。
         */
        getWalletRealUsdValue(wallet) {
            if (!wallet) {
                return 0;
            }

            const fundingUsd = this.toNumber(wallet.balance_in_wallet_usd);
            const tradeUsd = this.toNumber(wallet.balance_in_trade_usd);

            return fundingUsd + tradeUsd;
        },

        /*
         * 虚拟资产 USD。
         *
         * 优先使用后端已经返回的 USD 字段。
         * 如果没有 USD 字段，则尝试用币种价格换算。
         */
        getWalletVirtualUsdValue(wallet) {
            if (!wallet) {
                return 0;
            }

            const virtualWalletUsd = this.toNumber(wallet.balance_in_virtual_wallet_usd);
            const virtualTradeUsd = this.toNumber(wallet.balance_in_virtual_trade_usd);

            const virtualUsdTotal = virtualWalletUsd + virtualTradeUsd;

            if (virtualUsdTotal > 0) {
                return virtualUsdTotal;
            }

            const virtualWallet = this.toNumber(wallet.balance_in_virtual_wallet);
            const virtualTrade = this.toNumber(wallet.balance_in_virtual_trade);
            const virtualTotal = virtualWallet + virtualTrade;

            if (virtualTotal <= 0) {
                return 0;
            }

            const price = this.getWalletUsdPrice(wallet);

            if (price > 0) {
                return virtualTotal * price;
            }

            /*
             * 稳定币兜底：
             * 如果后端没有 USD 估值，但币种是稳定币，则按 1:1 显示。
             */
            const symbol = String(wallet.symbol || '').toUpperCase();

            if (['USD', 'USDT', 'USDC', 'BUSD', 'DAI'].includes(symbol)) {
                return virtualTotal;
            }

            return 0;
        },

        /*
         * 当前页面最终展示的资产：
         * 有虚拟资产 => 只显示虚拟资产；
         * 没有虚拟资产 => 显示真实资产。
         */
        getWalletDisplayUsdValue(wallet) {
            if (this.hasVirtualBalance(wallet)) {
                return this.getWalletVirtualUsdValue(wallet);
            }

            return this.getWalletRealUsdValue(wallet);
        },

        getWalletUsdPrice(wallet) {
            if (!wallet) {
                return 0;
            }

            const candidates = [
                wallet.price_usd,
                wallet.usd_price,
                wallet.rate_usd,
                wallet.usd_rate,
                wallet.price,
                wallet.rate,
                wallet.currency && wallet.currency.price_usd,
                wallet.currency && wallet.currency.usd_price,
                wallet.currency && wallet.currency.price,
                wallet.currency && wallet.currency.rate,
            ];

            for (let i = 0; i < candidates.length; i++) {
                const value = this.toNumber(candidates[i]);

                if (value > 0) {
                    return value;
                }
            }

            return 0;
        },
    }
});
</script>
