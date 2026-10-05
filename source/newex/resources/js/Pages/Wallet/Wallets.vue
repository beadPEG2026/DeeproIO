<script>
import WalletRefresh from '@/Mixins/WalletRefresh';
import DisplayPreferences from '@/Mixins/DisplayPreferences';
import Template from '{Template}/Web/Pages/Wallet/Wallets.template'
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
            totalBalance: null,
            activeType: 'coin',
        }
    },

    mixins: [TableFilter,DisplayPreferences,WalletRefresh],

    beforeDestroy() {

    },

    mounted() {
        this.setFilter('balance_in_wallet_usd', 'desc', true);

    },

    computed: {
        rawWallets() {
            return this.$store && this.$store.getters
                ? (this.$store.getters.getWallets || [])
                : [];
        },

        displayTotalBalance() {
            if (!this.walletBalanceVisible) return null;
            return this.fundingTotalBalance;
        },

        fundingTotalBalance() {
            const totalUsd = (this.rawWallets || []).reduce((sum, wallet) => {
                return sum
                    + this.getFundingWalletUsd(wallet)
                    + this.getWalletUsd(wallet, 'balance_in_withdraw', 'balance_in_withdraw_usd')
                    + this.getWalletUsd(wallet, 'balance_in_lc', 'balance_in_lc_usd');
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

                    if (this.filter.filterBy == 'balance_in_wallet_usd') {
                        return parseFloat(wallet.display_balance_in_wallet_usd) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_wallet') {
                        return parseFloat(wallet.display_balance_in_wallet) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_withdraw_usd') {
                        return parseFloat(wallet.display_balance_in_withdraw_usd) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_withdraw') {
                        return parseFloat(wallet.display_balance_in_withdraw) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_lc_usd') {
                        return parseFloat(wallet.display_balance_in_lc_usd) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_lc') {
                        return parseFloat(wallet.display_balance_in_lc) || 0;
                    }

                    return parseFloat(wallet[this.filter.filterBy]) || 0;
                },

                (wallet) => {
                    const usdValue = parseFloat(wallet.display_balance_in_wallet_usd) || 0;

                    if (usdValue === 0) {
                        return parseFloat(wallet.display_balance_in_wallet) || 0;
                    }

                    return 0;
                }
            ], [direction, direction]);

            return _.filter(wallets, (wallet) => {
                let sortedBy = true;

                if (this.filter.sortBy == "balance") {
                    const walletUsdBalance = parseFloat(wallet.display_balance_in_wallet_usd) || 0;
                    const walletBalance = parseFloat(wallet.display_balance_in_wallet) || 0;
                    const withdrawUsdBalance = parseFloat(wallet.display_balance_in_withdraw_usd) || 0;
                    const withdrawBalance = parseFloat(wallet.display_balance_in_withdraw) || 0;
                    const lcUsdBalance = parseFloat(wallet.display_balance_in_lc_usd) || 0;
                    const lcBalance = parseFloat(wallet.display_balance_in_lc) || 0;

                    sortedBy = walletUsdBalance > 0
                        || walletBalance > 0
                        || withdrawUsdBalance > 0
                        || withdrawBalance > 0
                        || lcUsdBalance > 0
                        || lcBalance > 0;
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
                'balance_in_lc',

                'balance_in_virtual_wallet',
                'balance_in_virtual_trade',
                'balance_in_virtual_order',

                'total_balance_in_wallet',
                'total_balance_in_trade',
                'total_balance_in_order',
            ];

            const usdFields = [
                'balance_in_wallet_usd',
                'balance_in_trade_usd',
                'balance_in_order_usd',
                'balance_in_withdraw_usd',
                'balance_in_lc_usd',

                'balance_in_virtual_wallet_usd',
                'balance_in_virtual_trade_usd',
                'balance_in_virtual_order_usd',

                'total_balance_in_wallet_usd',
                'total_balance_in_trade_usd',
                'total_balance_in_order_usd',
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

        getFundingWalletAmount(wallet) {
            const totalAmount = this.getWalletNumber(wallet, 'total_balance_in_wallet');

            if (totalAmount > 0) {
                return totalAmount;
            }

            const realAmount = this.getWalletNumber(wallet, 'balance_in_wallet');
            const virtualAmount = this.getWalletNumber(wallet, 'balance_in_virtual_wallet');

            if (virtualAmount > 0) {
                return realAmount + virtualAmount;
            }

            return realAmount;
        },

        getFundingWalletUsd(wallet) {
            const totalUsd = this.toNumber(wallet && wallet.total_balance_in_wallet_usd);

            if (totalUsd > 0) {
                return totalUsd;
            }

            const realUsd = this.getWalletUsd(wallet, 'balance_in_wallet', 'balance_in_wallet_usd');
            const virtualUsd = this.getWalletUsd(wallet, 'balance_in_virtual_wallet', 'balance_in_virtual_wallet_usd');

            if (virtualUsd > 0) {
                return realUsd + virtualUsd;
            }

            return realUsd;
        },

        decorateWalletForDisplay(wallet) {
            const displayWallet = {
                ...wallet,
            };

            const fundingWallet = this.getFundingWalletAmount(wallet);
            const fundingWalletUsd = this.getFundingWalletUsd(wallet);

            const withdraw = this.getWalletNumber(wallet, 'balance_in_withdraw');
            const withdrawUsd = this.getWalletUsd(wallet, 'balance_in_withdraw', 'balance_in_withdraw_usd');

            const locked = this.getWalletNumber(wallet, 'balance_in_lc');
            const lockedUsd = this.getWalletUsd(wallet, 'balance_in_lc', 'balance_in_lc_usd');

            displayWallet.display_balance_in_wallet = this.formatAmount(fundingWallet);
            displayWallet.display_balance_in_wallet_usd = this.formatUsd(fundingWalletUsd);

            displayWallet.display_balance_in_withdraw = this.formatAmount(withdraw);
            displayWallet.display_balance_in_withdraw_usd = this.formatUsd(withdrawUsd);

            displayWallet.display_balance_in_lc = this.formatAmount(locked);
            displayWallet.display_balance_in_lc_usd = this.formatUsd(lockedUsd);

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
                    + this.getWalletNumber(btcWallet, 'balance_in_lc')
                    + this.getWalletNumber(btcWallet, 'balance_in_virtual_wallet')
                    + this.getWalletNumber(btcWallet, 'balance_in_virtual_trade')
                    + this.getWalletNumber(btcWallet, 'balance_in_virtual_order');

                const btcUsd =
                    this.getWalletUsd(btcWallet, 'balance_in_wallet', 'balance_in_wallet_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_trade', 'balance_in_trade_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_order', 'balance_in_order_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_withdraw', 'balance_in_withdraw_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_lc', 'balance_in_lc_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_virtual_wallet', 'balance_in_virtual_wallet_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_virtual_trade', 'balance_in_virtual_trade_usd')
                    + this.getWalletUsd(btcWallet, 'balance_in_virtual_order', 'balance_in_virtual_order_usd');

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
