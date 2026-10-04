<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/CopyTrading/Show.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
    components: {
        AppLayout,
    },

    props: {
        trader: {
            type: Object,
            default: () => ({}),
        },
    },

    data() {
        return {
            activeTab: 'positions',
            activeChartIndex: 0,
            tabs: [
                { key: 'positions', label: legacyText("仓位") },
                { key: 'history', label: legacyText("仓位历史记录") },
                { key: 'actions', label: legacyText("最新操作记录") },
                { key: 'followers', label: legacyText("跟单者") },
            ],
        }
    },

    computed: {
        authUserData() {
            return this.$page && this.$page.props ? this.$page.props.user : null;
        },

        activeOrders() {
            const orders = this.trader && Array.isArray(this.trader.orders) ? this.trader.orders : [];

            return orders.filter((order) => order && order.status === 'active');
        },

        historyOrders() {
            const orders = this.trader && Array.isArray(this.trader.history_orders) ? this.trader.history_orders : [];

            return orders.slice(0, 10);
        },

        operationOrders() {
            const orders = this.trader && Array.isArray(this.trader.operation_orders) ? this.trader.operation_orders : [];

            return orders.slice(0, 10);
        },

        followers() {
            const followers = this.trader && Array.isArray(this.trader.followers) ? this.trader.followers : [];

            return followers.slice(0, 10);
        },

        totalOrdersCount() {
            return Number(this.trader.active_orders_count || 0) + Number(this.trader.closed_orders_count || 0);
        },

        followerProfit() {
            return Number(this.trader.profit_amount || 0) * 0.24;
        },

        sharpeRatio() {
            const roi = Math.abs(Number(this.trader.roi_percent || 0));
            const drawdown = Math.max(Number(this.trader.max_drawdown || 0), 1);

            return (roi / drawdown).toFixed(2);
        },

        winningPositions() {
            return Math.round(this.totalOrdersCount * (Number(this.trader.win_rate || 0) / 100));
        },

        donutStyle() {
            return {
                background: 'conic-gradient(#1f7bea 0 100%)',
            };
        },

        tooltipRoi() {
            return Math.max(0, Number(this.trader.roi_percent || 0) * 0.12);
        },

        chartPoints() {
            const values = this.chartValues();
            const width = 680;
            const height = 250;
            const min = Math.min(...values, 0);
            const max = Math.max(...values, 100);
            const range = max - min || 1;
            const end = new Date();

            return values.map((value, index) => {
                const x = values.length === 1 ? width : (index / (values.length - 1)) * width;
                const y = height - ((value - min) / range) * height;
                const date = new Date(end);

                date.setDate(end.getDate() - (values.length - 1 - index));

                return {
                    index: index,
                    x: x,
                    y: y,
                    roi: value,
                    date: this.formatDate(date),
                    fullDate: this.formatFullDate(date),
                };
            });
        },

        chartTooltip() {
            if (!this.chartPoints.length) {
                return {
                    date: '',
                    roi: 0,
                };
            }

            return this.chartPoints[Math.min(this.activeChartIndex, this.chartPoints.length - 1)];
        },

        chartTooltipStyle() {
            const point = this.chartTooltip;
            const left = ((point.x + 40) / 740) * 100;
            const top = ((point.y + 18) / 280) * 100;

            return {
                left: Math.max(4, Math.min(86, left)) + '%',
                top: Math.max(6, Math.min(76, top)) + '%',
            };
        },

        actionDateRange() {
            if (!this.operationOrders.length) {
                return '--';
            }

            const dates = this.operationOrders
                .map((order) => order.event_date)
                .filter((date) => date);

            if (!dates.length) {
                return '--';
            }

            return dates[dates.length - 1] + ' → ' + dates[0];
        },
    },

    methods: {
        authUser() {
            return this.authUserData;
        },

        goBack() {
            this.$inertia.visit('/copy-trading');
        },

        setActiveTab(tab) {
            this.activeTab = tab;
        },

        followButtonText() {
            if (!this.authUser()) {
                return legacyText("登录后跟单");
            }

            if (this.trader && this.trader.is_self) {
                return legacyText("自己的策略");
            }

            return this.trader && this.trader.is_following ? legacyText("取消跟单") : legacyText("跟单");
        },

        followButtonClass() {
            return this.trader && this.trader.is_following ? 'copy-detail-follow copy-detail-follow--active' : 'copy-detail-follow';
        },

        toggleFollow() {
            if (!this.trader || !this.trader.id) {
                return;
            }

            if (!this.authUser()) {
                this.$inertia.visit(this.route('login'));
                return;
            }

            if (this.trader.is_self) {
                return;
            }

            const url = '/copy-trading/' + this.trader.id + '/follow';

            if (this.trader.is_following) {
                this.$inertia.delete(url, {
                    preserveScroll: true,
                });
                return;
            }

            this.$inertia.post(url, {}, {
                preserveScroll: true,
            });
        },

        displayBadges() {
            if (!this.trader) {
                return [];
            }

            if (Array.isArray(this.trader.badges)) {
                return this.trader.badges.filter((badge) => badge);
            }

            return String(this.trader.badges || '')
                .split(/[,，\n]+/)
                .map((badge) => badge.trim())
                .filter((badge) => badge);
        },

        followerCapacity() {
            const current = Number(this.trader && this.trader.followers_count ? this.trader.followers_count : 0);
            const limit = Number(this.trader && this.trader.followers_limit ? this.trader.followers_limit : 0);

            if (limit > 0) {
                return this.formatInteger(current) + '/' + this.formatInteger(limit);
            }

            return this.formatInteger(current);
        },

        formatInteger(value) {
            return Number(value || 0).toLocaleString('en-US', {
                maximumFractionDigits: 0,
            });
        },

        formatMoney(value) {
            return Number(value || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        formatPercent(value) {
            return Number(value || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }) + '%';
        },

        detailLinePoints() {
            return this.chartPoints.map((point) => point.x.toFixed(2) + ',' + point.y.toFixed(2)).join(' ');
        },

        detailAreaPoints() {
            const line = this.detailLinePoints();

            return '0,250 ' + line + ' 680,250';
        },

        chartValues() {
            const provided = this.trader && Array.isArray(this.trader.chart_points) ? this.trader.chart_points : [];
            const numeric = provided.map((value) => Number(value)).filter((value) => Number.isFinite(value));

            if (numeric.length >= 2) {
                return numeric;
            }

            const roi = Math.abs(Number(this.trader.roi_percent || 0));
            const seed = Number(this.trader.id || 1);
            const peak = Math.max(760, Math.min(920, roi * 1.35 + 360));
            const shape = [46, 54, 49, 41, 57, 58, 230, 286, 735, 760, 710, 732, 775, 815, 672, 690, 625, 492, 220, 210, 236, 258, 249, 214, 255, 220, 306, 390, 490];
            const ratio = peak / 815;

            return shape.map((value, index) => {
                const wave = Math.sin((seed + index) * 0.87) * 8;

                return Math.max(0, value * ratio + wave);
            });
        },

        orderSideClass(order) {
            return order && order.side === 'long' ? 'copy-side-long' : 'copy-side-short';
        },

        tokenIconClass(order) {
            const symbol = String(order && order.base_symbol ? order.base_symbol : '').toLowerCase();

            if (symbol === 'btc') {
                return 'copy-market-dot copy-market-dot--btc';
            }

            if (symbol === 'eth') {
                return 'copy-market-dot copy-market-dot--eth';
            }

            return 'copy-market-dot';
        },

        pnlClass(order) {
            return order && order.is_profitable ? 'copy-positive' : 'copy-negative';
        },

        setChartPoint(index) {
            this.activeChartIndex = index;
        },

        operationClass(order) {
            if (!order) {
                return 'copy-operation-badge';
            }

            if (order.action_tone === 'green') {
                return 'copy-operation-badge copy-operation-badge--long';
            }

            return 'copy-operation-badge copy-operation-badge--short';
        },

        operationText(order) {
            if (!order) {
                return '';
            }

            const action = order.action_label || (order.side === 'long' ? legacyText("开多") : legacyText("开空"));
            const direction = order.trade_verb || (order.side === 'long' ? legacyText("买入") : legacyText("卖出"));
            const market = order.contract_label || ((order.market || '') + legacyText("永续合约"));
            const quantity = order.quantity || '0';
            const base = order.base_symbol || '';
            const value = order.notional_value || '0.00';
            const pnl = Number(order.pnl_amount || 0);
            const pnlText = order.action_kind === 'close'
                ? legacyText("，已实现盈亏为 ") + this.formatMoney(pnl) + ' USDT'
                : '';

            return legacyText("以均价为 ") + (order.price || order.entry_price) + 'USDT ' + direction + action + legacyText("至") + market + legacyText("，成交数量为 ") + quantity + base + legacyText("，总价值为 ") + value + 'USDT' + pnlText + '。';
        },

        formatDate(date) {
            return String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        },

        formatFullDate(date) {
            return date.getFullYear() + '-' + this.formatDate(date) + ' 08:00:00';
        },
    },
})
</script>

<style scoped>
.copy-detail-page {
    min-height: calc(100vh - 72px);
    padding: 18px 0 58px;
    color: #dfe4ec;
    background: #11161d;
    font-weight: 400;
}

.copy-detail-shell {
    width: min(1200px, calc(100% - 32px));
    margin: 0 auto;
}

.copy-detail-top,
.copy-detail-titlebar,
.copy-detail-metrics,
.copy-detail-badges,
.copy-detail-actions,
.copy-detail-tabs,
.copy-detail-chart-tabs,
.copy-detail-range,
.copy-order-market {
    display: flex;
    align-items: center;
}

.copy-detail-top {
    justify-content: space-between;
    gap: 24px;
}

.copy-detail-titlebar {
    gap: 18px;
}

.copy-back {
    border: 0;
    color: #f2f4f8;
    background: transparent;
    font-size: 16px;
    font-weight: 500;
}

.copy-pipe {
    width: 1px;
    height: 16px;
    background: #374151;
}

.copy-domain {
    min-height: 32px;
    padding: 0 16px;
    border: 0;
    border-radius: 6px;
    color: #f2f4f8;
    background: #222b37;
    font-size: 13px;
    font-weight: 500;
}

.copy-detail-actions {
    gap: 10px;
}

.copy-detail-follow {
    min-width: 160px;
    min-height: 36px;
    border: 1px solid rgba(232, 200, 82, 0.72);
    border-radius: 6px;
    color: #151a21;
    background: #e5c34b;
    font-weight: 500;
}

.copy-detail-follow--active {
    color: #dce3ee;
    border-color: #3a4555;
    background: #29323e;
}

.copy-detail-follow:disabled {
    cursor: not-allowed;
    color: #7f8796;
    border-color: #3a4555;
    background: #29323e;
}

.copy-profile {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 180px;
    gap: 28px;
    align-items: start;
    margin-top: 42px;
}

.copy-profile-name {
    color: #f2f4f8;
    font-size: 19px;
    font-weight: 500;
}

.copy-profile-desc {
    max-width: 860px;
    margin-top: 8px;
    color: #d6dbe5;
    font-size: 13px;
    line-height: 1.7;
}

.copy-translate {
    margin-top: 2px;
    color: #ffd438;
    font-size: 13px;
    font-weight: 500;
}

.copy-detail-badges {
    gap: 7px;
    flex-wrap: wrap;
    margin-top: 14px;
}

.copy-detail-badge {
    min-height: 20px;
    padding: 0 8px;
    border-radius: 4px;
    color: #dce3ee;
    background: #222b37;
    font-size: 12px;
}

.copy-detail-badge--hot {
    color: #f17d88;
    background: rgba(241, 125, 136, 0.12);
}

.copy-detail-metrics {
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 15px;
}

.copy-detail-metric {
    color: #858f9f;
    font-size: 12px;
}

.copy-detail-metric strong {
    color: #f2f4f8;
    font-size: 14px;
    font-weight: 500;
}

.copy-detail-grid {
    display: grid;
    grid-template-columns: 384px minmax(0, 1fr);
    gap: 24px;
    margin-top: 42px;
}

.copy-detail-panel {
    min-height: 328px;
    padding: 25px;
    border: 1px solid #2d3746;
    border-radius: 12px;
    background: #151a21;
}

.copy-detail-panel--wide {
    min-height: 328px;
}

.copy-panel-head {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 24px;
}

.copy-panel-title {
    color: #f2f4f8;
    font-size: 20px;
    font-weight: 500;
}

.copy-detail-range {
    gap: 6px;
    color: #f2f4f8;
    font-size: 13px;
    font-weight: 500;
}

.copy-detail-stat-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 16px;
    align-items: center;
    min-height: 40px;
    color: #858f9f;
    font-size: 13px;
}

.copy-detail-stat-value {
    color: #f2f4f8;
    font-weight: 500;
}

.copy-detail-stat-value--green,
.copy-positive {
    color: #20c98c;
}

.copy-negative {
    color: #ff6070;
}

.copy-detail-chart-tabs {
    gap: 12px;
}

.copy-detail-chart-tab {
    min-height: 36px;
    padding: 0 16px;
    border: 0;
    border-radius: 6px;
    color: #858f9f;
    background: transparent;
    font-size: 13px;
    font-weight: 500;
}

.copy-detail-chart-tab--active {
    color: #f2f4f8;
    background: #222b37;
}

.copy-line-chart {
    width: 100%;
    height: 260px;
}

.copy-chart-wrap {
    position: relative;
}

.copy-chart-tooltip {
    position: absolute;
    z-index: 2;
    min-width: 132px;
    padding: 10px 12px;
    border-radius: 3px;
    color: #f2f4f8;
    background: #596273;
    font-size: 12px;
    line-height: 1.5;
    pointer-events: none;
    transform: translate(-18px, -100%);
}

.copy-chart-tooltip::after {
    position: absolute;
    left: 50%;
    bottom: -8px;
    width: 0;
    height: 0;
    border-top: 8px solid #596273;
    border-right: 8px solid transparent;
    border-left: 8px solid transparent;
    content: '';
    transform: translateX(-50%);
}

.copy-chart-tooltip strong {
    display: block;
    font-weight: 500;
}

.copy-chart-grid {
    stroke: rgba(110, 123, 145, 0.32);
    stroke-width: 1;
}

.copy-chart-label {
    fill: #7f8796;
    font-size: 11px;
}

.copy-chart-area {
    fill: rgba(32, 201, 140, 0.08);
}

.copy-chart-line {
    fill: none;
    stroke: #20c98c;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 2.4;
}

.copy-chart-hit {
    cursor: pointer;
    fill: rgba(32, 201, 140, 0);
    stroke: transparent;
    stroke-width: 1;
}

.copy-chart-hit--active {
    fill: rgba(32, 201, 140, 0.22);
    stroke: #20c98c;
}

.copy-overview {
    margin-top: 24px;
}

.copy-donut-wrap {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) minmax(140px, 1fr);
    align-items: center;
    gap: 28px;
}

.copy-donut {
    position: relative;
    width: 180px;
    height: 180px;
    margin: 0 auto;
    border-radius: 50%;
}

.copy-donut::after {
    position: absolute;
    inset: 44px;
    border-radius: 50%;
    background: #151a23;
    content: '';
}

.copy-asset-legend {
    display: grid;
    gap: 20px;
}

.copy-asset-item {
    display: grid;
    grid-template-columns: 18px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    color: #f2f4f8;
    font-size: 12px;
    font-weight: 500;
}

.copy-asset-item strong {
    grid-column: 2;
    color: #858f9f;
    font-size: 12px;
    font-weight: 400;
}

.copy-asset-line {
    width: 18px;
    height: 4px;
    border-radius: 999px;
    background: #1f7bea;
}

.copy-detail-note {
    margin-top: 24px;
    color: #7f8796;
    font-size: 12px;
}

.copy-detail-tabs {
    gap: 30px;
    flex-wrap: wrap;
    margin-top: 42px;
}

.copy-detail-tab {
    position: relative;
    min-height: 36px;
    border: 0;
    color: #858f9f;
    background: transparent;
    font-size: 15px;
    font-weight: 500;
}

.copy-detail-tab--active {
    color: #f2f4f8;
}

.copy-detail-tab--active::after {
    position: absolute;
    left: 8px;
    right: 8px;
    bottom: 0;
    height: 2px;
    border-radius: 999px;
    background: #ffd438;
    content: '';
}

.copy-orders-panel {
    margin-top: 16px;
    overflow: hidden;
    border: 1px solid #2d3746;
    border-radius: 12px;
    background: #151a21;
}

.copy-history-wrap {
    margin-top: 16px;
}

.copy-history-toolbar {
    display: inline-flex;
    align-items: center;
    gap: 22px;
    min-height: 40px;
    margin-bottom: 18px;
    padding: 0 12px;
    border: 1px solid rgba(232, 200, 82, 0.42);
    border-radius: 6px;
    color: #7f8796;
    background: #151a21;
    font-size: 13px;
}

.copy-history-toolbar button {
    border: 0;
    color: #dfe4ec;
    background: transparent;
    font-size: 13px;
}

.copy-history-card {
    padding: 18px 0 18px;
    border-bottom: 1px solid rgba(73, 86, 108, 0.42);
}

.copy-history-title {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    min-height: 26px;
    color: #dfe4ec;
}

.copy-market-dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border-radius: 999px;
    color: #f2f4f8;
    background: #3f6fe4;
    font-size: 9px;
    font-weight: 500;
    text-transform: uppercase;
}

.copy-market-dot--btc {
    background: #f59b22;
}

.copy-market-dot--eth {
    background: #627eea;
}

.copy-history-market {
    color: #f2f4f8;
    font-size: 15px;
    font-weight: 500;
}

.copy-history-pill {
    min-height: 18px;
    padding: 0 6px;
    border-radius: 3px;
    color: #cbd3df;
    background: #222b37;
    font-size: 11px;
    line-height: 18px;
}

.copy-history-pill--green {
    color: #20c98c;
    background: rgba(32, 201, 140, 0.12);
}

.copy-history-close {
    margin-left: 6px;
    color: #dfe4ec;
    font-size: 13px;
}

.copy-history-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 28px;
    margin-top: 16px;
}

.copy-history-grid > div {
    display: grid;
    gap: 5px;
    align-content: start;
    min-height: 60px;
}

.copy-history-grid span {
    color: #7f8796;
    font-size: 12px;
}

.copy-history-grid strong {
    color: #dfe4ec;
    font-size: 12px;
    font-weight: 400;
}

.copy-date-filter {
    display: inline-flex;
    align-items: center;
    gap: 14px;
    min-height: 40px;
    margin: 22px 0 20px;
    padding: 0 14px;
    border: 1px solid #3b4658;
    border-radius: 6px;
    color: #f2f4f8;
    background: #171e27;
    font-size: 13px;
    font-weight: 500;
}

.copy-date-filter span {
    color: #7f8796;
}

.copy-timeline {
    margin-top: 12px;
    padding-bottom: 20px;
}

.copy-timeline-row {
    display: grid;
    grid-template-columns: 100px 28px minmax(0, 1fr);
    gap: 14px;
    min-height: 70px;
}

.copy-timeline-time {
    color: #7f8796;
    font-size: 12px;
    line-height: 24px;
}

.copy-timeline-time span {
    display: block;
}

.copy-timeline-dot {
    position: relative;
    width: 9px;
    height: 9px;
    margin-top: 7px;
    border-radius: 50%;
    background: #3c485a;
}

.copy-timeline-dot::after {
    position: absolute;
    top: 9px;
    left: 4px;
    width: 1px;
    height: 61px;
    background: #3c485a;
    content: '';
}

.copy-timeline-row:last-child .copy-timeline-dot::after {
    display: none;
}

.copy-timeline-body {
    padding-bottom: 20px;
    color: #9ba5b5;
    font-size: 12px;
    line-height: 1.8;
}

.copy-operation-badge {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    margin-bottom: 6px;
    padding: 0 8px;
    border-radius: 4px;
    color: #ff6070;
    background: rgba(255, 96, 112, 0.13);
    font-size: 12px;
    font-weight: 500;
}

.copy-operation-badge--long {
    color: #20c98c;
    background: rgba(32, 201, 140, 0.13);
}

.copy-operation-badge--short {
    color: #ff6070;
    background: rgba(255, 96, 112, 0.13);
}

.copy-timeline-text {
    color: #9ba5b5;
}

.copy-order-row {
    display: grid;
    grid-template-columns: 1.2fr 0.7fr 0.7fr 0.9fr 0.9fr 0.9fr;
    gap: 14px;
    align-items: center;
    min-height: 58px;
    padding: 0 18px;
    border-top: 1px solid rgba(73, 86, 108, 0.34);
    color: #dce3ee;
    font-size: 13px;
}

.copy-follower-row {
    display: grid;
    grid-template-columns: minmax(220px, 1.35fr) minmax(150px, 1fr) minmax(150px, 1fr) minmax(130px, 0.9fr) minmax(100px, 0.7fr);
    gap: 14px;
    align-items: center;
    min-height: 54px;
    padding: 0 18px;
    border-top: 1px solid rgba(73, 86, 108, 0.34);
    color: #dce3ee;
    font-size: 13px;
}

.copy-follower-row > div {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.copy-follower-row:first-child {
    border-top: 0;
}

.copy-follower-head {
    min-height: 46px;
    color: #858f9f;
    background: rgba(38, 48, 61, 0.48);
    font-size: 12px;
}

.copy-order-row:first-child {
    border-top: 0;
}

.copy-order-head {
    min-height: 46px;
    color: #858f9f;
    background: rgba(38, 48, 61, 0.48);
    font-size: 12px;
}

.copy-order-market {
    gap: 8px;
    color: #f2f4f8;
    font-weight: 500;
}

.copy-side-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 0 9px;
    border-radius: 999px;
    font-size: 12px;
}

.copy-side-long {
    color: #20c98c;
    background: rgba(32, 201, 140, 0.13);
}

.copy-side-short {
    color: #ff6070;
    background: rgba(255, 96, 112, 0.13);
}

.copy-empty {
    padding: 34px 18px;
    color: #858f9f;
    text-align: center;
}

@media (max-width: 980px) {
    .copy-profile,
    .copy-detail-grid,
    .copy-donut-wrap {
        grid-template-columns: 1fr;
    }

    .copy-profile-actions {
        width: 100%;
    }

    .copy-detail-follow {
        width: 100%;
    }
}

@media (max-width: 680px) {
    .copy-detail-shell {
        width: min(100% - 22px, 1200px);
    }

    .copy-detail-top,
    .copy-detail-titlebar {
        align-items: flex-start;
        flex-direction: column;
    }

    .copy-detail-panel {
        padding: 20px;
    }

    .copy-order-head {
        display: none;
    }

    .copy-follower-head {
        display: none;
    }

    .copy-order-row {
        grid-template-columns: 1fr 1fr;
        padding: 14px 16px;
    }

    .copy-order-row > div::before {
        display: block;
        margin-bottom: 4px;
        color: #858f9f;
        font-size: 11px;
        content: attr(data-label);
    }

    .copy-follower-row {
        grid-template-columns: 1fr;
        padding: 14px 16px;
    }

    .copy-history-grid {
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .copy-timeline-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .copy-timeline-dot {
        display: none;
    }
}
</style>
