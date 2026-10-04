<script>
import { legacyText } from '@/Functions/LegacyTranslation';
import { bsRatioToPercent, bsPercentToRatio } from '@/Functions/MarketBsPercentage.mjs';

import Template from '{Template}/Admin/Pages/Admin/Markets/Form.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";

const defaultForm = {
    name: null,
    quote_currency_id: null,
    base_currency_id: null,
    base_precision: null,
    quote_precision: null,
    min_trade_size: null,
    max_trade_size: null,
    min_trade_value: null,
    max_trade_value: null,
    min_market_buy_amount: null,
    base_ticker_size: null,
    quote_ticker_size: null,
    status: true,
    switch_chart: false,
    chart_source: 'binance',
    chart_symbol: null,
    chart_default_resolution: '1D',
    bs_percent: null,
    trade_status: true,
    buy_order_status: true,
    sell_order_status: true,
    cancel_order_status: true,
    has_futures: false,
    has_options: false,
    discount: 0,
    discount_bid: 0,
    options_min_amount: null,
    options_max_amount: null,
    custom_liquidity: false,

    // Trading Bot Settings
    bot_trend_direction: 'sideways',
    bot_trend_strength: 0.5,
    bot_volatility: 0.02,
    bot_volatility_burst_chance: 0.05,
    bot_price_floor: null,
    bot_orderbook_depth: 20,
    bot_spread_percentage: 0.001,
    bot_trade_frequency: 30,
    bot_cycle_interval: 2,
    bot_cycle_interval_min: 1000,
    bot_cycle_interval_max: 5000,
    bot_current_price: null,
    custom_liquidity_start_amount: null,
    custom_liquidity_end_amount: null,
    custom_liquidity_start_time: null,
    custom_liquidity_stop_time: null,
};

const chartSourceOptions = [
    { id: 'binance', name: 'Binance' },
    { id: 'mexc', name: 'MEXC' },
    { id: 'bybit', name: 'Bybit' },
];

const trendDirectionOptions = [
    { id: 'uptrend', name: legacyText("上涨趋势") },
    { id: 'downtrend', name: legacyText("下跌趋势") },
    { id: 'sideways', name: legacyText("震荡行情") },
];

const chartResolutionOptions = [
    { id: '1', name: '1 Minute' },
    { id: '5', name: '5 Minutes' },
    { id: '15', name: '15 Minutes' },
    { id: '30', name: '30 Minutes' },
    { id: '60', name: '1 Hour' },
    { id: '240', name: '4 Hours' },
    { id: '720', name: '12 Hours' },
    { id: '1D', name: '1 Day' },
    { id: '3D', name: '3 Days' },
    { id: '1W', name: '1 Week' },
    { id: '1M', name: '1 Month' },
];

export default Template({
    components: {
        NavButtonLink,
        AppLayout,
        TextInput,
        TextareaInput,
        LoadingButton,
        TrashedMessage,
        SelectInput
    },
    props: {
        errors: Object,
        market: Object,
        isEdit: {
            type: Boolean,
            default: false,
        },
        klineChanges: {
            type: Array,
            default: () => [],
        }
    },
    remember: 'form',
    data() {
        return {
            currencies: {},
            sending: false,
            savingBs: false,
            bsError: null,
            form: Object.assign({}, defaultForm),
            chartSourceOptions: chartSourceOptions,
            trendDirectionOptions: trendDirectionOptions,
            chartResolutionOptions: chartResolutionOptions,
            klineChangesOpen: true,
            klineChangesLocal: [],
            selectedKlineChangeIds: [],
            deletingKlineChanges: false,
            klinePricePendingCount: 0,
            klinePriceSavedAt: null,
            klinePriceError: null,
            klinePriceQueue: Promise.resolve(),
        }
    },
    mounted() {
        this.loadCurrencies();

        if (this.isEdit) {
            this.form = Object.assign({}, this.market, {
                bs_percent: bsRatioToPercent(this.market.bs),
            });
        }

        this.klineChangesLocal = Array.isArray(this.klineChanges)
            ? this.klineChanges.slice()
            : [];
    },
    watch: {
        klineChanges: function (value) {
            this.klineChangesLocal = Array.isArray(value) ? value.slice() : [];
        },
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? this.market.name : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Market' : 'Create Market';
        },
        normalizedKlineChanges: function () {
            return Array.isArray(this.klineChangesLocal) ? this.klineChangesLocal : [];
        },
        hasKlineChanges: function () {
            return this.normalizedKlineChanges.length > 0;
        },
        selectedKlineChangeCount: function () {
            return this.selectedKlineChangeIds.length;
        },
        isAllKlineChangesSelected: function () {
            return this.hasKlineChanges
                && this.selectedKlineChangeIds.length === this.normalizedKlineChanges.length;
        },
    },
    methods: {
        async saveBs() {
            if (!this.isEdit || this.savingBs || this.sending) return;
            if (this.$page.props.mode == 'readonly') {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.');
            }
            const submitted = this.form.bs_percent;
            this.savingBs = true;
            this.bsError = null;
            try {
                const response = await axios.put(this.route('admin.markets.bs.update', this.market.id), {
                    bs: bsPercentToRatio(submitted),
                });
                this.form.bs = response.data.bs;
                // Preserve any new input typed while the request was in flight.
                if (this.form.bs_percent === submitted) this.form.bs_percent = bsRatioToPercent(response.data.bs);
                this.$toast.open('BS ' + legacyText('已保存') + ': '
                    + (response.data.bs === null ? '—' : bsRatioToPercent(response.data.bs) + '%'));
            } catch (error) {
                const data = error.response && error.response.data;
                this.bsError = (data && data.errors && data.errors.bs && data.errors.bs[0])
                    || (data && data.message) || legacyText('There are some form errors');
                this.$toast.error(this.bsError);
            } finally {
                this.savingBs = false;
            }
        },

        submit() {
            if (this.savingBs || this.sending) return;
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.$toast.open('Market was saved');
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            this.form.base_ticker_size = 1 / Math.pow(10, this.form.base_precision);
            this.form.quote_ticker_size = 1 / Math.pow(10, this.form.quote_precision);

            /**
             * 提交 payload 单独复制一份，不要直接提交 this.form。
             *
             * 修复：
             * 编辑市场时，如果 last 当前有值，不提交 last 字段，
             * 避免保存普通配置时把市场最新价覆盖掉。
             *
             * 新增市场时仍然允许提交 last。
             */
            let payload = Object.assign({}, this.form);
            // Only the form uses percentages; the existing API/database keep bs ratios.
            // Convert the copied payload so retries never divide the form value twice.
            if (this.isEdit || payload.bs_percent !== null) {
                payload.bs = bsPercentToRatio(payload.bs_percent);
            }
            delete payload.bs_percent;

            if (this.isEdit && payload.last !== null && payload.last !== undefined && payload.last !== '') {
                delete payload.last;
            }

            if (this.isEdit) {
                this.$inertia.put(this.route('admin.markets.update', this.market.id), payload, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.markets.store'), payload, afterRequest);
            }
        },

        getMarketId() {
            return this.market && this.market.id
                ? this.market.id
                : this.form.id;
        },

        getKlinePriceAdjustmentUrl() {
            return `/exchange-control-panel/markets/${this.getMarketId()}/kline-price-adjustment`;
        },

        submitKlinePriceAdjustment() {
            if (!this.isEdit || !this.getMarketId()) {
                return;
            }

            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (this.form.bot_price_floor === null || this.form.bot_price_floor === undefined || this.form.bot_price_floor === '') {
                this.klinePriceError = legacyText("请输入价格百分比");
                return;
            }

            const payload = {
                bot_price_floor: this.form.bot_price_floor,
            };

            this.klinePriceError = null;
            this.klinePricePendingCount += 1;

            this.klinePriceQueue = this.klinePriceQueue
                .catch(() => {})
                .then(() => this.sendKlinePriceAdjustment(payload))
                .finally(() => {
                    this.klinePricePendingCount = Math.max(0, this.klinePricePendingCount - 1);
                });

            return this.klinePriceQueue;
        },

        sendKlinePriceAdjustment(payload) {
            return axios.post(this.getKlinePriceAdjustmentUrl(), payload, {
                headers: {
                    'Accept': 'application/json',
                },
            }).then((response) => {
                const data = response.data || {};

                if (data.market) {
                    this.form.bot_price_floor = data.market.bot_price_floor;
                    this.form.bot_price_ceiling = data.market.bot_price_ceiling;
                    this.form.custom_liquidity_t = data.market.custom_liquidity_t;

                    if (data.market.last !== null && data.market.last !== undefined) {
                        this.form.last = data.market.last;
                    }
                }

                if (Array.isArray(data.klineChanges)) {
                    this.klineChangesLocal = data.klineChanges;
                    this.selectedKlineChangeIds = this.selectedKlineChangeIds.filter((id) => {
                        return this.klineChangesLocal.some((change) => String(change.id) === String(id));
                    });
                }

                this.klinePriceSavedAt = new Date();
            }).catch((error) => {
                const message = error
                    && error.response
                    && error.response.data
                    && error.response.data.message
                        ? error.response.data.message
                        : legacyText("价格提交失败，请检查接口或权限");

                this.klinePriceError = message;
                this.$toast.error(message);
            });
        },

        destroy() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this market?')) {
                this.$inertia.delete(this.route('admin.markets.destroy', this.market.id), {
                    onSuccess: () => { this.$toast.open('Market was deleted'); },
                    onError: () => {
                        this.$toast.error(legacyText("There are some form errors"));
                    }
                })
            }
        },

        restore() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to restore this market?')) {
                this.$inertia.put(this.route('admin.markets.restore', this.market.id))
            }
        },

        loadCurrencies(id) {
            axios.get(this.route('admin.currencies'), {
                params: {
                    'json': true
                }
            }).then((res) => {
                this.currencies = res.data;
            })
        },

        toggleKlineChanges() {
            this.klineChangesOpen = !this.klineChangesOpen;
        },

        formatKlineNumber(value, suffix = '') {
            if (value === null || value === undefined || value === '') {
                return '-';
            }

            const number = Number(value);

            if (Number.isNaN(number)) {
                return value;
            }

            const text = number.toFixed(8).replace(/\.?0+$/, '');

            return suffix ? `${text}${suffix}` : text;
        },

        formatSignedKlineNumber(value) {
            if (value === null || value === undefined || value === '') {
                return '-';
            }

            const number = Number(value);

            if (Number.isNaN(number)) {
                return value;
            }

            const text = Math.abs(number).toFixed(8).replace(/\.?0+$/, '');

            if (number > 0) {
                return `+${text}`;
            }

            if (number < 0) {
                return `-${text}`;
            }

            return '0';
        },

        getKlinePriceChange(change) {
            if (!change) {
                return 0;
            }

            if (change.display_price_change !== null && change.display_price_change !== undefined && change.display_price_change !== '') {
                return Number(change.display_price_change);
            }

            if (change.price_change !== null && change.price_change !== undefined && change.price_change !== '') {
                return Number(change.price_change);
            }

            const beforePrice = Number(change.before_price || 0);
            const afterPrice = Number(change.after_price || 0);

            return afterPrice - beforePrice;
        },

        klinePriceChangeClass(change) {
            const priceChange = this.getKlinePriceChange(change);

            if (priceChange > 0) {
                return 'text-green-600';
            }

            if (priceChange < 0) {
                return 'text-red-600';
            }

            return 'text-gray-600';
        },

        klineDirectionClass(change) {
            return this.klinePriceChangeClass(change);
        },

        getKlineDirectionText(change) {
            const priceChange = this.getKlinePriceChange(change);

            if (priceChange > 0) {
                return legacyText("上涨");
            }

            if (priceChange < 0) {
                return legacyText("下跌");
            }

            return legacyText("持平");
        },

        getKlineBasePrice(change) {
            if (!change) {
                return '-';
            }

            if (change.display_base_price !== null && change.display_base_price !== undefined && change.display_base_price !== '') {
                return this.formatKlineNumber(change.display_base_price);
            }

            if (change.base_price !== null && change.base_price !== undefined && change.base_price !== '') {
                return this.formatKlineNumber(change.base_price);
            }

            if (change.before_price !== null && change.before_price !== undefined && change.before_price !== '') {
                return this.formatKlineNumber(change.before_price);
            }

            return '-';
        },

        getKlineChangeBaseUrl() {
            const marketId = this.market && this.market.id
                ? this.market.id
                : this.form.id;

            return `/exchange-control-panel/markets/${marketId}/kline-changes`;
        },

        toggleKlineChangeSelection(change) {
            if (!change || !change.id) {
                return;
            }

            const id = String(change.id);
            const index = this.selectedKlineChangeIds.indexOf(id);

            if (index >= 0) {
                this.selectedKlineChangeIds.splice(index, 1);
            } else {
                this.selectedKlineChangeIds.push(id);
            }
        },

        toggleAllKlineChangesSelection() {
            if (this.isAllKlineChangesSelected) {
                this.selectedKlineChangeIds = [];
                return;
            }

            this.selectedKlineChangeIds = this.normalizedKlineChanges
                .filter(item => item && item.id)
                .map(item => String(item.id));
        },

        deleteSelectedKlineChanges() {
            if (!this.isEdit || this.selectedKlineChangeIds.length <= 0) {
                return;
            }

            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (!confirm(legacyText("确定要删除选中的 {value0} 条 K 线修改记录吗？删除后对应时间段会恢复为外部原始 K 线。", {value0: this.selectedKlineChangeIds.length}))) {
                return;
            }

            this.deletingKlineChanges = true;

            const requestOptions = {
                headers: {
                    'Accept': 'application/json',
                },
                data: {
                    ids: this.selectedKlineChangeIds,
                },
            };

            axios.delete(this.getKlineChangeBaseUrl(), requestOptions).then(() => {
                this.$toast.open(legacyText("K线修改记录已删除"));
                this.selectedKlineChangeIds = [];

                this.$inertia.visit(window.location.href, {
                    preserveScroll: true,
                    preserveState: false,
                    replace: true,
                });
            }).catch(() => {
                this.$toast.error(legacyText("删除失败，请检查接口或权限"));
            }).finally(() => {
                this.deletingKlineChanges = false;
            });
        },

        clearKlineChanges() {
            if (!this.isEdit || !this.hasKlineChanges) {
                return;
            }

            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (!confirm(legacyText("确定要清空当前交易对全部 K 线修改记录吗？"))) {
                return;
            }

            this.deletingKlineChanges = true;

            axios.delete(this.getKlineChangeBaseUrl(), {
                headers: {
                    'Accept': 'application/json',
                }
            }).then(() => {
                this.$toast.open(legacyText("K线修改记录已清空"));
                this.selectedKlineChangeIds = [];

                this.$inertia.visit(window.location.href, {
                    preserveScroll: true,
                    preserveState: false,
                    replace: true,
                });
            }).catch(() => {
                this.$toast.error(legacyText("清空失败，请检查接口或权限"));
            }).finally(() => {
                this.deletingKlineChanges = false;
            });
        },
    },
});
</script>
