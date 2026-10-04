import axios from 'axios';

// Stock market data is display-only and never dispatched as a wallet/statistics update.
export default {
    data: () => ({stockTrades: [], stockTradesStale: false, stockTradesLoading: false,
        stockTradeTimer: null, stockTradeRequest: 0, stockTradePending: null, stockTradeDisposed: false}),
    mounted() {
        this.startStockTrades();
    },
    beforeDestroy() {
        this.stockTradeDisposed = true;
        this.stockTradeRequest++;
        clearInterval(this.stockTradeTimer);
    },
    watch: {
        'market.name'() {
            this.stockTradeRequest++;
            this.stockTradePending = null;
            this.stockTrades = [];
            this.stockTradesStale = false;
            this.startStockTrades();
        },
    },
    methods: {
        startStockTrades() {
            clearInterval(this.stockTradeTimer);
            if (!this.market.stock_token) return;
            this.refreshStockTrades();
            this.stockTradeTimer = setInterval(() => {
                if (!document.hidden) this.refreshStockTrades();
            }, 3000);
        },
        async refreshStockTrades() {
            const market = this.market.name;
            if (this.stockTradeDisposed || this.stockTradePending === market || !this.market.stock_token) return;
            const request = ++this.stockTradeRequest;
            this.stockTradePending = market;
            this.stockTradesLoading = true;
            try {
                const {data} = await axios.get(this.route('markets.api.historical.trades'), {
                    params: {market}, timeout: 30000,
                });
                if (request !== this.stockTradeRequest || this.stockTradeDisposed) return;
                if (!data.success || !Array.isArray(data.trades)) throw new Error('Invalid stock trade feed');
                // A failed provider must not erase the last real snapshot or replay it with a new timestamp.
                const rows = data.stale ? [...this.stockTrades, ...data.trades] : data.trades;
                this.stockTrades = [...new Map(rows.map(row => [row.id, row])).values()]
                    .sort((a, b) => b.timestamp - a.timestamp).slice(0, this.limit || 30);
                this.stockTradesStale = !!data.stale;
            } catch (_) {
                if (request === this.stockTradeRequest) this.stockTradesStale = true;
            } finally {
                if (request === this.stockTradeRequest) {
                    this.stockTradePending = null;
                    this.stockTradesLoading = false;
                }
            }
        },
        stockTradeTime(trade) {
            return new Date(trade.timestamp).toLocaleTimeString(this.$i18n.locale, {hour12: false});
        },
        stockTradeDate(trade) {
            return new Date(trade.timestamp).toLocaleString(this.$i18n.locale, {hour12: false});
        },
    },
};
