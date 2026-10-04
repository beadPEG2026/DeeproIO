import { legacyText } from '@/Functions/LegacyTranslation';
import {state, subscribeStockQuotes, unsubscribeStockQuotes} from '@/Functions/StockQuotes';
import {resolveEstimateMarket} from '@/Functions/OrderEstimate.mjs';
import {displayMarket, formatUsdt, usdtEquivalent} from '@/Functions/MarketDisplay.mjs';


export default {
    methods: {formatUsdt},
    computed: {
        isStockEstimate() { return !this.futures && this.market.stock_token; },
        displayMarketStats() {
            const native = {...this.market, ...(this.$store.getters.getMarket(this.market.name) || {})};
            if (native.price_reference_product) {
                const row=displayMarket(native,state.quotes,state.now,state.fx);
                return {...row,underlyingHkd:row.displayCurrency==='HKD'?row.last:null,last:row.approximateUsdt??null,high:usdtEquivalent(row.high,state.fx,state.now),low:usdtEquivalent(row.low,state.fx,state.now),displayCurrency:'USDT',approximateUsdt:null,volume:null,qVolume:null};
            }
            return resolveEstimateMarket(native, this.isStockEstimate ? state.quotes[this.market.base_currency] : null, state.now);
        },
        estimateMarket() {
            const native = {...this.market, ...(this.$store.getters.getMarket(this.market.name) || {})};
            if (this.isStockEstimate) {
                const side = this.activeTab === 'sell' ? 'bids' : 'asks';
                const book=this.$store.getters.getOrderbook(this.market.name,side)||[];
                const prices=book.map(r=>Number(r.price)).filter(p=>p>0);
                if (prices.length) return {...native,last:String(side==='asks'?Math.min(...prices):Math.max(...prices)),referenceQuote:false};
            }
            return this.isStockEstimate ? resolveEstimateMarket(native, state.quotes[this.market.base_currency], state.now) : native;
        },
        estimatePrice() { return Number(this.estimateMarket.last) || 0; },
        estimateNotice() {
            if (!this.isStockEstimate) return '';
            if (!this.estimatePrice) return legacyText("报价暂不可用，暂无法估算数量，请稍后重试。");
            if (this.estimateMarket.referenceQuote) return this.estimateMarket.approximateUsd
                ? legacyText("Estimated quantity (USD ≈ USDT)")
                : '';
            return '';
        }
    },
    mounted() {
        if (!this.isStockEstimate) return;
        this._estimateSubscribed = true;
        subscribeStockQuotes();
    },
    beforeDestroy() {
        if (!this._estimateSubscribed) return;
        unsubscribeStockQuotes();
    }
};
