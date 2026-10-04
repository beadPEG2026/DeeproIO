import {orderRequestFailure} from '@/Functions/OrderRequestFailure.mjs';
import OrderResultNotice from '@/Components/OrderResultNotice.vue';

export default {
    components: {OrderResultNotice},
    data: () => ({uncertainOrderSides: {buy: false, sell: false}}),
    methods: {
        canSubmitOrder(side) {
            if (!this.uncertainOrderSides[side]) return true;
            this.$toast.error(this.$t('Order result is unknown. Check open orders and order history before submitting again.'));
            return false;
        },
        acknowledgeOrderReview(side) {
            this.uncertainOrderSides[side] = false;
        },
        showOrderFailure(error, side, lite = false) {
            const failure = orderRequestFailure(error);
            this.uncertainOrderSides[side] = failure.uncertain;
            failure.fields.forEach(({field, message}) => {
                this[side === 'buy' ? 'buyErrorField' : 'sellErrorField'] = lite && field === 'quoteQuantity' ? 'quantity' : field;
                this.$toast.error(message);
            });
            if (failure.message) this.$toast.error(this.$t(failure.message));
        },
    },
};
