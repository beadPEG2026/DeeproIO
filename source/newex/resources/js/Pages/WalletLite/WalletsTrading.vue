<script>
import Template from '{Template}/Web/Pages/WalletLite/WalletsTrading.template'
import AppLayout from '@/Layouts/AppLayout'
import AccountActions from '@/Components/AccountActions.vue'
import JetCheckbox from '@/Jetstream/Checkbox'
import TableFilter from "@/Mixins/Filter/TableFilter";
import IconFilter from "@/Components/Table/IconFilter";
import WalletOverview from '@/Components/WalletOverview.vue'

export default Template({
    components: {
        AccountActions,
        AppLayout,
        JetCheckbox,
        TableFilter,
        IconFilter,
        WalletOverview
    },
    data() {
        return {
            fetchBalanceInterval : null,
            apiTotalBalance: null,
            activeType: 'coin',
            showWalletSheet: false,
            selectedWallet: null,
        }
    },
    mixins: [TableFilter],
    beforeDestroy () {
        if (typeof window !== 'undefined') {
            window.removeEventListener('resize', this.onResize, { passive: true })
        }

        clearInterval(this.fetchBalanceInterval)
    },
    mounted() {

        this.setFilter('balance_in_trade_usd', 'desc', true);

        if(_.isEmpty(this.rawWallets)) {
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        }
        this.onResize();
        window.addEventListener('resize', this.onResize, { passive: true })

        this.fetchTotalBalance();

        this.fetchBalanceInterval = setInterval(() => {
            this.fetchTotalBalance();
        }, 10000);
    },
    computed: {
        rawWallets: function () {
            return this.$store && this.$store.getters
                ? (this.$store.getters.getWallets || [])
                : [];
        },

        /**
         * 跟 PC 端一致：只要任意交易资产存在虚拟账户余额，
         * lite 端交易账户页面就整体显示虚拟账户余额。
         * 页面不额外显示“虚拟”标识。
         */
        useVirtualAssets: function () {
            return (this.rawWallets || []).some((wallet) => {
                return this.getVirtualWalletTotalAmount(wallet) > 0
                    || this.getVirtualWalletTotalUSD(wallet) > 0;
            });
        },

        totalBalance: function () {
            if(this.useVirtualAssets) {
                return this.virtualTradingTotalBalance;
            }

            return this.apiTotalBalance;
        },

        displayTotalBalance: function () {
            return this.totalBalance;
        },

        virtualTradingTotalBalance: function () {
            const totalUsd = (this.rawWallets || []).reduce((sum, wallet) => {
                return sum + this.getTradingUsd(wallet, true);
            }, 0);

            return {
                totatUsdBalance: this.formatUsd(totalUsd),
                totalBtcBalance: this.formatBtc(this.convertUsdToBtc(totalUsd)),
            };
        },

        wallets: function () {
            const direction = this.filter.filterDirection == 'desc' ? 'desc' : 'asc';
            
            let wallets = _.map(this.rawWallets, (wallet) => {
                return this.decorateTradingWalletForDisplay(wallet);
            });

            wallets = _.orderBy(wallets, [
                (wallet) => {
                    if(this.filter.filterBy == 'symbol' || this.filter.filterBy == 'type') {
                        return wallet[this.filter.filterBy];
                    }

                    if(this.filter.filterBy == 'balance_in_trade_usd') {
                        return parseFloat(wallet.balance_in_trade_usd) || 0;
                    }

                    if(this.filter.filterBy == 'balance_in_trade') {
                        return parseFloat(wallet.balance_in_trade) || 0;
                    }

                    if(this.filter.filterBy == 'balance_in_order_usd') {
                        return parseFloat(wallet.balance_in_order_usd) || 0;
                    }

                    if(this.filter.filterBy == 'balance_in_order') {
                        return parseFloat(wallet.balance_in_order) || 0;
                    }

                    if(this.filter.filterBy == 'balance_in_futures_usd') {
                        return parseFloat(wallet.balance_in_futures_usd) || 0;
                    }

                    if(this.filter.filterBy == 'balance_in_futures') {
                        return parseFloat(wallet.balance_in_futures) || 0;
                    }

                    return parseFloat(wallet[this.filter.filterBy]) || 0;
                },
                (wallet) => {
                    const usdValue = parseFloat(wallet.balance_in_trade_usd) || 0;
                    if (usdValue === 0) {
                        return parseFloat(wallet.balance_in_trade) || 0;
                    }
                    return 0;
                }
            ], [direction, direction]);

            return _.filter(wallets, (wallet) => {
                let sortedBy = true;

                if(this.filter.sortBy == "balance") {
                    const usdBalance = parseFloat(wallet.balance_in_trade_usd) || 0;
                    const cryptoBalance = parseFloat(wallet.balance_in_trade) || 0;
                    const orderUsdBalance = parseFloat(wallet.balance_in_order_usd) || 0;
                    const orderCryptoBalance = parseFloat(wallet.balance_in_order) || 0;
                    const futuresUsdBalance = parseFloat(wallet.balance_in_futures_usd) || 0;
                    const futuresCryptoBalance = parseFloat(wallet.balance_in_futures) || 0;

                    sortedBy = usdBalance > 0
                        || cryptoBalance > 0
                        || orderUsdBalance > 0
                        || orderCryptoBalance > 0
                        || futuresUsdBalance > 0
                        || futuresCryptoBalance > 0;
                }

                const search = this.filter.search ? this.filter.search.toLowerCase() : '';
                const matchesSearch = (String(wallet.symbol || '').toLowerCase().includes(search) ||
                    String(wallet.currency || '').toLowerCase().includes(search));

                const stock=['stock','etf'].includes(wallet.asset_category);
                const matchesType=this.activeType==='stocks'?stock:this.activeType==='coin'?wallet.type==='coin'&&!stock:wallet.type==='fiat';

                return matchesSearch && sortedBy && matchesType;
            });
        },
        pricesInUsd: function () {
            if(!this.currency || !this.currency.symbol) {
                return null;
            }

            return this.$store.getters.getWallet(this.currency.symbol);
        },
    },
    methods: {
      onResize () {
        if(window.innerWidth >= 986) {
          this.$inertia.visit(this.route('wallets.trading'));
        } else {

        }
      },
        fetchTotalBalance() {
            axios.get(this.route('currencies.api.rates-balance'), {
                params: {
                    wallet: 'trade'
                }
            }).then((response) => {
                this.apiTotalBalance = response.data;
            })
        },
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
        toNumber(value) {
            if(value === null || value === undefined || value === '') {
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
        formatPlainAmount(value, decimals = 8) {
            const number = this.toNumber(value);

            if(number === 0) {
                return '0';
            }

            return number.toFixed(decimals).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
        },
        getWalletNumber(wallet, key) {
            if(!wallet || !key) {
                return 0;
            }

            return this.toNumber(wallet[key]);
        },
        getWalletUsd(wallet, amountKey, usdKey) {
            if(!wallet) {
                return 0;
            }

            const directUsd = this.toNumber(wallet[usdKey]);

            if(directUsd > 0) {
                return directUsd;
            }

            const amount = this.getWalletNumber(wallet, amountKey);

            if(amount <= 0) {
                return 0;
            }

            const rate = this.getWalletUsdRate(wallet);

            if(rate <= 0) {
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

            for(let i = 0; i < amountFields.length; i++) {
                const amount = this.toNumber(wallet[amountFields[i]]);
                const usd = this.toNumber(wallet[usdFields[i]]);

                if(amount > 0 && usd > 0) {
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
            if(isVirtual) {
                return this.getWalletNumber(wallet, 'balance_in_virtual_futures')
                    + this.getWalletNumber(wallet, 'balance_in_virtual_future')
                    + this.getWalletNumber(wallet, 'balance_in_virtual_futures_margin');
            }

            return this.getWalletNumber(wallet, 'balance_in_futures')
                + this.getWalletNumber(wallet, 'balance_in_future')
                + this.getWalletNumber(wallet, 'balance_in_futures_margin');
        },
        getFuturesUsd(wallet, isVirtual) {
            if(isVirtual) {
                return this.getWalletUsd(wallet, 'balance_in_virtual_futures', 'balance_in_virtual_futures_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_future', 'balance_in_virtual_future_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_futures_margin', 'balance_in_virtual_futures_margin_usd');
            }

            return this.getWalletUsd(wallet, 'balance_in_futures', 'balance_in_futures_usd')
                + this.getWalletUsd(wallet, 'balance_in_future', 'balance_in_future_usd')
                + this.getWalletUsd(wallet, 'balance_in_futures_margin', 'balance_in_futures_margin_usd');
        },
        getTradingAmount(wallet, isVirtual) {
            if(isVirtual) {
                return this.getWalletNumber(wallet, 'balance_in_virtual_trade')
                    + this.getWalletNumber(wallet, 'balance_in_virtual_order')
                    + this.getFuturesAmount(wallet, true);
            }

            return this.getWalletNumber(wallet, 'balance_in_trade')
                + this.getWalletNumber(wallet, 'balance_in_order')
                + this.getFuturesAmount(wallet, false);
        },
        getTradingUsd(wallet, isVirtual) {
            if(isVirtual) {
                return this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd')
                    + this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd')
                    + this.getFuturesUsd(wallet, true);
            }

            return this.getWalletUsd(wallet, 'balance_in_trade', 'balance_in_trade_usd')
                + this.getWalletUsd(wallet, 'balance_in_order', 'balance_in_order_usd')
                + this.getFuturesUsd(wallet, false);
        },
        getAllWalletAmount(wallet) {
            return this.getWalletNumber(wallet, 'balance_in_wallet')
                + this.getWalletNumber(wallet, 'balance_in_trade')
                + this.getWalletNumber(wallet, 'balance_in_order')
                + this.getWalletNumber(wallet, 'balance_in_withdraw')
                + this.getFuturesAmount(wallet, false)
                + this.getVirtualWalletTotalAmount(wallet);
        },
        getAllWalletUsd(wallet) {
            return this.getWalletUsd(wallet, 'balance_in_wallet', 'balance_in_wallet_usd')
                + this.getWalletUsd(wallet, 'balance_in_trade', 'balance_in_trade_usd')
                + this.getWalletUsd(wallet, 'balance_in_order', 'balance_in_order_usd')
                + this.getWalletUsd(wallet, 'balance_in_withdraw', 'balance_in_withdraw_usd')
                + this.getFuturesUsd(wallet, false)
                + this.getVirtualWalletTotalUSD(wallet);
        },
        convertUsdToBtc(usdValue) {
            const usd = this.toNumber(usdValue);

            if(usd <= 0) {
                return 0;
            }

            const btcWallet = (this.rawWallets || []).find((wallet) => {
                return String(wallet.symbol || '').toUpperCase() === 'BTC';
            });

            if(btcWallet) {
                const btcAmount = this.getAllWalletAmount(btcWallet);
                const btcUsd = this.getAllWalletUsd(btcWallet);

                if(btcAmount > 0 && btcUsd > 0) {
                    return usd / (btcUsd / btcAmount);
                }
            }

            if(this.apiTotalBalance && this.apiTotalBalance.totatUsdBalance && this.apiTotalBalance.totalBtcBalance) {
                const apiUsd = this.toNumber(this.apiTotalBalance.totatUsdBalance);
                const apiBtc = this.toNumber(this.apiTotalBalance.totalBtcBalance);

                if(apiUsd > 0 && apiBtc > 0) {
                    return usd / (apiUsd / apiBtc);
                }
            }

            return 0;
        },
        decorateTradingWalletForDisplay(wallet) {
            const displayWallet = {
                ...wallet,
            };

            if(!this.useVirtualAssets) {
                const realFutures = this.getFuturesAmount(wallet, false);
                const realFuturesUsd = this.getFuturesUsd(wallet, false);

                displayWallet.balance_in_futures = this.formatPlainAmount(realFutures, 8);
                displayWallet.balance_in_futures_usd = this.formatPlainAmount(realFuturesUsd, 2);
                displayWallet.display_balance_in_trade = displayWallet.balance_in_trade;
                displayWallet.display_balance_in_trade_usd = displayWallet.balance_in_trade_usd;
                displayWallet.display_balance_in_order = displayWallet.balance_in_order;
                displayWallet.display_balance_in_order_usd = displayWallet.balance_in_order_usd;
                displayWallet.display_balance_in_futures = displayWallet.balance_in_futures;
                displayWallet.display_balance_in_futures_usd = displayWallet.balance_in_futures_usd;
                return displayWallet;
            }

            const virtualTrade = this.getWalletNumber(wallet, 'balance_in_virtual_trade');
            const virtualOrder = this.getWalletNumber(wallet, 'balance_in_virtual_order');
            const virtualFutures = this.getFuturesAmount(wallet, true);
            const virtualTradingTotal = virtualTrade + virtualOrder + virtualFutures;

            const virtualTradeUsd = this.getWalletUsd(wallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd');
            const virtualOrderUsd = this.getWalletUsd(wallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd');
            const virtualFuturesUsd = this.getFuturesUsd(wallet, true);
            const virtualTradingTotalUsd = virtualTradeUsd + virtualOrderUsd + virtualFuturesUsd;

            displayWallet.real_balance_in_trade = wallet.balance_in_trade;
            displayWallet.real_balance_in_trade_usd = wallet.balance_in_trade_usd;
            displayWallet.real_balance_in_order = wallet.balance_in_order;
            displayWallet.real_balance_in_order_usd = wallet.balance_in_order_usd;
            displayWallet.real_balance_in_futures = this.getFuturesAmount(wallet, false);
            displayWallet.real_balance_in_futures_usd = this.getFuturesUsd(wallet, false);

            displayWallet.balance_in_trade = this.formatPlainAmount(virtualTradingTotal, 8);
            displayWallet.balance_in_trade_usd = this.formatPlainAmount(virtualTradingTotalUsd, 2);
            displayWallet.balance_in_order = this.formatPlainAmount(virtualOrder, 8);
            displayWallet.balance_in_order_usd = this.formatPlainAmount(virtualOrderUsd, 2);
            displayWallet.balance_in_futures = this.formatPlainAmount(virtualFutures, 8);
            displayWallet.balance_in_futures_usd = this.formatPlainAmount(virtualFuturesUsd, 2);

            displayWallet.display_balance_in_trade = displayWallet.balance_in_trade;
            displayWallet.display_balance_in_trade_usd = displayWallet.balance_in_trade_usd;
            displayWallet.display_balance_in_order = displayWallet.balance_in_order;
            displayWallet.display_balance_in_order_usd = displayWallet.balance_in_order_usd;
            displayWallet.display_balance_in_futures = displayWallet.balance_in_futures;
            displayWallet.display_balance_in_futures_usd = displayWallet.balance_in_futures_usd;

            displayWallet.display_balance_in_trade_raw = virtualTradingTotal;
            displayWallet.display_balance_in_trade_usd_raw = virtualTradingTotalUsd;
            displayWallet.display_balance_in_order_raw = virtualOrder;
            displayWallet.display_balance_in_order_usd_raw = virtualOrderUsd;
            displayWallet.display_balance_in_futures_raw = virtualFutures;
            displayWallet.display_balance_in_futures_usd_raw = virtualFuturesUsd;

            return displayWallet;
        },
        goDeposit() {
            const type = this.activeType === 'fiat' ? 'fiat' : 'coin';
            const symbol = this.firstSymbolByType(type, 'deposit');
            if (!symbol) { return; }
            if (type === 'fiat') {
                this.$inertia.visit(this.route('wallets.deposit.fiat', { symbol }));
            } else {
                this.$inertia.visit(this.route('wallets.deposit.crypto', { symbol }));
            }
        },
        goWithdraw() {
            const type = this.activeType === 'fiat' ? 'fiat' : 'coin';
            const symbol = this.firstSymbolByType(type, 'withdraw');
            if (!symbol) { return; }
            if (type === 'fiat') {
                this.$inertia.visit(this.route('wallets.withdraw.fiat', { symbol }));
            } else {
                this.$inertia.visit(this.route('wallets.withdraw.crypto', { symbol }));
            }
        },
        goTransfer() {
            this.$inertia.visit(this.route('wallets.transfer'));
        },
        openWalletModal(wallet) {
            this.selectedWallet = wallet;
            this.showWalletSheet = true;
        },
        closeWalletModal() {
            this.showWalletSheet = false;
            this.selectedWallet = null;
        }
    }
})
</script>

<style scoped>
/* Make each wallet row appear clickable */
.tables.mob-table-wallets tbody tr { cursor: pointer; }

/* Add a right-arrow chevron to the wallet row cell */
.tables.mob-table-wallets tbody tr td.nopaddings {
  position: relative;
  padding-right: 34px; /* space for the arrow */
}
.tables.mob-table-wallets tbody tr:hover td.nopaddings::after {
  transform: translateY(-50%) translateX(2px);
  opacity: 0.9;
}
</style>
