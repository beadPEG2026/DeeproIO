<script>
import AccountActions from "@/Components/AccountActions.vue";
import Template from '{Template}/Web/Pages/Wallet/Investfunding.template'
import AppLayout from '@/Layouts/AppLayout'
import JetCheckbox from '@/Jetstream/Checkbox'
import TableFilter from "@/Mixins/Filter/TableFilter";
import IconFilter from "@/Components/Table/IconFilter";

export default Template({
    components: {
        AccountActions,
        AppLayout,
        JetCheckbox,
        TableFilter,
        IconFilter
    },

    data() {
        return {
            fetchBalanceInterval: null,
            totalBalance: null,
            activeType: 'coin',
        }
    },

    mixins: [TableFilter],

    beforeDestroy() {
        if (typeof window !== 'undefined') {
            window.removeEventListener('resize', this.onResize, { passive: true })
        }

        clearInterval(this.fetchBalanceInterval)
    },

    mounted() {
        // 按 lc 余额排序
        this.setFilter('balance_in_lc', 'desc', true);

        if (_.isEmpty(this.rawWallets)) {
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
        rawWallets() {
            return this.$store && this.$store.getters
                ? (this.$store.getters.getWallets || [])
                : [];
        },

        displayTotalBalance() {
            if (!_.isEmpty(this.rawWallets)) {
                return this.investFundingTotalBalance;
            }

            return this.totalBalance;
        },

        investFundingTotalBalance() {
            let totalUsd = this.getWalletSummaryValue('custody_total_usdt');

            if (totalUsd <= 0) {
                totalUsd = (this.rawWallets || []).reduce((sum, wallet) => {
                    return sum + this.getWalletUsd(wallet, 'balance_in_lc', 'balance_in_lc_usd');
                }, 0);
            }

            return {
                totatUsdBalance: this.formatUsd(totalUsd),
                totalBtcBalance: this.formatBtc(this.convertUsdToBtc(totalUsd)),
            };
        },

        wallets() {
            const direction = this.filter.filterDirection == 'desc' ? 'desc' : 'asc';

            let wallets = _.map(this.rawWallets, (wallet) => {
                return this.decorateWalletForDisplay(wallet);
            });

            wallets = _.orderBy(wallets, [
                // 主排序：按当前选择字段
                (wallet) => {
                    if (this.filter.filterBy == 'symbol' || this.filter.filterBy == 'type') {
                        return wallet[this.filter.filterBy];
                    }

                    if (this.filter.filterBy == 'balance_in_lc_usd') {
                        return parseFloat(wallet.display_balance_in_lc_usd) || 0;
                    }

                    if (this.filter.filterBy == 'balance_in_lc') {
                        return parseFloat(wallet.display_balance_in_lc) || 0;
                    }

                    return parseFloat(wallet[this.filter.filterBy]) || 0;
                },
                // 次排序：如果主值为 0，就按 lc 余额排序
                (wallet) => {
                    const lcValue = parseFloat(wallet.display_balance_in_lc) || 0;
                    if (lcValue === 0) {
                        return parseFloat(wallet.display_balance_in_lc_usd) || 0;
                    }
                    return 0;
                }
            ], [direction, direction]);

            return _.filter(wallets, (wallet) => {
                let sortedBy = true;

                // LC 钱包按 balance_in_lc 过滤
                if (this.filter.sortBy == "balance") {
                    const lcBalance = parseFloat(wallet.display_balance_in_lc) || 0;
                    const lcUsdBalance = parseFloat(wallet.display_balance_in_lc_usd) || 0;
                    sortedBy = lcBalance > 0 || lcUsdBalance > 0;
                }

                const search = (this.filter.search || '').toLowerCase();

                const matchesSearch =
                    wallet.symbol.toLowerCase().includes(search) ||
                    wallet.currency.toLowerCase().includes(search);

                const matchesType =
                    this.activeType === 'coin'
                        ? wallet.type === 'coin'
                        : wallet.type === 'fiat';

                return matchesSearch && sortedBy && matchesType;
            });
        }
    },

    methods: {
        firstSymbolByType(type, action = null) {
            const list = _.filter(this.$store.getters.getWallets, (w) => {
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

        onResize() {
            if (window.innerWidth < 986) {
                this.$inertia.visit(this.route('wallets.investfunding.lite'));
            }
        },

        fetchTotalBalance() {
            this.totalBalance = this.investFundingTotalBalance;
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

        getWalletUsdRate(wallet) {
            const directRate = this.toNumber(wallet.usd_rate)
                || this.toNumber(wallet.currency_usd_rate)
                || this.toNumber(wallet.price_usd)
                || this.toNumber(wallet.currency_price_usd)
                || this.toNumber(wallet.rate);

            if (directRate > 0) {
                return directRate;
            }

            if (this.isStableUsdSymbol(wallet && wallet.symbol)) {
                return 1;
            }

            const amountFields = [
                'balance_in_lc',
                'balance_in_wallet',
                'balance_in_trade',
                'balance_in_order',
                'balance_in_withdraw',
            ];

            const usdFields = [
                'balance_in_lc_usd',
                'balance_in_wallet_usd',
                'balance_in_trade_usd',
                'balance_in_order_usd',
                'balance_in_withdraw_usd',
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

        decorateWalletForDisplay(wallet) {
            const displayWallet = {
                ...wallet,
            };
            const locked = this.getWalletNumber(wallet, 'balance_in_lc');
            const lockedUsd = this.getWalletUsd(wallet, 'balance_in_lc', 'balance_in_lc_usd');

            displayWallet.display_balance_in_lc = this.formatAmount(locked);
            displayWallet.display_balance_in_lc_usd = this.formatUsd(lockedUsd);

            return displayWallet;
        },

        getWalletSummaryValue(...keys) {
            for (let i = 0; i < (this.rawWallets || []).length; i++) {
                const wallet = this.rawWallets[i];

                for (let j = 0; j < keys.length; j++) {
                    const value = this.toNumber(wallet[keys[j]]);

                    if (value > 0) {
                        return value;
                    }
                }
            }

            return 0;
        },

        convertUsdToBtc(usdValue) {
            const usd = this.toNumber(usdValue);

            if (usd <= 0) {
                return 0;
            }

            const btcWallet = (this.rawWallets || []).find(wallet => {
                return String(wallet.symbol || '').toUpperCase() === 'BTC';
            });

            if (!btcWallet) {
                return 0;
            }

            const btcAmount = this.getWalletNumber(btcWallet, 'balance_in_lc')
                + this.getWalletNumber(btcWallet, 'balance_in_wallet')
                + this.getWalletNumber(btcWallet, 'balance_in_trade')
                + this.getWalletNumber(btcWallet, 'balance_in_order')
                + this.getWalletNumber(btcWallet, 'balance_in_withdraw');

            const btcUsd = this.getWalletUsd(btcWallet, 'balance_in_lc', 'balance_in_lc_usd')
                + this.getWalletUsd(btcWallet, 'balance_in_wallet', 'balance_in_wallet_usd')
                + this.getWalletUsd(btcWallet, 'balance_in_trade', 'balance_in_trade_usd')
                + this.getWalletUsd(btcWallet, 'balance_in_order', 'balance_in_order_usd')
                + this.getWalletUsd(btcWallet, 'balance_in_withdraw', 'balance_in_withdraw_usd');

            if (btcAmount > 0 && btcUsd > 0) {
                return usd / (btcUsd / btcAmount);
            }

            return 0;
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
        }
    }
})
</script>
