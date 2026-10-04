<script>
import Template from '{Template}/Web/Pages/Report/Trades.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import TextUserInput from "@/Jetstream/TextUserInput";
import SelectUserInput from "@/Jetstream/SelectUserInput";
import ReportsTab from "@/Components/Reports/ReportsTab";
import {math_formatter} from "@/Functions/Math";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        TextUserInput,
        SelectUserInput,
        ReportsTab
    },

    props: {
        transactions: Object,
        filters: Object,
        markets: Object,
    },

    data() {
        return {
            sending: false,
            form: {
                market: parseInt(this.filters.market),
                side: this.filters.side,
            },

            selectedTransaction: null,
            showTransactionDetailModal: false,
        }
    },

    methods: {

        padDateNumber(value) {
            return String(value).padStart(2, '0');
        },

        getTimeZoneOffset(timeZone, date) {
            if (!timeZone || !(date instanceof Date) || Number.isNaN(date.getTime())) {
                return 0;
            }

            try {
                const formatter = new Intl.DateTimeFormat('en-US', {
                    timeZone: timeZone,
                    hour12: false,
                    hourCycle: 'h23',
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                });

                const parts = formatter.formatToParts(date).reduce((carry, item) => {
                    carry[item.type] = item.value;
                    return carry;
                }, {});

                const year = parseInt(parts.year, 10);
                const month = parseInt(parts.month, 10);
                const day = parseInt(parts.day, 10);
                let hour = parseInt(parts.hour, 10);
                const minute = parseInt(parts.minute, 10);
                const second = parseInt(parts.second, 10);

                if (hour === 24) {
                    hour = 0;
                }

                const localAsUtc = Date.UTC(year, month - 1, day, hour, minute, second);

                return localAsUtc - date.getTime();
            } catch (e) {
                return 0;
            }
        },

        parseServerDateTime(value) {
            if (!value) {
                return null;
            }

            if (value instanceof Date) {
                return Number.isNaN(value.getTime()) ? null : value;
            }

            if (typeof value === 'number') {
                const date = new Date(value > 10000000000 ? value : value * 1000);
                return Number.isNaN(date.getTime()) ? null : date;
            }

            const stringValue = String(value).trim();

            if (!stringValue || stringValue === '-') {
                return null;
            }

            if (/[zZ]$/.test(stringValue) || /[+-]\d{2}:?\d{2}$/.test(stringValue)) {
                const date = new Date(stringValue.replace(' ', 'T'));
                return Number.isNaN(date.getTime()) ? null : date;
            }

            const match = stringValue.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);

            if (!match) {
                const date = new Date(stringValue);
                return Number.isNaN(date.getTime()) ? null : date;
            }

            const year = parseInt(match[1], 10);
            const month = parseInt(match[2], 10);
            const day = parseInt(match[3], 10);
            const hour = parseInt(match[4], 10);
            const minute = parseInt(match[5], 10);
            const second = parseInt(match[6] || '0', 10);

            const wallTimeAsUtc = Date.UTC(year, month - 1, day, hour, minute, second);
            const sourceServerTimeZone = 'Europe/Berlin';

            let utcTime = wallTimeAsUtc - this.getTimeZoneOffset(sourceServerTimeZone, new Date(wallTimeAsUtc));
            utcTime = wallTimeAsUtc - this.getTimeZoneOffset(sourceServerTimeZone, new Date(utcTime));

            const date = new Date(utcTime);

            return Number.isNaN(date.getTime()) ? null : date;
        },

        formatLocalDateTime(value) {
            const date = this.parseServerDateTime(value);

            if (!date || Number.isNaN(date.getTime())) {
                return '-';
            }

            return [
                date.getFullYear(),
                this.padDateNumber(date.getMonth() + 1),
                this.padDateNumber(date.getDate()),
            ].join('-') + ' ' + [
                this.padDateNumber(date.getHours()),
                this.padDateNumber(date.getMinutes()),
                this.padDateNumber(date.getSeconds()),
            ].join(':');
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

        format_string(string, limit) {
            return string_cut(string, limit);
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

        reset() {
            this.form = mapValues(this.form, () => null)
        },

        getList() {
            if (this.sending) return;

            this.sending = true;

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.sending = false;
                },
                onError: () => {
                    this.sending = false;
                },
                preserveScroll: true
            };

            let query = pickBy(this.form)
            this.$inertia.replace(this.route('reports.trades', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },

        openTransactionDetail(transaction) {
            this.selectedTransaction = transaction;
            this.showTransactionDetailModal = true;
        },

        closeTransactionDetail() {
            this.showTransactionDetailModal = false;
            this.selectedTransaction = null;
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

            return this.stripTrailingZeros(math_formatter(number, decimals));
        },

        getMarketName(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.market || transaction.market_name || '-';
        },

        getBaseSymbol(transaction) {
            if (!transaction) {
                return '';
            }

            if (transaction.base_symbol) {
                return transaction.base_symbol;
            }

            if (transaction.market && transaction.market.indexOf('-') !== -1) {
                return transaction.market.split('-')[0] || '';
            }

            return '';
        },

        getQuoteSymbol(transaction) {
            if (!transaction) {
                return '';
            }

            if (transaction.quote_symbol) {
                return transaction.quote_symbol;
            }

            if (transaction.market && transaction.market.indexOf('-') !== -1) {
                return transaction.market.split('-')[1] || '';
            }

            return '';
        },

        getSideLabel(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.side === 'sell' ? this.$t('Sell') : this.$t('Buy');
        },

        getSideClass(transaction) {
            if (!transaction) {
                return '';
            }

            return transaction.side === 'sell' ? 'color-sell' : 'color-buy';
        },

        getSideBadgeClass(transaction) {
            if (!transaction) {
                return 'label-green';
            }

            return transaction.side === 'sell' ? 'label-red' : 'label-green';
        },

        getTypeShort(transaction) {
            if (!transaction) {
                return '-';
            }

            const type = transaction.type || '';

            const labels = {
                market: this.$t('market'),
                limit: this.$t('limit'),
                stop_limit: this.$t('stop limit'),
                stop_market: this.$t('stop market'),
            };

            return labels[type] || type || '-';
        },

        getOrderType(transaction) {
            if (!transaction) {
                return '-';
            }

            const type = transaction.type || '';

            const labels = {
                market: this.$t('Market Order'),
                limit: this.$t('Limit Order'),
                stop_limit: this.$t('Stop Limit Order'),
                stop_market: this.$t('Stop Market Order'),
            };

            return labels[type] || type || '-';
        },

        getDisplayType(transaction) {
            return this.getSideLabel(transaction) + ' ' + this.getTypeShort(transaction);
        },

        getStatusLabel(transaction) {
            if (!transaction) {
                return this.$t('Filled');
            }

            const status = String(transaction.status || '').toLowerCase();

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

        getStatusClass(transaction) {
            if (!transaction) {
                return 'label-green';
            }

            const status = String(transaction.status || '').toLowerCase();

            if (status === 'pending' || status === 'open') {
                return 'label-orange';
            }

            if (status === 'canceled' || status === 'cancelled' || status === 'rejected' || status === 'failed') {
                return 'label-red';
            }

            return 'label-green';
        },

        getOrderPrice(transaction) {
            if (!transaction) {
                return '-';
            }

            if (transaction.type === 'market') {
                return this.$t('Market Price');
            }

            const price = this.toNumber(transaction.price);

            if (price <= 0) {
                return '-';
            }

            return this.formatPrice(price) + ' ' + this.getQuoteSymbol(transaction);
        },

        getExecutedPrice(transaction) {
            if (!transaction) {
                return '-';
            }

            const price = this.toNumber(transaction.price);

            if (price <= 0) {
                if (transaction.type === 'market') {
                    return this.$t('Market Price');
                }

                return '-';
            }

            return this.formatPrice(price) + ' ' + this.getQuoteSymbol(transaction);
        },

        getBaseAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            return this.toNumber(transaction.base_currency || transaction.quantity || 0);
        },

        getQuoteAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            let quoteAmount = this.toNumber(transaction.quote_currency);

            if (quoteAmount > 0) {
                return quoteAmount;
            }

            const baseAmount = this.getBaseAmount(transaction);
            const price = this.toNumber(transaction.price);

            return baseAmount * price;
        },

        getBaseAmountText(transaction) {
            const amount = this.getBaseAmount(transaction);

            if (amount <= 0) {
                return '-';
            }

            return this.formatNumber(amount, 8) + ' ' + this.getBaseSymbol(transaction);
        },

        getQuoteAmountText(transaction) {
            const amount = this.getQuoteAmount(transaction);

            if (amount <= 0) {
                return '-';
            }

            return this.formatNumber(amount, 8) + ' ' + this.getQuoteSymbol(transaction);
        },

        getFeeAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            return this.toNumber(transaction.fee);
        },

        getFeeText(transaction) {
            const fee = this.getFeeAmount(transaction);

            if (fee <= 0) {
                return '-';
            }

            return this.formatNumber(fee, 8) + ' ' + this.getQuoteSymbol(transaction);
        },

        getTransactionId(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.id || transaction.transaction_id || transaction.order_id || '-';
        },

        getDetailRow(transaction) {
            if (!transaction) {
                return {
                    quantityText: '-',
                    quantitySymbol: '',
                    priceText: '-',
                    priceSymbol: '',
                    feeText: '-',
                    feeSymbol: '',
                    time: '',
                };
            }

            const quantity = this.getBaseAmount(transaction);
            const price = this.toNumber(transaction.price);
            const fee = this.getFeeAmount(transaction);

            return {
                quantityText: quantity > 0 ? this.formatNumber(quantity, 8) : '-',
                quantitySymbol: quantity > 0 ? this.getBaseSymbol(transaction) : '',

                priceText: price > 0 ? this.formatPrice(price) : (transaction.type === 'market' ? this.$t('Market Price') : '-'),
                priceSymbol: price > 0 ? this.getQuoteSymbol(transaction) : '',

                feeText: fee > 0 ? this.formatNumber(fee, 8) : '-',
                feeSymbol: fee > 0 ? this.getQuoteSymbol(transaction) : '',

                time: this.formatLocalDateTime(transaction.created_at),
            };
        },
    },
})
</script>