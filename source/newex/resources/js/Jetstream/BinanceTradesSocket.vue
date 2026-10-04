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

        this.connection = new WebSocket("wss://stream.binance.com:9443/ws/"+this.market.sanitized_name.toLowerCase()+"@trade")

        this.connection.onmessage = (event) => {

            let trade = JSON.parse(event.data);
            let ratio = !trade.m ? this.market.ratio * 0.01 : this.market.ratio_b * 0.01;

            this.$store.dispatch('updateMarketTrade', {
                market: this.market,
                trade: {
                    side: trade.m ? 'buy': 'sell',
                    price: parseFloat(trade.p) + (trade.p * ratio),
                    quantity: trade.q,
                    created_at: trade.E,
                }
            });
        }
    },
    methods: {

    }
}
</script>
