<script>
import WalletRefresh from '@/Mixins/WalletRefresh';
import DisplayPreferences from '@/Mixins/DisplayPreferences';
import Template from '{Template}/Web/Pages/Wallet/NewWallets.template'
import AppLayout from '@/Layouts/AppLayout'
import AccountActions from '@/Components/AccountActions.vue'
import CurrencyAvatar from '@/Components/CurrencyAvatar.vue'
import {walletUiCopy} from '@/Functions/WalletUiCopy.mjs';

export default Template({
    mixins:[DisplayPreferences,WalletRefresh],
    components: {
        AccountActions,
        CurrencyAvatar,
        AppLayout
    },

    mounted() {
        if (!this.$store.getters.getMarkets?.length) this.$store.dispatch('fetchMarkets',this.route('markets.api.ticker'));

    },

    data() {
        return {
            search: '',
            hideSmallAssets: false,
            searchOpen: false,
            balanceHidden: false,
            message: '',
            messageTimer: null,
        }
    },

    beforeDestroy() { clearTimeout(this.messageTimer); },

    computed: {
        fundingAvailableUSD() {return this.wallets.reduce((sum,w)=>sum+this.getWalletUsd(w,this.useVirtualAssets?'balance_in_virtual_wallet':'balance_in_wallet',this.useVirtualAssets?'balance_in_virtual_wallet_usd':'balance_in_wallet_usd'),0);},
        frozenBalanceUSD() {const prefix=this.useVirtualAssets?'balance_in_virtual_':'balance_in_';return this.wallets.reduce((sum,w)=>sum+this.getWalletUsd(w,prefix+'order',prefix+'order_usd')+this.getWalletUsd(w,prefix+'withdraw',prefix+'withdraw_usd'),0);},
        wallets() {
            return (this.$store && this.$store.getters && this.$store.getters.getWallets)
                ? this.$store.getters.getWallets
                : [];
        },

        /**
         * 只要任意币种存在虚拟资产，则整个页面全部展示虚拟资产。
         * 页面不显示任何虚拟标识。
         */
        useVirtualAssets() {
            return (this.wallets || []).some(wallet => {
                return this.getVirtualWalletTotalAmount(wallet) > 0
                    || this.getVirtualWalletTotalUSD(wallet) > 0;
            });
        },

        fundingBalanceUSD() {
            return this.wallets.reduce((sum, wallet) => {
                if (this.useVirtualAssets) {
                    return sum
                        + this.getWalletUsd(wallet, 'balance_in_virtual_wallet', 'balance_in_virtual_wallet_usd')
                        + this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd')
                        + this.getWalletUsd(wallet, 'balance_in_virtual_withdraw', 'balance_in_virtual_withdraw_usd');
                }

                return sum
                    + this.getWalletUsd(wallet, 'balance_in_wallet', 'balance_in_wallet_usd')
                    + this.getWalletUsd(wallet, 'balance_in_order', 'balance_in_order_usd')
                    + this.getWalletUsd(wallet, 'balance_in_withdraw', 'balance_in_withdraw_usd');
            }, 0);
        },

        tradingBalanceUSD() {
            return this.wallets.reduce((sum, wallet) => {
                if (this.useVirtualAssets) {
                    return sum + this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd');
                }

                return sum + this.getWalletUsd(wallet, 'balance_in_trade', 'balance_in_trade_usd');
            }, 0);
        },

        stakingBalanceUSD() {
            return this.getWalletSummaryValue('staking_total_usdt');
        },

        earnBalanceUSD() {
            const summaryValue = this.getWalletSummaryValue('earn_total_usdt', 'auto_invest_total_usdt');

            if (summaryValue > 0) {
                return summaryValue;
            }

            return this.wallets.reduce((sum, wallet) => {
                return sum + this.getWalletUsd(wallet, 'balance_in_lc', 'balance_in_lc_usd');
            }, 0);
        },

        custodyBalanceUSD() {
            const summaryValue = this.getWalletSummaryValue('custody_total_usdt');

            if (summaryValue > 0) {
                return summaryValue;
            }

            return this.stakingBalanceUSD + this.earnBalanceUSD;
        },

        totalBalanceUSD() {
            const apiTotal = this.getWalletSummaryValue('all_assets_total_usdt');

            if (apiTotal > 0) {
                return apiTotal;
            }

            return this.fundingBalanceUSD + this.tradingBalanceUSD + this.custodyBalanceUSD;
        },

        totalBalanceBTC() {
            const btcWallet = (this.wallets || []).find(wallet => {
                return this.getWalletSymbol(wallet).toUpperCase() === 'BTC';
            });
            const market = (this.$store.getters.getMarkets || []).find(m=>m.base_currency==='BTC' && m.quote_currency==='USDT');
            const btcPrice = (btcWallet ? this.getWalletUsdRate(btcWallet) : 0) || this.toNumber(market?.last);
            return btcPrice > 0 ? this.totalBalanceUSD / btcPrice : null;
        },

        accountCards() {
            return [
                {
                    key: 'funding',
                    title: this.translate('Funding available'),
                    subtitle: this.translate('Available for deposits, withdrawals and transfers.'),
                    value: this.fundingAvailableUSD,
                    routeType: 'route',
                    routeName: 'wallets',
                },
                {
                    key: 'trading',
                    title: this.translate('Trading available'),
                    subtitle: this.translate('Used for spot, futures and order margin.'),
                    value: this.tradingBalanceUSD,
                    routeType: 'route',
                    routeName: 'wallets.trading',
                },
                {
                    key: 'custody',
                    title: this.translate('Earn Account'),
                    subtitle: this.translate('Assets allocated to wealth management products.'),
                    value: this.custodyBalanceUSD,
                    path: '/wallets/investfunding',
                    details: [
                        {
                            label: this.translate('Staking'),
                            value: this.stakingBalanceUSD,
                        },
                        {
                            label: this.translate('Earn Account'),
                            value: this.earnBalanceUSD,
                        },
                    ],
                },
            ];
        },

        assetRows() {
            const rows = (this.wallets || []).map(wallet => {
                let fundingAmount = 0;
                let tradingAmount = 0;
                let orderAmount = 0;
                let withdrawAmount = 0;

                let fundingUsd = 0;
                let tradingUsd = 0;
                let orderUsd = 0;
                let withdrawUsd = 0;

                if (this.useVirtualAssets) {
                    fundingAmount = this.getWalletNumber(wallet, 'balance_in_virtual_wallet');
                    tradingAmount = this.getWalletNumber(wallet, 'balance_in_virtual_trade');
                    orderAmount = this.getWalletNumber(wallet, 'balance_in_virtual_order');
                    withdrawAmount = this.getWalletNumber(wallet, 'balance_in_virtual_withdraw');

                    fundingUsd = this.getWalletUsd(wallet, 'balance_in_virtual_wallet', 'balance_in_virtual_wallet_usd');
                    tradingUsd = this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd');
                    orderUsd = this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd');
                    withdrawUsd = this.getWalletUsd(wallet, 'balance_in_virtual_withdraw', 'balance_in_virtual_withdraw_usd');
                } else {
                    fundingAmount = this.getWalletNumber(wallet, 'balance_in_wallet');
                    tradingAmount = this.getWalletNumber(wallet, 'balance_in_trade');
                    orderAmount = this.getWalletNumber(wallet, 'balance_in_order');
                    withdrawAmount = this.getWalletNumber(wallet, 'balance_in_withdraw');

                    fundingUsd = this.getWalletUsd(wallet, 'balance_in_wallet', 'balance_in_wallet_usd');
                    tradingUsd = this.getWalletUsd(wallet, 'balance_in_trade', 'balance_in_trade_usd');
                    orderUsd = this.getWalletUsd(wallet, 'balance_in_order', 'balance_in_order_usd');
                    withdrawUsd = this.getWalletUsd(wallet, 'balance_in_withdraw', 'balance_in_withdraw_usd');
                }

                const availableAmount = fundingAmount + orderAmount + withdrawAmount;
                const availableUsd = fundingUsd + orderUsd + withdrawUsd;

                const totalAmount = availableAmount + tradingAmount;
                const totalUsd = availableUsd + tradingUsd;

                return {
                    id: wallet.id || wallet.currency_id || wallet.symbol,
                    symbol: this.getWalletSymbol(wallet),
                    name: this.getWalletName(wallet),
                    icon: this.getWalletIcon(wallet),

                    fundingAmount: availableAmount,
                    tradingAmount: tradingAmount,
                    orderAmount: orderAmount,
                    withdrawAmount: withdrawAmount,
                    totalAmount: totalAmount,

                    fundingUsd: availableUsd,
                    tradingUsd: tradingUsd,
                    orderUsd: orderUsd,
                    withdrawUsd: withdrawUsd,
                    totalUsd: totalUsd,

                    raw: wallet,
                };
            });

            let filtered = rows;

            if (this.hideSmallAssets) {
                filtered = filtered.filter(row => row.totalAmount > 0 || row.totalUsd > 0);
            }

            if (this.search) {
                const keyword = String(this.search).trim().toLowerCase();

                if (keyword) {
                    filtered = filtered.filter(row => {
                        return String(row.symbol).toLowerCase().includes(keyword)
                            || String(row.name).toLowerCase().includes(keyword);
                    });
                }
            }

            return filtered.sort((a, b) => b.totalUsd - a.totalUsd);
        },

        hasAssets() {
            return this.assetRows && this.assetRows.length > 0;
        },
    },

    methods: {
        walletCopy(key) { return walletUiCopy(this,key); },
        unavailable() {this.message=this.walletCopy('This feature is not available yet');clearTimeout(this.messageTimer);this.messageTimer=setTimeout(()=>this.message='',2600);},
        depositAsset(asset) {
            if (![true,1,'1','true'].includes(asset.raw.deposit_status)) return this.unavailable();
            this.$inertia.visit(this.route(asset.raw.type==='fiat'?'wallets.deposit.fiat':'wallets.deposit.crypto',{symbol:asset.symbol}));
        },
        tradeAsset(asset) {
            const markets=(this.$store.getters.getMarkets || []).filter(m=>[true,1,'1','true'].includes(m.status));
            const market=markets.find(m=>m.base_currency===asset.symbol && m.quote_currency==='USDT') || markets.find(m=>m.base_currency===asset.symbol) || markets.find(m=>m.quote_currency===asset.symbol);
            if (!market) return this.unavailable();
            this.$inertia.visit(this.route('market',market.name));
        },
        translate(value) {
            return this.$t ? this.$t(value) : value;
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        getWalletNumber(wallet, key) {
            if (!wallet || !key) {
                return 0;
            }

            return this.toNumber(wallet[key]);
        },

        getWalletUsd(wallet, amountKey, usdKey) {
            if (!wallet) {
                return 0;
            }

            const directUsd = this.toNumber(wallet[usdKey]);

            if (directUsd > 0) {
                return directUsd;
            }

            const amount = this.getWalletNumber(wallet, amountKey);

            if (amount <= 0) {
                return 0;
            }

            const rate = this.getWalletUsdRate(wallet);

            if (rate <= 0) {
                return 0;
            }

            return amount * rate;
        },

        getWalletSummaryValue(...keys) {
            for (let i = 0; i < (this.wallets || []).length; i++) {
                const wallet = this.wallets[i];

                for (let j = 0; j < keys.length; j++) {
                    const value = this.toNumber(wallet[keys[j]]);

                    if (value > 0) {
                        return value;
                    }
                }
            }

            return 0;
        },

        getWalletUsdRate(wallet) {
            const directRate = this.toNumber(wallet.usd_rate)
                || this.toNumber(wallet.currency_usd_rate)
                || this.toNumber(wallet.price_usd)
                || this.toNumber(wallet.currency_price_usd)
                || this.toNumber(wallet.rate);

            if (directRate > 0) {
                return directRate;
            }

            if (this.isStableUsdSymbol(this.getWalletSymbol(wallet))) {
                return 1;
            }

            const amountFields = [
                'balance_in_wallet',
                'balance_in_trade',
                'balance_in_order',
                'balance_in_withdraw',
                'balance_in_virtual_wallet',
                'balance_in_virtual_trade',
                'balance_in_virtual_order',
                'balance_in_virtual_withdraw',
            ];

            const usdFields = [
                'balance_in_wallet_usd',
                'balance_in_trade_usd',
                'balance_in_order_usd',
                'balance_in_withdraw_usd',
                'balance_in_virtual_wallet_usd',
                'balance_in_virtual_trade_usd',
                'balance_in_virtual_order_usd',
                'balance_in_virtual_withdraw_usd',
            ];

            for (let i = 0; i < amountFields.length; i++) {
                const amount = this.toNumber(wallet[amountFields[i]]);
                const usd = this.toNumber(wallet[usdFields[i]]);

                if (amount > 0 && usd > 0) {
                    return usd / amount;
                }
            }

            return 0;
        },

        isStableUsdSymbol(symbol) {
            return ['USDT', 'USD', 'USDC', 'BUSD', 'DAI', 'TUSD', 'USDP', 'USDD']
                .includes(String(symbol || '').trim().toUpperCase());
        },

        getVirtualWalletTotalAmount(wallet) {
            return this.getWalletNumber(wallet, 'balance_in_virtual_wallet')
                + this.getWalletNumber(wallet, 'balance_in_virtual_trade')
                + this.getWalletNumber(wallet, 'balance_in_virtual_order')
                + this.getWalletNumber(wallet, 'balance_in_virtual_withdraw');
        },

        getVirtualWalletTotalUSD(wallet) {
            return this.getWalletUsd(wallet, 'balance_in_virtual_wallet', 'balance_in_virtual_wallet_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_withdraw', 'balance_in_virtual_withdraw_usd');
        },

        getRealWalletTotalAmount(wallet) {
            return this.getWalletNumber(wallet, 'balance_in_wallet')
                + this.getWalletNumber(wallet, 'balance_in_trade')
                + this.getWalletNumber(wallet, 'balance_in_order')
                + this.getWalletNumber(wallet, 'balance_in_withdraw');
        },

        getRealWalletTotalUSD(wallet) {
            return this.getWalletUsd(wallet, 'balance_in_wallet', 'balance_in_wallet_usd')
                + this.getWalletUsd(wallet, 'balance_in_trade', 'balance_in_trade_usd')
                + this.getWalletUsd(wallet, 'balance_in_order', 'balance_in_order_usd')
                + this.getWalletUsd(wallet, 'balance_in_withdraw', 'balance_in_withdraw_usd');
        },

        formatUsd(value) {
            return this.toNumber(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        formatBtc(value) {
            return this.toNumber(value).toLocaleString(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 8,
            });
        },

        formatAmount(value) {
            const number = this.toNumber(value);

            if (number === 0) {
                return '0';
            }

            if (Math.abs(number) >= 1) {
                return number.toLocaleString(undefined, {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 8,
                });
            }

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 10,
            });
        },

        getWalletSymbol(wallet) {
            return wallet.symbol
                || wallet.currency_symbol
                || (wallet.currency && wallet.currency.symbol)
                || '';
        },

        getWalletName(wallet) {
            return wallet.name
                || wallet.currency_name
                || (typeof wallet.currency === 'string' ? wallet.currency : '')
                || (wallet.currency && wallet.currency.name)
                || this.getWalletSymbol(wallet);
        },

        getWalletIcon(wallet) {
            if (wallet.logo) {
                return wallet.logo;
            }

            if (wallet.icon) {
                return wallet.icon;
            }

            if (wallet.file && wallet.file.path) {
                return wallet.file.path;
            }

            if (wallet.currency && wallet.currency.file && wallet.currency.file.path) {
                return wallet.currency.file.path;
            }

            return null;
        },

        openAccount(card) {
            if (card.displayOnly) {
                return;
            }

            if (card.routeType === 'route') {
                this.$inertia.visit(this.route(card.routeName));
                return;
            }

            if (card.path) {
                this.$inertia.visit(card.path);
            }
        },

        goDeposit() {
            const wallet = (this.wallets || []).find(item => item && [true, 1, '1', 'true'].includes(item.deposit_status));

            if (!wallet) {
                return;
            }

            const symbol = this.getWalletSymbol(wallet);

            if (wallet.type === 'fiat') {
                this.$inertia.visit(this.route('wallets.deposit.fiat', { symbol }));
                return;
            }

            this.$inertia.visit(this.route('wallets.deposit.crypto', { symbol }));
        },

        goWithdraw() {
            const wallet = (this.wallets || []).find(item => item && [true, 1, '1', 'true'].includes(item.withdraw_status));

            if (!wallet) {
                return;
            }

            const symbol = this.getWalletSymbol(wallet);

            if (wallet.type === 'fiat') {
                this.$inertia.visit(this.route('wallets.withdraw.fiat', { symbol }));
                return;
            }

            this.$inertia.visit(this.route('wallets.withdraw.crypto', { symbol }));
        },

        goTransfer() {
            this.$inertia.visit('/wallets/transfer');
        },
    }
})
</script>
<style src="../../../css/wallet-overview.css"></style>
