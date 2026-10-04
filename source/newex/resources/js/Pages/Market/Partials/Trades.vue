<script>
import {localDateTime} from '@/Functions/UserDisplay.mjs';
import Template from '{Template}/Web/Pages/Market/Partials/Trades.template'
import {math_formatter} from "@/Functions/Math";

export default Template({
    props: {
        market: Object,
    },

    data() {
        return {
            orders: null,
            ordersInterval: null,
            limit: 20,

            selectedOrder: null,
            showOrderDetailModal: false,
        }
    },

    mounted() {
        if (this.$page.props.user) {
            this.fetchOrders();

            this.ordersInterval = setInterval(() => {
                this.fetchOrders();
            }, 5000)
        }
    },

    beforeDestroy() {
        clearInterval(this.ordersInterval)
    },

    methods: {
        localDateTime(value) { return localDateTime(value, this.$page.props.timezone); },
        fetchOrders() {
            axios.get(this.route('orders.api.trades')).then(response => {
                this.orders = response.data.data;
            });
        },

        stripTrailingZeros(value) {
            if (value === null || value === undefined || value === '') {
                return '0';
            }

            let stringValue = String(value);

            if (stringValue.indexOf('.') === -1) {
                return stringValue;
            }

            stringValue = stringValue.replace(/(\.\d*?[1-9])0+$/g, '$1');
            stringValue = stringValue.replace(/\.0+$/g, '');
            stringValue = stringValue.replace(/\.$/g, '');

            return stringValue === '' ? '0' : stringValue;
        },

        decimal_format(value, decimal, type = '') {
            let formatted = math_formatter(value, decimal);

            if (type == "fiat") {
                formatted = numeral(formatted).format('0,0.00');
            }

            return this.stripTrailingZeros(formatted);
        },

        openOrderDetail(order) {
            this.selectedOrder = order;
            this.showOrderDetailModal = true;
        },

        closeOrderDetail() {
            this.showOrderDetailModal = false;
            this.selectedOrder = null;
        },

        doCopy(string) {
            if (!string) {
                return;
            }

            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {

            })
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            let number = parseFloat(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        formatNumber(value, decimals = 8) {
            return this.stripTrailingZeros(math_formatter(this.toNumber(value), decimals));
        },

        formatPrice(value, decimals = 8) {
            let number = this.toNumber(value);

            if (number <= 0) {
                return '-';
            }

            return this.formatNumber(number, decimals);
        },

        getTransactions(order) {
            if (!order || !Array.isArray(order.transactions)) {
                return [];
            }

            return order.transactions;
        },

        getFirstTransaction(order) {
            const transactions = this.getTransactions(order);

            if (transactions.length > 0) {
                return transactions[0];
            }

            return null;
        },

        getMarketName(order) {
            if (!order) {
                return '-';
            }

            return order.market || order.market_name || '-';
        },

        getSymbolsFromMarket(order) {
            const marketName = this.getMarketName(order);

            if (marketName && marketName.indexOf('-') !== -1) {
                const arr = marketName.split('-');

                return {
                    base: arr[0] || '',
                    quote: arr[1] || '',
                };
            }

            return {
                base: '',
                quote: '',
            };
        },

        getBaseSymbol(order) {
            if (!order) {
                return '';
            }

            const transaction = this.getFirstTransaction(order);

            if (transaction && transaction.base_symbol) {
                return transaction.base_symbol;
            }

            if (order.base_symbol) {
                return order.base_symbol;
            }

            if (order.base_currency && isNaN(order.base_currency)) {
                return order.base_currency;
            }

            return this.getSymbolsFromMarket(order).base || '';
        },

        getQuoteSymbol(order) {
            if (!order) {
                return '';
            }

            const transaction = this.getFirstTransaction(order);

            if (transaction && transaction.quote_symbol) {
                return transaction.quote_symbol;
            }

            if (order.quote_symbol) {
                return order.quote_symbol;
            }

            if (order.quote_currency && isNaN(order.quote_currency)) {
                return order.quote_currency;
            }

            return this.getSymbolsFromMarket(order).quote || '';
        },

        getOrderSide(order) {
            if (!order) {
                return '-';
            }

            return order.side === 'sell' ? this.$t('Sell') : this.$t('Buy');
        },

        getOrderSideClass(order) {
            if (!order) {
                return '';
            }

            return order.side === 'sell' ? 'color-sell' : 'color-buy';
        },

        getOrderType(order) {
            if (!order) {
                return '-';
            }

            const type = order.type || order.symbol || '';

            const labels = {
                market: this.$t('Market Order'),
                limit: this.$t('Limit Order'),
                stop_limit: this.$t('Stop Limit Order'),
                stop_market: this.$t('Stop Market Order'),
            };

            return labels[type] || type || '-';
        },

        getOrderTypeShort(order) {
            if (!order) {
                return '-';
            }

            const type = order.type || order.symbol || '';

            const labels = {
                market: this.$t('market'),
                limit: this.$t('limit'),
                stop_limit: this.$t('stop limit'),
                stop_market: this.$t('stop market'),
            };

            return labels[type] || type || '-';
        },

        getDisplayType(order) {
            return this.getOrderSide(order) + ' ' + this.getOrderTypeShort(order);
        },

        getOrderStatus(order) {
            const status = String(order && order.status ? order.status : '').toLowerCase();

            if (status === 'pending' || status === 'open') {
                return this.$t('Pending');
            }

            if (status === 'canceled' || status === 'cancelled') {
                return this.$t('Canceled');
            }

            if (status === 'rejected') {
                return this.$t('Rejected');
            }

            if (status === 'failed') {
                return this.$t('Failed');
            }

            return this.$t('Filled');
        },

        getOrderStatusClass(order) {
            const status = String(order && order.status ? order.status : '').toLowerCase();

            if (status === 'pending' || status === 'open') {
                return 'label-orange';
            }

            if (status === 'canceled' || status === 'cancelled' || status === 'rejected' || status === 'failed') {
                return 'label-red';
            }

            return 'label-green';
        },

        getOrderPrice(order) {
            if (!order) {
                return '-';
            }

            if (order.type === 'market' || order.symbol === 'market') {
                return this.$t('Market Price');
            }

            const price = this.toNumber(order.price);

            if (price <= 0) {
                return '-';
            }

            return this.formatPrice(price) + ' ' + this.getQuoteSymbol(order);
        },

        getAveragePrice(order) {
            const transactions = this.getTransactions(order);

            if (transactions.length > 0) {
                let totalBase = 0;
                let totalQuote = 0;

                transactions.forEach(transaction => {
                    const base = this.toNumber(transaction.quantity || transaction.base_currency);
                    let quote = this.toNumber(transaction.quote_currency);

                    if (quote <= 0) {
                        quote = base * this.toNumber(transaction.price);
                    }

                    totalBase += base;
                    totalQuote += quote;
                });

                if (totalBase > 0 && totalQuote > 0) {
                    return totalQuote / totalBase;
                }

                return this.toNumber(transactions[0].price);
            }

            return this.toNumber(order && order.price ? order.price : 0);
        },

        getExecutedPrice(order) {
            const avgPrice = this.getAveragePrice(order);

            if (avgPrice <= 0) {
                if (order && (order.type === 'market' || order.symbol === 'market')) {
                    return this.$t('Market Price');
                }

                return '-';
            }

            return this.formatPrice(avgPrice) + ' ' + this.getQuoteSymbol(order);
        },

        getExecutedBaseAmount(order) {
            const transactions = this.getTransactions(order);

            if (transactions.length > 0) {
                return transactions.reduce((total, transaction) => {
                    return total + this.toNumber(transaction.quantity || transaction.base_currency);
                }, 0);
            }

            return this.toNumber(order && order.quantity ? order.quantity : 0);
        },

        getExecutedQuoteAmount(order) {
            const transactions = this.getTransactions(order);

            if (transactions.length > 0) {
                return transactions.reduce((total, transaction) => {
                    let quote = this.toNumber(transaction.quote_currency);

                    if (quote <= 0) {
                        quote = this.toNumber(transaction.quantity || transaction.base_currency) * this.toNumber(transaction.price);
                    }

                    return total + quote;
                }, 0);
            }

            const qty = this.toNumber(order && order.quantity ? order.quantity : 0);
            const price = this.toNumber(order && order.price ? order.price : 0);

            return qty * price;
        },

        getExecutedBaseText(order) {
            const amount = this.getExecutedBaseAmount(order);

            if (amount <= 0) {
                return '-';
            }

            return this.formatNumber(amount, 8) + ' ' + this.getBaseSymbol(order);
        },

        getExecutedQuoteText(order) {
            const amount = this.getExecutedQuoteAmount(order);

            if (amount <= 0) {
                return '-';
            }

            return this.formatNumber(amount, 8) + ' ' + this.getQuoteSymbol(order);
        },

        getOrderQuantity(order) {
            const qty = this.getExecutedBaseAmount(order);

            if (qty <= 0) {
                return '-';
            }

            return this.formatNumber(qty, 8) + ' ' + this.getBaseSymbol(order);
        },

        getOrderValue(order) {
            const amount = this.getExecutedQuoteAmount(order);

            if (amount <= 0) {
                return '-';
            }

            return this.formatNumber(amount, 8) + ' ' + this.getQuoteSymbol(order);
        },

        getTotalFee(order) {
            const transactions = this.getTransactions(order);

            if (transactions.length > 0) {
                return transactions.reduce((total, transaction) => {
                    return total + this.toNumber(transaction.fee);
                }, 0);
            }

            return this.toNumber(order && order.fee ? order.fee : 0);
        },

        getTotalFeeText(order) {
            const fee = this.getTotalFee(order);

            if (fee <= 0) {
                return '-';
            }

            return this.formatNumber(fee, 8) + ' ' + this.getQuoteSymbol(order);
        },

        getOrderId(order) {
            if (!order) {
                return '-';
            }

            const transaction = this.getFirstTransaction(order);

            return (transaction && transaction.id) || order.id || order.order_id || '-';
        },

        getDisplayDetail(order) {
            const transaction = this.getFirstTransaction(order);

            let qty = 0;
            let price = 0;
            let fee = 0;
            let time = order && order.created_at ? order.created_at : '';

            if (transaction) {
                qty = this.toNumber(transaction.quantity || transaction.base_currency);
                price = this.toNumber(transaction.price);
                fee = this.toNumber(transaction.fee);
                time = transaction.created_at || time;
            } else {
                qty = this.getExecutedBaseAmount(order);
                price = this.getAveragePrice(order);
                fee = this.getTotalFee(order);
            }

            if (price <= 0 && this.getAveragePrice(order) > 0) {
                price = this.getAveragePrice(order);
            }

            return {
                quantityText: qty > 0 ? this.formatNumber(qty, 8) : '-',
                quantitySymbol: this.getBaseSymbol(order),

                priceText: price > 0 ? this.formatPrice(price) : (order && order.type === 'market' ? this.$t('Market Price') : '-'),
                priceSymbol: price > 0 ? this.getQuoteSymbol(order) : '',

                feeText: fee > 0 ? this.formatNumber(fee, 8) : '-',
                feeSymbol: fee > 0 ? this.getQuoteSymbol(order) : '',

                time: time,
            };
        },
    }
})
</script>