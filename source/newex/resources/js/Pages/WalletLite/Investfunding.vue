<script>
import AccountActions from "@/Components/AccountActions.vue";
import Template from '{Template}/Web/Pages/WalletLite/Investfunding.template'
import AppLayout from '@/Layouts/AppLayout'
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
            totalBalance: null,
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

        this.setFilter('balance_in_lc_usd', 'desc', true);

        if(_.isEmpty(this.wallets)) {
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
        wallets: function () {
            const direction = this.filter.filterDirection == 'desc' ? 'desc' : 'asc';
            
            let wallets = _.orderBy(this.$store.getters.getWallets, [
                // Primary sort: by the selected filter field
                (wallet) => {
                    if(this.filter.filterBy == 'symbol' || this.filter.filterBy == 'type') {
                        return wallet[this.filter.filterBy];
                    } else {
                        return parseFloat(wallet[this.filter.filterBy]) || 0;
                    }
                },
                // Secondary sort: by crypto balance when USD is zero
                (wallet) => {
                    const usdValue = parseFloat(wallet.balance_in_lc_usd) || 0;
                    // If USD value is 0, use crypto balance for secondary sort
                    if (usdValue === 0) {
                        return parseFloat(wallet.balance_in_lc) || 0;
                    }
                    return 0; // Already sorted by USD, no secondary needed
                }
            ], [direction, direction]);

            return _.filter(wallets, (wallet) => {
                let sortedBy = true;

                // For trading wallets, filter by trading balance (USD or crypto)
                if(this.filter.sortBy == "balance") {
                    const usdBalance = parseFloat(wallet.balance_in_lc_usd) || 0;
                    const cryptoBalance = parseFloat(wallet.balance_in_lc) || 0;
                    sortedBy = usdBalance > 0 || cryptoBalance > 0;
                }

                const matchesSearch = (wallet.symbol.toLowerCase().includes(this.filter.search.toLowerCase()) ||
                    wallet.currency.toLowerCase().includes(this.filter.search.toLowerCase()));

                const matchesType = this.activeType === 'coin' ? wallet.type === 'coin' : wallet.type === 'fiat';

                return matchesSearch && sortedBy && matchesType;
            });


        },
        pricesInUsd: function () {
            return this.$store.getters.getWallet(this.currency.symbol);
        },
    },
    methods: {
      onResize () {
        if(window.innerWidth >= 986) {
          this.$inertia.visit(this.route('wallets.investfunding'));
        } else {

        }
      },
        fetchTotalBalance() {
            axios.get(this.route('currencies.api.rates-balance'), {
                params: {
                    wallet: 'lc'
                }
            }).then((response) => {
                this.totalBalance = response.data;
            })
        },
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
