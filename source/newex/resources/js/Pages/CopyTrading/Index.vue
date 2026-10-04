<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/CopyTrading/Index.template'
import AppLayout from '@/Layouts/AppLayout'

export default Template({
    components: {
        AppLayout,
    },

    props: {
        unpublishedFollowing: {type: Array, default: () => []},
        traders: {
            type: Array,
            default: () => [],
        },
        stats: {
            type: Object,
            default: () => ({
                trader_count: 0,
                active_orders_count: 0,
                recent_orders_count: 0,
            }),
        },
        portfolio: {
            type: Object,
            default: () => ({
                active_orders_count: 0,
                pending_orders_count: 0,
                total_orders_count: 0,
                total_balance_usdt: '0.00',
                total_margin_usdt: '0.00',
                used_margin_usdt: '0.00',
                unrealized_pnl_usdt: '0.00',
                unrealized_pnl_percent: '0.00',
                orders: [],
            }),
        },
    },

    data() {
        return {
            activeTab: 'recommend',
            navTabs: [
                { key: 'recommend', label: legacyText("推荐") },
                { key: 'all', label: legacyText("全部项目") },
                { key: 'following', label: legacyText("我的收藏") },
            ],
        }
    },

    computed: {
        authUserData() {
            return this.$page && this.$page.props ? this.$page.props.user : null;
        },

        safeTraders() {
            return Array.isArray(this.traders) ? this.traders : [];
        },

        visibleTraders() {
            if (this.activeTab === 'following') {
                return this.safeTraders.filter((trader) => trader && trader.is_following);
            }

            return this.safeTraders;
        },

        traderSections() { return [{label:this.$t('Copy Trading'),traders:this.visibleTraders}]; },

        safePortfolio() {
            return this.portfolio && typeof this.portfolio === 'object' ? this.portfolio : {};
        },

        portfolioOrders() {
            return Array.isArray(this.safePortfolio.orders) ? this.safePortfolio.orders : [];
        },

        hasPortfolioOrders() {
            return this.portfolioOrders.length > 0;
        },
    },

    methods: {
        authUser() {
            return this.authUserData;
        },

        setActiveTab(tab) {
            this.activeTab = tab;
        },

        openTrader(trader) {
            if (!trader || !trader.id) {
                return;
            }

            this.$inertia.visit('/copy-trading/' + trader.id);
        },

        scrollToPortfolio() {
            if (!this.$refs || !this.$refs.portfolioPanel) {
                return;
            }

            this.$refs.portfolioPanel.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        },

        followButtonText(trader) {
            if (!this.authUser()) {
                return legacyText("登录后跟单");
            }

            if (trader && trader.is_self) {
                return legacyText("自己的策略");
            }

            return trader && trader.is_following ? legacyText("取消跟单") : legacyText("跟单");
        },

        followButtonClass(trader) {
            return trader && trader.is_following ? 'copy-card-action copy-card-action--active' : 'copy-card-action';
        },

        toggleFollow(trader) {
            if (!trader || !trader.id) {
                return;
            }

            if (!this.authUser()) {
                this.$inertia.visit(this.route('login'));
                return;
            }

            if (trader.is_self) {
                return;
            }

            const url = '/copy-trading/' + trader.id + '/follow';

            if (trader.is_following) {
                this.$inertia.delete(url, {
                    preserveScroll: true,
                });
                return;
            }

            this.$inertia.post(url, {}, {
                preserveScroll: true,
            });
        },

        displayBadges(trader) {
            if (!trader) {
                return [];
            }

            if (Array.isArray(trader.badges)) {
                return trader.badges.filter((badge) => badge);
            }

            return String(trader.badges || '')
                .split(/[,，\n]+/)
                .map((badge) => badge.trim())
                .filter((badge) => badge);
        },

        followerCapacity(trader) {
            const current = Number(trader && trader.followers_count ? trader.followers_count : 0);
            const limit = Number(trader && trader.followers_limit ? trader.followers_limit : 0);

            if (limit > 0) {
                return this.formatInteger(current) + ' / ' + this.formatInteger(limit);
            }

            return this.formatInteger(current);
        },

        formatInteger(value) {
            return Number(value || 0).toLocaleString('en-US', {
                maximumFractionDigits: 0,
            });
        },

        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        formatMoney(value) {
            return this.toNumber(value).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        formatPercent(value) {
            return this.toNumber(value).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }) + '%';
        },

        signedMoney(value) {
            const number = this.toNumber(value);
            const prefix = number > 0 ? '+' : '';

            return prefix + this.formatMoney(number);
        },

        signedPercent(value) {
            const number = this.toNumber(value);
            const prefix = number > 0 ? '+' : '';

            return prefix + this.formatPercent(number);
        },

        portfolioValueClass(value) {
            return this.toNumber(value) >= 0 ? 'copy-positive' : 'copy-negative';
        },

        orderSideClass(order) {
            return order && order.side === 'long' ? 'copy-position-side copy-position-side--long' : 'copy-position-side copy-position-side--short';
        },

        statDisplay(value) {
            const number = Number(value || 0);

            if (number === 0) {
                return '--';
            }

            return this.formatMoney(number);
        },

        sparklinePoints(trader) {
            const values = this.sparklineValues(trader);
            const width = 122;
            const height = 46;
            const min = Math.min(...values);
            const max = Math.max(...values);
            const range = max - min || 1;

            return values.map((value, index) => {
                const x = values.length === 1 ? width : (index / (values.length - 1)) * width;
                const y = height - ((value - min) / range) * height;

                return x.toFixed(2) + ',' + y.toFixed(2);
            }).join(' ');
        },

        sparklineValues(trader) {
            const provided = trader && Array.isArray(trader.chart_points) ? trader.chart_points : [];
            const numeric = provided.map((value) => Number(value)).filter((value) => Number.isFinite(value));

            if (numeric.length >= 2) {
                return numeric;
            }

            const seed = Number(trader && trader.id ? trader.id : 1);
            const profit = Math.abs(Number(trader && trader.profit_amount ? trader.profit_amount : 0));
            const roi = Number(trader && trader.roi_percent ? trader.roi_percent : 0);
            const base = profit > 0 ? Math.log10(profit + 10) * 8 : 18;
            const direction = roi >= 0 ? 1 : -1;

            return Array.from({ length: 14 }).map((_, index) => {
                const wave = Math.sin((index + seed) * 0.72) * 5;
                const step = direction * index * 1.35;
                const bump = index > 6 ? direction * 8 : 0;

                return base + wave + step + bump;
            });
        },
    },
})
</script>

<style scoped>
.copy-page {
    min-height: calc(100vh - 72px);
    padding: 0 0 56px;
    color: #dfe4ec;
    background: #11161d;
    font-weight: 400;
}

.copy-shell {
    width: min(1200px, calc(100% - 32px));
    margin: 0 auto;
    padding-top: 16px;
}

.copy-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 48px;
}

.copy-product-tabs,
.copy-nav-tabs,
.copy-section-head,
.copy-card-meta {
    display: flex;
    align-items: center;
}

.copy-product-tab {
    position: relative;
    min-height: 36px;
    padding: 0 2px;
    border: 0;
    color: #7f8796;
    background: transparent;
    font-size: 16px;
    font-weight: 500;
}

.copy-product-tab--active {
    color: #f2f4f8;
}

.copy-product-tab--active::after {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 0;
    height: 2px;
    border-radius: 999px;
    background: #f5c92f;
    content: '';
}

.copy-manage-btn,
.copy-card-action {
    border: 1px solid rgba(232, 200, 82, 0.7);
    border-radius: 6px;
    color: #151a21;
    background: #e5c34b;
    font-weight: 500;
    transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease;
}

.copy-hero {
    display: flex;
    align-items: flex-start;
    min-height: 150px;
    padding-top: 22px;
}

.copy-balance-label,
.copy-balance-sub,
.copy-card-kicker,
.copy-card-rate-label,
.copy-card-stat-label,
.copy-empty {
    color: #858f9f;
}

.copy-balance-label {
    font-size: 12px;
}

.copy-balance-value {
    margin-top: 8px;
    color: #f2f4f8;
    font-size: 31px;
    line-height: 1;
    font-weight: 500;
}

.copy-balance-sub {
    margin-top: 12px;
    font-size: 13px;
}

.copy-balance-sub span {
    margin-left: 4px;
    font-weight: 500;
}

.copy-balance-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    width: min(560px, 100%);
    margin-top: 16px;
}

.copy-balance-stat {
    min-height: 54px;
    padding: 10px 12px;
    border: 1px solid #27313d;
    border-radius: 8px;
    background: #151a21;
}

.copy-balance-stat span {
    display: block;
    color: #858f9f;
    font-size: 12px;
}

.copy-balance-stat strong {
    display: block;
    overflow: hidden;
    margin-top: 6px;
    color: #f2f4f8;
    font-size: 15px;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.copy-manage-btn {
    min-height: 32px;
    margin-top: 14px;
    padding: 0 14px;
    font-size: 13px;
}

.copy-positive {
    color: #20c98c !important;
}

.copy-negative {
    color: #ff6d75 !important;
}

.copy-portfolio {
    margin-top: 6px;
    padding: 16px;
    border: 1px solid #2d3746;
    border-radius: 10px;
    background: #151a21;
}

.copy-portfolio-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.copy-portfolio-title {
    color: #f2f4f8;
    font-size: 17px;
    font-weight: 500;
}

.copy-portfolio-sub {
    margin: 5px 0 0;
    color: #858f9f;
    font-size: 12px;
}

.copy-portfolio-count {
    min-width: 58px;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    color: #151a21;
    background: #e5c34b;
    font-size: 12px;
    font-weight: 500;
    line-height: 28px;
    text-align: center;
}

.copy-position-table {
    margin-top: 16px;
}

.copy-position-row {
    display: grid;
    grid-template-columns: minmax(150px, 1.5fr) minmax(96px, 0.8fr) 72px minmax(118px, 1fr) minmax(126px, 1fr) 86px minmax(140px, 1fr);
    gap: 12px;
    align-items: center;
    min-height: 52px;
    padding: 0 2px;
    border-top: 1px solid #27313d;
    color: #dfe4ec;
    font-size: 12px;
}

.copy-position-head {
    min-height: 34px;
    border-top: 0;
    color: #858f9f;
    font-size: 11px;
}

.copy-position-market,
.copy-position-trader {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.copy-position-market {
    color: #f2f4f8;
    font-size: 13px;
    font-weight: 500;
}

.copy-position-trader {
    margin-top: 3px;
    color: #858f9f;
}

.copy-position-side {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 0 7px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
}

.copy-position-side--long {
    color: #20c98c;
    background: rgba(32, 201, 140, 0.12);
}

.copy-position-side--short {
    color: #ff6d75;
    background: rgba(255, 109, 117, 0.12);
}

.copy-empty--portfolio {
    padding: 28px 0 14px;
}

.copy-nav-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-top: 30px;
}

.copy-nav-tabs {
    gap: 28px;
}

.copy-nav-tab {
    position: relative;
    min-height: 36px;
    border: 0;
    color: #858f9f;
    background: transparent;
    font-size: 15px;
    font-weight: 500;
}

.copy-nav-tab--active {
    color: #f2f4f8;
}

.copy-nav-tab--active::after {
    position: absolute;
    left: 2px;
    right: 2px;
    bottom: 0;
    height: 2px;
    border-radius: 999px;
    background: #f5c92f;
    content: '';
}

.copy-daily-btn {
    min-height: 28px;
    padding: 0 9px;
    border: 1px solid rgba(74, 85, 104, 0.72);
    border-radius: 6px;
    color: #cbd2df;
    background: #222a36;
    font-size: 12px;
}

.copy-section {
    margin-top: 26px;
}

.copy-section-head {
    justify-content: space-between;
    margin-bottom: 14px;
}

.copy-section-title {
    color: #f2f4f8;
    font-size: 19px;
    font-weight: 500;
}

.copy-more {
    border: 0;
    color: #7f8796;
    background: transparent;
    font-size: 14px;
}

.copy-card-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.copy-trader-card {
    min-height: 284px;
    padding: 15px 13px 13px;
    border: 1px solid #2d3746;
    border-radius: 10px;
    background: #151a21;
    cursor: pointer;
    transition: border-color 0.16s ease, background 0.16s ease;
}

.copy-trader-card:hover {
    border-color: #465467;
    background: #171d25;
}

.copy-card-head {
    min-height: 58px;
}

.copy-card-name {
    overflow: hidden;
    color: #f2f4f8;
    font-size: 15px;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.copy-card-meta {
    gap: 5px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.copy-card-capacity,
.copy-card-tag {
    display: inline-flex;
    align-items: center;
    min-height: 18px;
    padding: 0 5px;
    border-radius: 2px;
    color: #cbd2df;
    background: #222b37;
    font-size: 11px;
    line-height: 18px;
}

.copy-card-tag {
    color: #d4d9e2;
}

.copy-card-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 122px;
    gap: 10px;
    align-items: end;
    margin-top: 16px;
}

.copy-card-kicker {
    font-size: 12px;
}

.copy-profit {
    margin-top: 7px;
    color: #20c98c;
    font-size: 20px;
    line-height: 1.15;
    font-weight: 500;
}

.copy-card-rate {
    margin-top: 5px;
    font-size: 12px;
}

.copy-card-rate strong {
    color: #20c98c;
    font-weight: 500;
}

.copy-sparkline {
    width: 122px;
    height: 46px;
}

.copy-sparkline-line {
    fill: none;
    stroke: #20c98c;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 2.4;
}

.copy-card-stats {
    display: grid;
    gap: 8px;
    margin-top: 20px;
}

.copy-card-stat {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    min-height: 16px;
    font-size: 12px;
}

.copy-card-stat-value {
    color: #f2f4f8;
    font-weight: 500;
}

.copy-card-action {
    width: 100%;
    min-height: 32px;
    margin-top: 18px;
    font-size: 14px;
}

.copy-card-action--active {
    color: #dce3ee;
    border-color: #3a4555;
    background: #29323e;
}

.copy-card-action:disabled {
    cursor: not-allowed;
    color: #7f8796;
    border-color: #3a4555;
    background: #29323e;
}

.copy-card-action:hover,
.copy-manage-btn:hover {
    border-color: rgba(245, 216, 97, 0.9);
    background: #edcf62;
}

.copy-empty {
    padding: 42px 0;
    text-align: center;
    font-size: 14px;
}

@media (max-width: 1120px) {
    .copy-card-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 860px) {
    .copy-hero {
        padding-top: 14px;
    }

    .copy-balance-stats {
        grid-template-columns: 1fr;
        width: 100%;
    }

    .copy-card-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .copy-position-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 14px;
        align-items: start;
        min-height: 0;
        padding: 14px 0;
    }

    .copy-position-head {
        display: none;
    }

    .copy-position-row > div::before {
        display: block;
        margin-bottom: 5px;
        color: #858f9f;
        font-size: 11px;
        content: attr(data-label);
    }
}

@media (max-width: 620px) {
    .copy-shell {
        width: min(100% - 22px, 1200px);
    }

    .copy-topbar,
    .copy-nav-row {
        align-items: flex-start;
        flex-direction: column;
    }

    .copy-nav-tabs {
        width: 100%;
        justify-content: space-between;
        gap: 12px;
    }

    .copy-card-grid {
        grid-template-columns: 1fr;
    }

    .copy-portfolio {
        padding: 14px;
    }

    .copy-position-row {
        grid-template-columns: 1fr;
    }
}
</style>
