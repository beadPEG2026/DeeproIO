<template>
<div></div>
</template>

<script>
import {mapGetters} from "vuex";

export default {
    name: 'orderbook-channel',
    data() {
        return {
            channel: 'orderbook-',
        }
    },
    props: {
        market: Object,
    },
    mounted() {
      // Join when single market page loaded
      this.join();
    },
    beforeDestroy() {
      this.quit();
    },
    watch: {
        socket: function () {
            // Rejoin when socket updated
            this.join();
        },
    },
    computed: mapGetters({
        socket: 'getSocket',
    }),
    methods: {
        join: function () {
            let mergedChannel = this.channel + this.market.name;

            // If already joined ignore
            if(mergedChannel in window.Echo.connector.channels) return false;

            //Join to the channel and listen events
            window.Echo.channel(mergedChannel)
                .listen('OrderBookUpdated', (payload) => {

                    this.$store.dispatch('updateOrderbook', {
                        order: payload.order,
                        type: payload.type,
                        market: this.market.name,
                    });

                }).listen('OrderBookSnapshot', (payload) => {

                    let orders = [];

                    orders['bids'] = payload.bids;
                    orders['asks'] = payload.asks;
                    orders.book_status = payload.book_status;

                    this.$store.dispatch('liquidityOrders', { orders: orders, market: this.market.name });
                }).listen('MarketTradeUpdated', (payload) => {
                    if(!payload.s) {
                        this.$store.dispatch('updateMarketTrade', {
                            market: payload.market,
                            trade: payload.trade
                        });
                    }
                }).listen('MarketTradeLiteUpdated', (payload) => {
                    if(!payload.s) {
                        this.$store.dispatch('updateMarketTrade', {
                            market: payload.market,
                            trade: payload.trade
                        });
                    }
                }).listen('MarketTradePressureUpdated', (payload) => {
                    this.$worker.$emit("marketTradePressure", {
                        'market': payload.market,
                        'buy': payload.buy,
                        'sell': payload.sell
                    });
                });
        },
        quit() {
            let mergedChannel = this.channel + this.market.name;
            window.Echo.leave(mergedChannel);
        }
    }
}
</script>
