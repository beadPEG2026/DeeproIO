<template>
<div></div>
</template>

<script>
import {mapGetters} from 'vuex'
import {math_formatter, math_percentage} from "@/Functions/Math";

export default {
    name: 'private-channel',
    watch: {
        user: function (socket) {
            this.join();
        },
    },
    computed: mapGetters({
        user: 'getUser',
        channel: 'getUserChannel'
    }),
    methods: {
        join: function () {

            if(!this.user) return false;

            // If already joined ignore
            if(this.channel in window.Echo.connector.channels) return false;

            // Join channel and listen events
            Echo.channel(this.channel)
                .listen('WalletUpdated', (payload) => {

                    // Listen wallet updated event

                    this.$store.dispatch('updateWallet', {
                        wallet: payload.wallet
                    });

                })
                .listen('DepositUpdated', (payload) => {

                    // Listen deposit received event
                    if(payload.type == "received") {
                        this.$store.dispatch('storeDeposit', {
                            deposit: payload.deposit
                        });
                    }

                    if(payload.type == "updated") {
                        this.$store.dispatch('updateDeposit', {
                            deposit: payload.deposit
                        });
                    }
                })
                .listen('WithdrawalUpdated', (payload) => {
                    this.$store.dispatch('updateWithdrawal', {
                        withdrawal: payload.withdrawal
                    });
                })
                .listen('MarketTradePrivateUpdated', (payload) => {
                    this.$toast.open(this.$t('Order processed.') + ' ' + this.$t('Rate:') + ' ' + math_formatter(payload.trade.price, payload.market.quotePrecision) + ' ' + payload.market.quote + '. ' + this.$t('Quantity:') + ' ' + math_formatter(payload.trade.quantity, payload.market.basePrecision) + ' ' + payload.market.base);
                }).listen('OptionsStatusUpdated', (payload) => {

                    if(payload.status == "won") {
                        this.$toast.open(this.$t('Your position was closed with positive PNL. Your profit: ') + payload.pnl + ' ' + payload.symbol);
                    } else {
                        this.$toast.error(this.$t('Your position was closed with negative PNL. Loss amount: ') + '-' + payload.pnl + ' ' + payload.symbol);
                    }
                }).listen('ChatMessageTyped', (payload) => {
                    this.$worker.$emit("startTypingEvent", {
                        'order': payload.order,
                    });
                });
        },
    }
}
</script>
