<script>
import WalletRefresh from '@/Mixins/WalletRefresh';
import DisplayPreferences from '@/Mixins/DisplayPreferences';
import Template from '{Template}/Web/Pages/Wallet/WalletsTrading.template'
import AppLayout from '@/Layouts/AppLayout'
import AccountActions from '@/Components/AccountActions.vue'
import JetCheckbox from '@/Jetstream/Checkbox'
import TableFilter from "@/Mixins/Filter/TableFilter";
import IconFilter from "@/Components/Table/IconFilter";

export default Template({
    walletOverview: true,
    components: {
        AccountActions,
        AppLayout,
        JetCheckbox,
        TableFilter,
        IconFilter
    },

    data() {
        return {
            fetchBalanceInterval : null,
            totalBalance: null,
            activeType: 'coin',
        }
    },

    mixins: [TableFilter,DisplayPreferences,WalletRefresh],

    beforeDestroy () {

        clearInterval(this.fetchBalanceInterval)
    },

    mounted() {
        this.setFilter('balance_in_trade_usd', 'desc', true);

        this.fetchTotalBalance();

        this.fetchBalanceInterval = setInterval(() => {
            this.fetchTotalBalance();
        }, 10000);
    },

    computed: {
        rawWallets() {
            return this.$store && this.$store.getters
                ? (this.$store.getters.getWallets || [])
                : [];
        },

        /**
         * 只要任意虚拟账户资产大于 0，
         * Trading Account 页面全部展示虚拟账户资产。
         * 页面不显示任何虚拟标识。
         */
        useVirtualAssets() {
            return (this.rawWallets || []).some(wallet => {
                return this.getVirtualWalletTotalAmount(wallet) > 0
                    || this.getVirtualWalletTotalUSD(wallet) > 0;
            });
        },

        displayTotalBalance() {
            if (!this.walletBalanceVisible) return null;
            if (this.useVirtualAssets) {
                return this.virtualTradingTotalBalance;
            }

            return this.totalBalance;
        },

        virtualTradingTotalBalance() {
            const totalUsd = (this.rawWallets || []).reduce((sum, wallet) => {
                return sum
                    + this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_futures', 'balance_in_virtual_futures_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_future', 'balance_in_virtual_future_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_futures_margin', 'balance_in_virtual_futures_margin_usd');
            }, 0);

            return {
                totatUsdBalance: this.formatUsd(totalUsd),
                totalBtcBalance: this.formatBtc(this.convertUsdToBtc(totalUsd)),
            };
        },

        wallets() {
            if (!this.walletBalanceVisible) return [];
            const direction = this.filter.filterDirection == 'desc' ? 'desc' : 'asc';

            let wallets = _.map(this.rawWallets, (wallet) => {
                return this.decorateWalletForDisplay(wallet);
            });

            wallets = _.orderBy(wallets, [
                (wallet) => {
                    if (this.filter.filterBy == 'symbol' || this.filter.filterBy == 'type') {
                        return wallet[this.filter.filterBy];
                    }

                    if (this.filter.filterBy == 'balance_in_trade_usd') {
                        return parseFloat(wallet.display_balance_in_trade_usd) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_trade') {
                        return parseFloat(wallet.display_balance_in_trade) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_order_usd') {
                        return parseFloat(wallet.display_balance_in_order_usd) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_order') {
                        return parseFloat(wallet.display_balance_in_order) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_futures_usd') {
                        return parseFloat(wallet.display_balance_in_futures_usd) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_futures') {
                        return parseFloat(wallet.display_balance_in_futures) || 0;
                    }

                    return parseFloat(wallet[this.filter.filterBy]) || 0;
                },

                (wallet) => {
                    const usdValue = parseFloat(wallet.display_balance_in_trade_usd) || 0;

                    if (usdValue === 0) {
                        return parseFloat(wallet.display_balance_in_trade) || 0;
                    }

                    return 0;
                }
            ], [direction, direction]);

            return _.filter(wallets, (wallet) => {
                let sortedBy = true;

                if (this.filter.sortBy == "balance") {
                    const usdBalance = parseFloat(wallet.display_balance_in_trade_usd) || 0;
                    const cryptoBalance = parseFloat(wallet.display_balance_in_trade) || 0;
                    const orderUsdBalance = parseFloat(wallet.display_balance_in_order_usd) || 0;
                    const orderCryptoBalance = parseFloat(wallet.display_balance_in_order) || 0;

                    sortedBy = usdBalance > 0
                        || cryptoBalance > 0
                        || orderUsdBalance > 0
                        || orderCryptoBalance > 0;
                }

                const search = this.filter.search ? this.filter.search.toLowerCase() : '';

                const matchesSearch = (
                    String(wallet.symbol || '').toLowerCase().includes(search) ||
                    String(wallet.currency || '').toLowerCase().includes(search)
                );

                const stock=['stock','etf'].includes(wallet.asset_category);
                const matchesType=this.activeType==='stocks'?stock:this.activeType==='coin'?wallet.type==='coin'&&!stock:wallet.type==='fiat';

                return matchesSearch && sortedBy && matchesType;
            });
        },

        pricesInUsd() {
            return this.$store.getters.getWallet(this.currency.symbol);
        },
    },

    methods: {
        firstSymbolByType(type, action = null) {
            const list = _.filter(this.rawWallets, (w) => {
                if (w.type !== type) {
                    return false;
                }

                if (action === 'deposit') {
                    return this.canDeposit(w);
                }

                if (action === 'withdraw') {
                    return this.canWithdraw(w);
                }

                return true;
            });
            return list && list.length ? list[0].symbol : null;
        },

        canDeposit(wallet) {
            return wallet && [true, 1, '1', 'true'].includes(wallet.deposit_status);
        },

        canWithdraw(wallet) {
            return wallet && [true, 1, '1', 'true'].includes(wallet.withdraw_status);
        },

        fetchTotalBalance() {
            axios.get(this.route('currencies.api.rates-balance'), {
                params: {
                    wallet: 'trade'
                }
            }).then((response) => {
                this.totalBalance = response.data;
            })
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
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

        formatAmount(value, decimals = 8) {
            const number = this.toNumber(value);

            if (number === 0) {
                return '0';
            }

            if (Math.abs(number) >= 1) {
                return number.toLocaleString(undefined, {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: decimals,
                });
            }

            return number.toLocaleString(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 10,
            });
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

        getWalletUsdRate(wallet) {
            const amountFields = [
                'balance_in_wallet',
                'balance_in_trade',
                'balance_in_order',
                'balance_in_withdraw',
                'balance_in_futures',
                'balance_in_future',
                'balance_in_futures_margin',

                'balance_in_virtual_wallet',
                'balance_in_virtual_trade',
                'balance_in_virtual_order',
                'balance_in_virtual_withdraw',
                'balance_in_virtual_futures',
                'balance_in_virtual_future',
                'balance_in_virtual_futures_margin',
            ];

            const usdFields = [
                'balance_in_wallet_usd',
                'balance_in_trade_usd',
                'balance_in_order_usd',
                'balance_in_withdraw_usd',
                'balance_in_futures_usd',
                'balance_in_future_usd',
                'balance_in_futures_margin_usd',

                'balance_in_virtual_wallet_usd',
                'balance_in_virtual_trade_usd',
                'balance_in_virtual_order_usd',
                'balance_in_virtual_withdraw_usd',
                'balance_in_virtual_futures_usd',
                'balance_in_virtual_future_usd',
                'balance_in_virtual_futures_margin_usd',
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

        getVirtualWalletTotalAmount(wallet) {
            return this.getWalletNumber(wallet, 'balance_in_virtual_wallet')
                + this.getWalletNumber(wallet, 'balance_in_virtual_trade')
                + this.getWalletNumber(wallet, 'balance_in_virtual_order')
                + this.getWalletNumber(wallet, 'balance_in_virtual_withdraw')
                + this.getWalletNumber(wallet, 'balance_in_virtual_futures')
                + this.getWalletNumber(wallet, 'balance_in_virtual_future')
                + this.getWalletNumber(wallet, 'balance_in_virtual_futures_margin');
        },

        getVirtualWalletTotalUSD(wallet) {
            return this.getWalletUsd(wallet, 'balance_in_virtual_wallet', 'balance_in_virtual_wallet_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_withdraw', 'balance_in_virtual_withdraw_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_futures', 'balance_in_virtual_futures_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_future', 'balance_in_virtual_future_usd')
                + this.getWalletUsd(wallet, 'balance_in_virtual_futures_margin', 'balance_in_virtual_futures_margin_usd');
        },

        getFuturesAmount(wallet, isVirtual) {
            if (isVirtual) {
                return this.getWalletNumber(wallet, 'balance_in_virtual_futures')
                    + this.getWalletNumber(wallet, 'balance_in_virtual_future')
                    + this.getWalletNumber(wallet, 'balance_in_virtual_futures_margin');
            }

            return this.getWalletNumber(wallet, 'balance_in_futures')
                + this.getWalletNumber(wallet, 'balance_in_future')
                + this.getWalletNumber(wallet, 'balance_in_futures_margin');
        },

        getFuturesUsd(wallet, isVirtual) {
            if (isVirtual) {
                return this.getWalletUsd(wallet, 'balance_in_virtual_futures', 'balance_in_virtual_futures_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_future', 'balance_in_virtual_future_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_futures_margin', 'balance_in_virtual_futures_margin_usd');
            }

            return this.getWalletUsd(wallet, 'balance_in_futures', 'balance_in_futures_usd')
                + this.getWalletUsd(wallet, 'balance_in_future', 'balance_in_future_usd')
                + this.getWalletUsd(wallet, 'balance_in_futures_margin', 'balance_in_futures_margin_usd');
        },

        decorateWalletForDisplay(wallet) {
            const displayWallet = {
                ...wallet,
            };

            if (this.useVirtualAssets) {
                const virtualTrade = this.getWalletNumber(wallet, 'balance_in_virtual_trade');
                const virtualOrder = this.getWalletNumber(wallet, 'balance_in_virtual_order');
                const virtualFutures = this.getFuturesAmount(wallet, true);

                const virtualTradeUsd = this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd');
                const virtualOrderUsd = this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd');
                const virtualFuturesUsd = this.getFuturesUsd(wallet, true);

                displayWallet.display_balance_in_trade = this.formatAmount(virtualTrade);
                displayWallet.display_balance_in_trade_usd = this.formatUsd(virtualTradeUsd);
                displayWallet.display_balance_in_order = this.formatAmount(virtualOrder);
                displayWallet.display_balance_in_order_usd = this.formatUsd(virtualOrderUsd);
                displayWallet.display_balance_in_futures = this.formatAmount(virtualFutures);
                displayWallet.display_balance_in_futures_usd = this.formatUsd(virtualFuturesUsd);

                displayWallet.display_balance_in_trade_raw = virtualTrade;
                displayWallet.display_balance_in_trade_usd_raw = virtualTradeUsd;
                displayWallet.display_balance_in_order_raw = virtualOrder;
                displayWallet.display_balance_in_order_usd_raw = virtualOrderUsd;
                displayWallet.display_balance_in_futures_raw = virtualFutures;
                displayWallet.display_balance_in_futures_usd_raw = virtualFuturesUsd;

                return displayWallet;
            }

            const realFutures = this.getFuturesAmount(wallet, false);
            const realFuturesUsd = this.getFuturesUsd(wallet, false);

            displayWallet.display_balance_in_trade = wallet.balance_in_trade;
            displayWallet.display_balance_in_trade_usd = wallet.balance_in_trade_usd;
            displayWallet.display_balance_in_order = wallet.balance_in_order;
            displayWallet.display_balance_in_order_usd = wallet.balance_in_order_usd;

            displayWallet.display_balance_in_futures = this.formatAmount(realFutures);
            displayWallet.display_balance_in_futures_usd = this.formatUsd(realFuturesUsd);

            displayWallet.display_balance_in_trade_raw = this.toNumber(wallet.balance_in_trade);
            displayWallet.display_balance_in_trade_usd_raw = this.toNumber(wallet.balance_in_trade_usd);
            displayWallet.display_balance_in_order_raw = this.toNumber(wallet.balance_in_order);
            displayWallet.display_balance_in_order_usd_raw = this.toNumber(wallet.balance_in_order_usd);
            displayWallet.display_balance_in_futures_raw = realFutures;
            displayWallet.display_balance_in_futures_usd_raw = realFuturesUsd;

            return displayWallet;
        },

        convertUsdToBtc(usdValue) {
            const usd = this.toNumber(usdValue);

            if (usd <= 0) {
                return 0;
            }

            const btcWallet = (this.rawWallets || []).find(wallet => {
                return String(wallet.symbol || '').toUpperCase() === 'BTC';
            });

            if (btcWallet) {
                const btcAmount =
                    this.getWalletNumber(btcWallet, 'balance_in_wallet')
                    + this.getWalletNumber(btcWallet, 'balance_in_trade')
                    + this.getWalletNumber(btcWallet, 'balance_in_order')
                    + this.getWalletNumber(btcWallet, 'balance_in_withdraw')
                    + this.getFuturesAmount(btcWallet, false)
                    + this.getWalletNumber(btcWallet, 'balance_in_virtual_wallet')
                    + this.getWalletNumber(btcWallet, 'balance_in_virtual_trade')
                    + this.getWalletNumber(btcWallet, 'balance_in_virtual_order')
                    + this.getWalletNumber(btcWallet, 'balance_in_virtual_withdraw')
                    + this.getFuturesAmount(btcWallet, true);

                const btcUsd =
                    this.getWalletUsd(btcWallet, 'balance_in_wallet', 'balance_in_wallet_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_trade', 'balance_in_trade_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_order', 'balance_in_order_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_withdraw', 'balance_in_withdraw_usd')
                    + this.getFuturesUsd(btcWallet, false)
                    + this.getWalletUsd(btcWallet, 'balance_in_virtual_wallet', 'balance_in_virtual_wallet_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_virtual_withdraw', 'balance_in_virtual_withdraw_usd')
                    + this.getFuturesUsd(btcWallet, true);

                if (btcAmount > 0 && btcUsd > 0) {
                    return usd / (btcUsd / btcAmount);
                }
            }

            if (this.totalBalance && this.totalBalance.totatUsdBalance && this.totalBalance.totalBtcBalance) {
                const apiUsd = this.toNumber(this.totalBalance.totatUsdBalance);
                const apiBtc = this.toNumber(this.totalBalance.totalBtcBalance);

                if (apiUsd > 0 && apiBtc > 0) {
                    return usd / (apiUsd / apiBtc);
                }
            }

            return 0;
        },

        goDeposit() {
            const type = this.activeType === 'fiat' ? 'fiat' : 'coin';
            const symbol = this.firstSymbolByType(type, 'deposit');

            if (!symbol) {
                return;
            }

            if (type === 'fiat') {
                this.$inertia.visit(this.route('wallets.deposit.fiat', { symbol }));
            } else {
                this.$inertia.visit(this.route('wallets.deposit.crypto', { symbol }));
            }
        },

        goWithdraw() {
            const type = this.activeType === 'fiat' ? 'fiat' : 'coin';
            const symbol = this.firstSymbolByType(type, 'withdraw');

            if (!symbol) {
                return;
            }

            if (type === 'fiat') {
                this.$inertia.visit(this.route('wallets.withdraw.fiat', { symbol }));
            } else {
                this.$inertia.visit(this.route('wallets.withdraw.crypto', { symbol }));
            }
        },

        goTransfer() {
            this.$inertia.visit(this.route('wallets.transfer'));
        }
    }
})
</script>
