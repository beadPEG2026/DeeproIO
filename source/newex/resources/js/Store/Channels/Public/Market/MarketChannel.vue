<template>
<div></div>
</template>

<script>
import {mapGetters} from 'vuex'
import {math_formatter, math_percentage} from "@/Functions/Math";

export default {
    name: 'market-channel',
    data() {
        return {
            channel: 'market',
        }
    },
    computed: mapGetters({
        user: 'getUser',
        socket: 'getSocket'
    }),
    mounted() {
        this.join();
    },
    watch: {
        socket: function (val) {
            this.join();
        }
    },
    beforeDestroy() {
        this.quit();
    },
    methods: {
        join: function () {
            //Join to the channel and listen events
            Echo.channel(this.channel)
                .listen('MarketStatsUpdated', (payload) => {
                this.$store.dispatch('updateMarketStats', {
                    market: payload.market
                });
            }).listen('MarketStatsLiteUpdated', (payload) => {
                this.$store.dispatch('updateMarketStats', {
                    market: payload.market
                });
            });
        },
        quit() {
            window.Echo.leave(this.channel);
        },
        math_formatter(value, decimals) {
            return math_formatter(value, decimals);
        },
    }
}
</script>
