<template>
<div>
</div>
</template>

<script>
export default {
    data: function() {
        return {
            connection: null
        }
    },
    props: {
        market: Object,
    },
    mounted() {

        return;

        if(!this.market.s) return;

        this.connection = new WebSocket("wss://stream.binance.com:9443/ws/"+this.market.sanitized_name.toLowerCase()+"@depth20")

        this.connection.onmessage = (event) => {

            let ratio = this.market.ratio * 0.01;
            let ratio_b = this.market.ratio_b * 0.01;

            let orderbook = JSON.parse(event.data);

            let orders = [];

            let bids = orderbook.bids.map((bid) => {
               return {
                   'price': parseFloat(bid[0]) + (bid[0] * ratio_b),
                   'quantity': bid[1]
               }
            });

            let asks = orderbook.asks.map((ask) => {
                return {
                    'price': parseFloat(ask[0]) + (ask[0] * ratio),
                    'quantity': ask[1]
                }
            });

            orders['bids'] = bids;
            orders['asks'] = asks;

            this.fetchOrderbook(orders);
        }
    },
    methods: {
        fetchOrderbook(orders) {
            this.$store.dispatch('liquidityOrders', { orders: orders, market: this.market.name });
        },
    }
}
</script>
