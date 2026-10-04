<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Report/FuturesTrades.template'
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
                type: this.filters.type || '',
            },

            showTPSLModal: false,
            selectedTPSL: null,

            showFuturesDetailModal: false,
            selectedFutures: null,
            showDetailedOrder: false,

            futuresTableScrollWidth: 0,
            showFuturesBottomScrollbar: false,
            syncingFuturesTableScroll: false,
            syncingFuturesBottomScroll: false,

            /*
             * 后端保存 / 返回的合约订单时间按服务器时区理解。
             * 你的服务器当前是 CEST +0200，所以这里使用 Europe/Berlin。
             * 前端显示时会自动转换成用户浏览器本地时区。
             */
            sourceServerTimeZone: 'Europe/Berlin',
        }
    },

    mounted() {
        this.$nextTick(() => {
            this.initFuturesTransactionsScrollbar();
        });

        if (typeof window !== 'undefined') {
            window.addEventListener('resize', this.updateFuturesTransactionsScrollbar);
        }
    },

    beforeDestroy() {
        this.destroyFuturesTransactionsScrollbar();

        if (typeof window !== 'undefined') {
            window.removeEventListener('resize', this.updateFuturesTransactionsScrollbar);
        }
    },

    updated() {
        this.updateFuturesTransactionsScrollbar();
    },

    methods: {
        initFuturesTransactionsScrollbar() {
            this.destroyFuturesTransactionsScrollbar();

            this.$nextTick(() => {
                const tableWrap = this.$refs.futuresTransactionsTableWrap;

                if (!tableWrap) {
                    return;
                }

                tableWrap.addEventListener('scroll', this.onFuturesTableScroll, { passive: true });
                this.updateFuturesTransactionsScrollbar();
            });
        },

        destroyFuturesTransactionsScrollbar() {
            const tableWrap = this.$refs ? this.$refs.futuresTransactionsTableWrap : null;

            if (tableWrap) {
                tableWrap.removeEventListener('scroll', this.onFuturesTableScroll);
            }
        },

        updateFuturesTransactionsScrollbar() {
            this.$nextTick(() => {
                const tableWrap = this.$refs.futuresTransactionsTableWrap;
                const bottomScrollbar = this.$refs.futuresBottomScrollbar;

                if (!tableWrap) {
                    this.showFuturesBottomScrollbar = false;
                    this.futuresTableScrollWidth = 0;
                    return;
                }

                const scrollWidth = tableWrap.scrollWidth || 0;
                const clientWidth = tableWrap.clientWidth || 0;

                this.futuresTableScrollWidth = scrollWidth;
                this.showFuturesBottomScrollbar = scrollWidth > clientWidth + 2;

                if (bottomScrollbar && this.showFuturesBottomScrollbar) {
                    bottomScrollbar.scrollLeft = tableWrap.scrollLeft;
                }
            });
        },

        onFuturesTableScroll() {
            if (this.syncingFuturesBottomScroll) {
                return;
            }

            const tableWrap = this.$refs.futuresTransactionsTableWrap;
            const bottomScrollbar = this.$refs.futuresBottomScrollbar;

            if (!tableWrap || !bottomScrollbar) {
                return;
            }

            this.syncingFuturesTableScroll = true;
            bottomScrollbar.scrollLeft = tableWrap.scrollLeft;

            this.$nextTick(() => {
                this.syncingFuturesTableScroll = false;
            });
        },

        onFuturesBottomScrollbarScroll() {
            if (this.syncingFuturesTableScroll) {
                return;
            }

            const tableWrap = this.$refs.futuresTransactionsTableWrap;
            const bottomScrollbar = this.$refs.futuresBottomScrollbar;

            if (!tableWrap || !bottomScrollbar) {
                return;
            }

            this.syncingFuturesBottomScroll = true;
            tableWrap.scrollLeft = bottomScrollbar.scrollLeft;

            this.$nextTick(() => {
                this.syncingFuturesBottomScroll = false;
            });
        },

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

            /*
             * 如果后端已经返回：
             * 2026-06-06T18:17:13Z
             * 2026-06-06T18:17:13+02:00
             * 2026-06-06 18:17:13+0200
             * 就直接交给浏览器转成本地时间。
             */
            if (/[zZ]$/.test(stringValue) || /[+-]\d{2}:?\d{2}$/.test(stringValue)) {
                const date = new Date(stringValue.replace(' ', 'T'));
                return Number.isNaN(date.getTime()) ? null : date;
            }

            /*
             * Laravel / PostgreSQL 常见格式：
             * 2026-06-06 18:17:13
             * 这种没有时区，前端按 sourceServerTimeZone 服务器时区解析。
             */
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

            /*
             * 第一次计算 Europe/Berlin 在这个时间附近的偏移。
             * 第二次再修正夏令时边界，避免 CEST/CET 切换日出现 1 小时偏差。
             */
            let utcTime = wallTimeAsUtc - this.getTimeZoneOffset(this.sourceServerTimeZone, new Date(wallTimeAsUtc));
            utcTime = wallTimeAsUtc - this.getTimeZoneOffset(this.sourceServerTimeZone, new Date(utcTime));

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

        getOpenTime(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.formatLocalDateTime(transaction.activated_at || transaction.start_at || transaction.created_at);
        },

        getUpdatedTime(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.formatLocalDateTime(transaction.updated_at || transaction.created_at);
        },

        math_formatter(value, decimals) {
            return this.stripTrailingZeros(math_formatter(value, decimals));
        },

        getFundingFeeRateValue(transaction) {
            if (!transaction) {
                return 0;
            }

            return this.toNumber(transaction.fee_rate);
        },

        getFundingFeeRateText(transaction) {
            const rate = this.getFundingFeeRateValue(transaction);

            if (!Number.isFinite(rate) || rate === 0) {
                return '0%';
            }

            return this.stripTrailingZeros(math_formatter(rate, 4)) + '%';
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
            if (!string) {
                return '-';
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
            this.$inertia.replace(this.route('reports.trades.futures', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },

        showTPSLInfo(transaction) {
            this.selectedTPSL = transaction;
            this.showTPSLModal = true;
        },

        closeTPSLModal() {
            this.showTPSLModal = false;
            this.selectedTPSL = null;
        },

        openFuturesDetail(transaction) {
            this.selectedFutures = transaction;
            this.showFuturesDetailModal = true;
            this.showDetailedOrder = false;
        },

        closeFuturesDetail() {
            this.showFuturesDetailModal = false;
            this.selectedFutures = null;
            this.showDetailedOrder = false;
        },

        toggleDetailedOrder() {
            this.showDetailedOrder = !this.showDetailedOrder;
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

        formatPrice(value, symbol = null) {
            const number = this.toNumber(value);

            if (number <= 0) {
                return '-';
            }

            return this.formatNumber(number, 8) + (symbol ? ' ' + symbol : '');
        },

        formatPercent(value, decimals = 4) {
            const number = this.toNumber(value);

            if (!Number.isFinite(number)) {
                return '0%';
            }

            return this.stripTrailingZeros(math_formatter(number, decimals)) + '%';
        },

        getMoneyText(value, transaction) {
            return this.formatNumber(value, 8) + ' ' + this.getQuoteSymbol(transaction);
        },

        getSignedMoneyText(value, transaction) {
            const number = this.toNumber(value);

            if (number === 0) {
                return '0 ' + this.getQuoteSymbol(transaction);
            }

            return (number > 0 ? '+' : '-') + this.formatNumber(Math.abs(number), 8) + ' ' + this.getQuoteSymbol(transaction);
        },

        getSignedValueClass(value) {
            const number = this.toNumber(value);

            if (number > 0) {
                return 'color-buy';
            }

            if (number < 0) {
                return 'color-sell';
            }

            return '';
        },

        getMarketName(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.market || transaction.market_name || '-';
        },

        getContractTitle(transaction) {
            const market = this.getMarketName(transaction);

            if (!market || market === '-') {
                return '-';
            }

            return market.replace('-', '') + ' ' + this.$t('Perpetual');
        },

        getBaseSymbol(transaction) {
            if (!transaction) {
                return '';
            }

            if (transaction.base_symbol) {
                return transaction.base_symbol;
            }

            const market = this.getMarketName(transaction);

            if (market.indexOf('-') !== -1) {
                return market.split('-')[0] || '';
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

            const market = this.getMarketName(transaction);

            if (market.indexOf('-') !== -1) {
                return market.split('-')[1] || '';
            }

            return 'USDT';
        },

        isShortPosition(transaction) {
            if (!transaction) {
                return false;
            }

            if (transaction.is_long === false || transaction.is_long === 0 || transaction.is_long === '0') {
                return true;
            }

            if (transaction.position_type === 'short' || transaction.side === 'short') {
                return true;
            }

            return transaction.type === 'short';
        },

        getPositionLabel(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.isShortPosition(transaction) ? this.$t('Short') : this.$t('Long');
        },

        getPositionClass(transaction) {
            return this.isShortPosition(transaction) ? 'label-red' : 'label-green';
        },

        getPositionTextClass(transaction) {
            return this.isShortPosition(transaction) ? 'color-sell' : 'color-buy';
        },

        getStatusLabel(transaction) {
            if (!transaction) {
                return '-';
            }

            const status = String(transaction.status || '').toLowerCase();

            if (status === 'closed') {
                return this.$t('Completed');
            }

            if (status === 'liquidated') {
                return this.$t('Liquidated');
            }

            if (status === 'active') {
                return this.$t('Active');
            }

            if (status === 'pending') {
                return this.$t('Pending');
            }

            if (status === 'canceled' || status === 'cancelled') {
                return this.$t('Canceled');
            }

            return status || '-';
        },

        getStatusClass(transaction) {
            if (!transaction) {
                return 'label-green';
            }

            const status = String(transaction.status || '').toLowerCase();

            if (status === 'liquidated' || status === 'canceled' || status === 'cancelled') {
                return 'label-red';
            }

            if (status === 'active' || status === 'pending') {
                return 'label-orange';
            }

            return 'label-green';
        },

        getOrderType(transaction) {
            if (!transaction) {
                return '-';
            }

            const rawType = transaction.order_type ||
                transaction.order_method ||
                transaction.execution_type ||
                transaction.position_order_type ||
                transaction.futures_order_type ||
                transaction.type ||
                'market';

            const type = ['market', 'limit', 'stop_limit', 'stop_market'].indexOf(rawType) !== -1 ? rawType : legacyText("market");

            const labels = {
                market: this.$t('Market Order'),
                limit: this.$t('Limit Order'),
                stop_limit: this.$t('Stop Limit Order'),
                stop_market: this.$t('Stop Market Order'),
            };

            return labels[type] || type || '-';
        },

        getMarginMode(transaction) {
            if (!transaction) {
                return '-';
            }

            const mode = transaction.margin_mode || transaction.margin_type || transaction.position_mode || 'isolated';

            const labels = {
                isolated: this.$t('Isolated'),
                cross: this.$t('Cross'),
                full: this.$t('Cross'),
            };

            return labels[mode] || mode || '-';
        },

        getLeverageText(transaction) {
            if (!transaction) {
                return '-';
            }

            const leverage = this.toNumber(transaction.leverage);

            if (leverage <= 0) {
                return '-';
            }

            return this.stripTrailingZeros(String(leverage)) + 'X';
        },

        getPositionSizeText(transaction) {
            const amount = this.getPositionPrincipalAmount(transaction);

            if (amount <= 0) {
                return '-';
            }

            return this.getMoneyText(amount, transaction);
        },

        getEntryPriceText(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.formatPrice(transaction.price, this.getQuoteSymbol(transaction));
        },

        getClosePriceText(transaction) {
            if (!transaction) {
                return '-';
            }

            const closePrice = this.toNumber(transaction.close_price);

            if (closePrice <= 0) {
                return '-';
            }

            return this.formatPrice(closePrice, this.getQuoteSymbol(transaction));
        },

        getLiquidationPriceText(transaction) {
            if (!transaction) {
                return '-';
            }

            return this.formatPrice(transaction.liquidation_price, this.getQuoteSymbol(transaction));
        },

        getRawReleasedAmountValue(transaction) {
            if (!transaction) {
                return 0;
            }

            return this.toNumber(transaction.released_amount);
        },

        getReleasedAmountValue(transaction) {
            if (!transaction) {
                return 0;
            }

            return this.getInitialPrincipalAmount(transaction) + this.getNetProfitAmount(transaction);
        },

        getReleasedAmountText(transaction) {
            const amount = this.getReleasedAmountValue(transaction);

            if (amount <= 0) {
                return '-';
            }

            return this.getMoneyText(amount, transaction);
        },

        getInitialPrincipalAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            const totalMargin = this.toNumber(transaction.total_margin_amount);
            if (totalMargin > 0) {
                return totalMargin;
            }

            const tradeMargin = this.toNumber(transaction.trade_margin_amount);
            if (tradeMargin > 0) {
                return tradeMargin;
            }

            const balance = this.toNumber(transaction.balance);
            const entryFee = this.toNumber(transaction.entry_fee);

            if (balance > 0 || entryFee > 0) {
                return balance + entryFee;
            }

            return this.toNumber(transaction.amount || transaction.margin);
        },

        getEntryFeeAmount(transaction) {
            return this.toNumber(transaction && transaction.entry_fee ? transaction.entry_fee : 0);
        },

        getExitFeeAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            const exitFee = this.toNumber(transaction.exit_fee);

            if (exitFee > 0) {
                return exitFee;
            }

            return this.toNumber(transaction.close_fee);
        },

        getFundingFeeAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            return this.toNumber(transaction.total_funding_fee_paid || transaction.funding_fee || 0);
        },

        getPositionPrincipalAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            const balance = this.toNumber(transaction.balance);

            if (balance > 0) {
                return balance;
            }

            return this.getInitialPrincipalAmount(transaction) - this.getEntryFeeAmount(transaction);
        },

        getCalculatedPricePnlAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            const entryPrice = this.toNumber(transaction.price);
            const closePrice = this.toNumber(transaction.close_price);
            const positionPrincipal = this.getPositionPrincipalAmount(transaction);
            const leverage = this.toNumber(transaction.leverage || 1);

            if (entryPrice <= 0 || closePrice <= 0 || positionPrincipal <= 0) {
                return 0;
            }

            if (this.isShortPosition(transaction)) {
                return positionPrincipal * leverage * (entryPrice - closePrice) / entryPrice;
            }

            return positionPrincipal * leverage * (closePrice - entryPrice) / entryPrice;
        },

        getSettlementPnlAmount(transaction) {
            if (!transaction) {
                return 0;
            }

            const releasedAmount = this.getRawReleasedAmountValue(transaction);
            const positionPrincipal = this.getPositionPrincipalAmount(transaction);
            const exitFee = this.getExitFeeAmount(transaction);
            const fundingFee = this.getFundingFeeAmount(transaction);

            if (releasedAmount > 0 && positionPrincipal > 0) {
                return releasedAmount + exitFee + fundingFee - positionPrincipal;
            }

            return this.getCalculatedPricePnlAmount(transaction);
        },

        getTradingFeeAmount(transaction) {
            return this.getEntryFeeAmount(transaction) + this.getExitFeeAmount(transaction);
        },

        getTotalProfitAmount(transaction) {
            return this.getSettlementPnlAmount(transaction);
        },

        getNetProfitAmount(transaction) {
            return this.getTotalProfitAmount(transaction) - this.getTradingFeeAmount(transaction);
        },

        getFinalResultAmount(transaction) {
            return this.getNetProfitAmount(transaction);
        },

        getTotalFee(transaction) {
            return this.getEntryFeeAmount(transaction) +
                this.getExitFeeAmount(transaction) +
                this.getFundingFeeAmount(transaction);
        },

        getFeeText(transaction) {
            const fee = this.getTotalFee(transaction);

            if (fee <= 0) {
                return '-';
            }

            return this.getMoneyText(fee, transaction);
        },

        getProfitAmount(transaction) {
            return this.getSettlementPnlAmount(transaction);
        },

        getProfitText(transaction) {
            return this.getSignedMoneyText(this.getProfitAmount(transaction), transaction);
        },

        getProfitClass(transaction) {
            const profit = this.getProfitAmount(transaction);

            if (profit < 0) {
                return 'color-sell';
            }

            if (profit > 0) {
                return 'color-buy';
            }

            return '';
        },

        getTotalProfitText(transaction) {
            return this.getSignedMoneyText(this.getTotalProfitAmount(transaction), transaction);
        },

        getNetProfitText(transaction) {
            return this.getSignedMoneyText(this.getNetProfitAmount(transaction), transaction);
        },

        getTotalProfitClass(transaction) {
            return this.getSignedValueClass(this.getTotalProfitAmount(transaction));
        },

        getNetProfitClass(transaction) {
            return this.getSignedValueClass(this.getNetProfitAmount(transaction));
        },

        getPnlRateText(transaction) {
            if (!transaction) {
                return '0%';
            }

            return this.formatPercent(transaction.pnl, 4);
        },

        hasTPSL(transaction) {
            if (!transaction) {
                return false;
            }

            return this.toNumber(transaction.take_profit_price) > 0 ||
                this.toNumber(transaction.stop_loss_price) > 0;
        },

        getOrderId(transaction) {
            if (!transaction) {
                return '-';
            }

            return transaction.id || transaction.uuid || transaction.future_contract_id || '-';
        },

        getCloseTime(transaction) {
            if (!transaction) {
                return '-';
            }

            if (transaction.status === 'active' || transaction.status === 'scheduled' || transaction.status === 'pending') {
                return '-';
            }

            return this.formatLocalDateTime(transaction.closed_at || transaction.updated_at);
        },

        getEntryFeeText(transaction) {
            return this.getMoneyText(this.getEntryFeeAmount(transaction), transaction);
        },

        getExitFeeText(transaction) {
            return this.getMoneyText(this.getExitFeeAmount(transaction), transaction);
        },

        getFundingFeeText(transaction) {
            return this.getMoneyText(this.getFundingFeeAmount(transaction), transaction);
        },

        getInitialPrincipalText(transaction) {
            return this.getMoneyText(this.getInitialPrincipalAmount(transaction), transaction);
        },

        getPositionPrincipalText(transaction) {
            return this.getMoneyText(this.getPositionPrincipalAmount(transaction), transaction);
        },

        getSettlementPnlText(transaction) {
            return this.getSignedMoneyText(this.getSettlementPnlAmount(transaction), transaction);
        },

        getFinalResultText(transaction) {
            return this.getSignedMoneyText(this.getFinalResultAmount(transaction), transaction);
        },
    },
})
</script>


<style>
/*
 * Futures transactions table:
 * The table keeps one-line cells. The table can still scroll internally,
 * but a synchronized fixed scrollbar is always visible at the bottom of the viewport.
 */
.futures-transactions-table-wrap {
    width: 100%;
    overflow-x: auto !important;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 22px;
}

.futures-transactions-table {
    min-width: 1320px;
    table-layout: auto;
}

.futures-transactions-table th,
.futures-transactions-table td {
    white-space: nowrap;
    vertical-align: middle;
}

.futures-transactions-table .table-sort__title,
.futures-transactions-table .table-list__subtitle,
.futures-transactions-table .table-list__value,
.futures-transactions-table .table-list__text,
.futures-transactions-table .components-label {
    white-space: nowrap;
}

.futures-transactions-table .table-list__text {
    line-height: 1.35;
}

.futures-transactions-table .components-label {
    min-width: 96px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.futures-bottom-scrollbar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    height: 18px;
    overflow-x: auto;
    overflow-y: hidden;
    z-index: 99999;
    background: rgba(15, 23, 42, 0.96);
    border-top: 1px solid rgba(129, 140, 248, 0.28);
}

.futures-bottom-scrollbar__inner {
    height: 1px;
}

.futures-bottom-scrollbar::-webkit-scrollbar,
.futures-transactions-table-wrap::-webkit-scrollbar {
    height: 8px;
}

.futures-bottom-scrollbar::-webkit-scrollbar-thumb,
.futures-transactions-table-wrap::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: rgba(129, 140, 248, 0.62);
}

.futures-bottom-scrollbar::-webkit-scrollbar-track,
.futures-transactions-table-wrap::-webkit-scrollbar-track {
    background: rgba(15, 23, 42, 0.42);
}


/*
 * Mobile card layout:
 * The original mob-table responsive style can hide values on narrow screens.
 * On mobile we hide the wide table and render clear data cards instead.
 */
.futures-transactions-mobile-list {
    display: none;
}

@media (max-width: 1000px) {
    .futures-transactions-table-wrap {
        display: none !important;
    }

    .futures-bottom-scrollbar {
        display: none !important;
    }

    .futures-transactions-mobile-list {
        display: grid;
        gap: 14px;
        padding: 0 12px 18px;
    }

    .futures-mobile-card {
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.025);
        padding: 14px;
        cursor: pointer;
    }

    .futures-mobile-card__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .futures-mobile-card__market {
        color: #ffffff;
        font-size: 15px;
        line-height: 1.35;
        font-weight: 700;
        word-break: break-word;
    }

    .futures-mobile-card__date {
        margin-top: 4px;
        color: #8d93a6;
        font-size: 12px;
        line-height: 1.4;
    }

    .futures-mobile-card__meta {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
    }

    .futures-mobile-card__meta .components-label,
    .futures-mobile-card__head .components-label {
        min-width: 72px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 12px;
        line-height: 1;
        white-space: nowrap;
    }

    .futures-mobile-card__leverage {
        min-width: 44px;
        height: 24px;
        padding: 0 10px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        background: rgba(255, 255, 255, 0.08);
        font-size: 12px;
        font-weight: 600;
        line-height: 1;
    }

    .futures-mobile-card__rows {
        display: grid;
        gap: 10px;
        padding-top: 12px;
        border-top: 1px solid rgba(255, 255, 255, 0.07);
    }

    .futures-mobile-card__row {
        display: grid;
        grid-template-columns: minmax(110px, 0.9fr) minmax(0, 1.1fr);
        align-items: start;
        gap: 12px;
    }

    .futures-mobile-card__row span {
        color: #8d93a6;
        font-size: 12px;
        line-height: 1.45;
    }

    .futures-mobile-card__row strong {
        color: #ffffff;
        font-size: 13px;
        line-height: 1.45;
        font-weight: 600;
        text-align: right;
        word-break: break-word;
    }

    .futures-mobile-empty {
        padding: 20px 14px;
        border-radius: 16px;
        color: #8d93a6;
        text-align: center;
        background: rgba(255, 255, 255, 0.025);
        border: 1px solid rgba(255, 255, 255, 0.08);
    }
}

@media (max-width: 1000px) {
    .futures-transactions-table {
        min-width: 1260px;
    }
}
</style>
