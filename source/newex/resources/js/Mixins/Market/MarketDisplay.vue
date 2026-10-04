<script>
import {state,subscribeStockQuotes,unsubscribeStockQuotes} from '@/Functions/StockQuotes';
import {displayMarket} from '@/Functions/MarketDisplay.mjs';
export default {computed:{displayMarkets(){return this.$store.getters.getMarkets.map(m=>displayMarket(m,state.quotes,state.now,state.fx))}},mounted(){subscribeStockQuotes()},beforeDestroy(){unsubscribeStockQuotes()}};
</script>
