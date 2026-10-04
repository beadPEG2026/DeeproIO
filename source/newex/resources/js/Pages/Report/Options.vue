<script>
import Template from '{Template}/Web/Pages/Report/Options.template'
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
                market: this.filters.market ? parseInt(this.filters.market) : null,
                side: this.filters.side || '',
            },

            selectedOption: null,
            showOptionDetailModal: false,
        }
    },

    methods: {
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

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = parseFloat(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        formatNumber(value, decimals = 8) {
            return this.stripTrailingZeros(math_formatter(this.toNumber(value), decimals));
        },

        formatPrice(value, symbol = '') {
            const number = this.toNumber(value);

            if (number <= 0) {
                return '-';
            }

            return this.formatNumber(number, 8) + (symbol ? ' ' + symbol : '');
        },

        formatAmount(value, symbol = '') {
            return this.formatNumber(value, 8) + (symbol ? ' ' + symbol : '');
        },

        format_string(string, limit) {
            if (!string) {
                return '';
            }

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
            this.$inertia.replace(this.route('reports.options', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },

        openOptionDetail(transaction) {
            this.selectedOption = transaction;
            this.showOptionDetailModal = true;
        },

        closeOptionDetail() {
            this.showOptionDetailModal = false;
            this.selectedOption = null;
        },

        getMarketName(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.market || transaction.market_name || '-';
        },

        getOptionTitle(transaction) {
            const market = this.getMarketName(transaction);

            if (!market || market === '-') {
                return this.$t('Options');
            }

            return market + ' ' + this.$t('Options');
        },

        getQuoteSymbol(transaction) {
            if (!transaction) {
                return '';
            }

            if (transaction.symbol) {
                return transaction.symbol;
            }

            const market = this.getMarketName(transaction);

            if (market.indexOf('-') !== -1) {
                return market.split('-')[1] || '';
            }

            return '';
        },

        getSideLabel(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.type === 'sell' ? this.$t('Sell') : this.$t('Buy');
        },

        getSideClass(transaction) {
            if (!transaction) {
                return 'color-buy';
            }

            return transaction.type === 'sell' ? 'color-sell' : 'color-buy';
        },

        getSideBadgeClass(transaction) {
            if (!transaction) {
                return 'label-green';
            }

            return transaction.type === 'sell' ? 'label-red' : 'label-green';
        },

        getStatusLabel(transaction) {
            if (!transaction) {
                return '-';
            }

            const status = String(transaction.status || '').toLowerCase();

            if (status === 'won') {
                return this.$t('Profit');
            }

            if (status === 'lost') {
                return this.$t('Loss');
            }

            if (status === 'scheduled') {
                return this.$t('Scheduled');
            }

            if (status === 'closed') {
                return this.$t('Closed');
            }

            if (status === 'active') {
                return this.$t('Active');
            }

            if (status === 'pending') {
                return this.$t('Pending');
            }

            return transaction.status || '-';
        },

        getStatusClass(transaction) {
            if (!transaction) {
                return 'label-gray';
            }

            const status = String(transaction.status || '').toLowerCase();

            if (status === 'won') {
                return 'label-green';
            }

            if (status === 'lost') {
                return 'label-red';
            }

            if (status === 'pending' || status === 'scheduled' || status === 'active' || status === 'closed') {
                return 'label-gray';
            }

            return 'label-gray';
        },

        getPnlClass(transaction) {
            if (!transaction) {
                return '';
            }

            if (transaction.status === 'won') {
                return 'color-buy';
            }

            if (transaction.status === 'lost') {
                return 'color-sell';
            }

            return '';
        },

        getEnterPriceText(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.formatPrice(transaction.price, this.getQuoteSymbol(transaction));
        },

        getClosedPriceText(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.formatPrice(transaction.market_price, this.getQuoteSymbol(transaction));
        },

        getAmountText(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.formatAmount(transaction.amount, this.getQuoteSymbol(transaction));
        },

        getPnlText(transaction) {
            if (!transaction) {
                return '-';
            }

            if (transaction.status !== 'won' && transaction.status !== 'lost') {
                return '-';
            }

            const pnl = this.toNumber(transaction.pnl);
            const symbol = this.getQuoteSymbol(transaction);

            if (transaction.status === 'won') {
                return '+' + this.formatNumber(pnl, 8) + (symbol ? ' ' + symbol : '');
            }

            return '-' + this.formatNumber(Math.abs(pnl), 8) + (symbol ? ' ' + symbol : '');
        },

        getPeriodText(transaction) {
            if (!transaction) {
                return '-';
            }

            const period = Number(transaction.period);

            const periodMap = {
                1: '60s',
                2: '120s',
                3: '180s',
                4: '5m',
                5: '10m',
            };

            return periodMap[period] || '-';
        },

        getOptionId(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.id || transaction.option_id || transaction.transaction_id || '-';
        },

        getResultPriceText(transaction) {
            if (!transaction) {
                return '-';
            }

            const marketPrice = this.toNumber(transaction.market_price);

            if (marketPrice > 0) {
                return this.getClosedPriceText(transaction);
            }

            return '-';
        },

        getDetailQuantityText(transaction) {
            return this.getAmountText(transaction);
        },

        getDetailPriceText(transaction) {
            return this.getEnterPriceText(transaction);
        },

        getDetailClosedPriceText(transaction) {
            return this.getClosedPriceText(transaction);
        },

        getDetailTime(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.closed_at || transaction.updated_at || transaction.created_at || '-';
        },
    },
})
</script>