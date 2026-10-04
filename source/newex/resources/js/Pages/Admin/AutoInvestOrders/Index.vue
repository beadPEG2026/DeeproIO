<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/AutoInvestOrders/Index.template'
import AppLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'
import AdminReportsTab from "@/Components/Reports/AdminReportsTab";

export default Template({
    components: {
        AppLayout,
        Pagination,
        AdminReportsTab
    },
    props: {
        orders: Object,
        filters: Object,
    },
    data() {
        return {
            closingIds: [],
            releasingMarginIds: [],
            selectedSuperiorOrder: null,
            form: {
                search: this.filters.search || '',
                user: this.filters.user || '',
                user_id: this.filters.user_id || '',
                superior: this.filters.superior || '',
                order: this.filters.order || '',
                currency: this.filters.currency || '',
                investment_type: this.filters.investment_type || 'all',
                status: this.filters.status || 'all',
                date_from: this.filters.date_from || '',
                date_to: this.filters.date_to || '',
            },
        }
    },
    computed: {
        canManageAutoInvestOrders() {
            if (this.$page.props.can_manage_auto_invest_orders === true) {
                return true;
            }

            const user = this.$page.props.user || {};
            const roles = Array.isArray(user.roles) ? user.roles : [];

            return roles.includes('superadmin');
        },
    },
    methods: {
        reset() {
            this.form = mapValues(this.form, () => null);
            this.form.status = 'all';
            this.form.investment_type = 'all';
        },
        getList() {
            let query = pickBy(this.form, (value) => value !== null && value !== '');
            let url = '/exchange-control-panel/reports/auto-invest-orders';

            if (Object.keys(query).length) {
                url += '?' + new URLSearchParams(query).toString();
            } else {
                url += '?remember=forget';
            }

            this.$inertia.replace(url);
        },
        formatNumber(value, decimals = 8) {
            const number = parseFloat(value || 0);

            if (!Number.isFinite(number)) {
                return '0';
            }

            return number.toFixed(decimals).replace(/(\.\d*?[1-9])0+$/g, '$1').replace(/\.0+$/g, '');
        },
        formatAmount(value, symbol) {
            return this.formatNumber(value, 8) + (symbol ? ' ' + symbol : '');
        },
        getTypeLabel(order) {
            return order.investment_type === 'flexible' ? legacyText("活期") : legacyText("定期");
        },
        getStatusLabel(order) {
            const labels = {
                active: legacyText("进行中"),
                closed: legacyText("已关闭"),
                redeemed: legacyText("已赎回"),
            };

            return labels[order.status] || order.status || '-';
        },
        isClosing(order) {
            return this.closingIds.includes(order.id);
        },
        isReleasingMargin(order) {
            return this.releasingMarginIds.includes(order.id);
        },
        getUserDisplayName(user) {
            if (!user) {
                return '-';
            }

            return user.display_name || user.leader_nickname || user.nickname || user.email || user.phone || user.wallet_id || user.name || ('UID ' + user.id);
        },
        getUserAccount(user) {
            if (!user) {
                return '-';
            }

            return user.email || user.phone || user.wallet_id || user.name || '-';
        },
        getOrderUserName(order) {
            return order.user_nickname || order.user_email || order.user_phone || order.user_wallet_id || order.user_name || ('UID ' + order.user_id);
        },
        showAccount(value) {
            if (!value) {
                return '-';
            }

            return this.$page.props.mode == "readonly" ? legacyText("演示模式隐藏") : value;
        },
        openSuperiorModal(order) {
            if (!order || !order.superiors || !order.superiors.length) {
                return;
            }

            this.selectedSuperiorOrder = order;
        },
        closeSuperiorModal() {
            this.selectedSuperiorOrder = null;
        },
        closeOrder(order) {
            if (!this.canManageAutoInvestOrders) {
                this.$toast.error(legacyText("仅超级管理员可操作量化订单"));
                return;
            }

            if (!order || !order.can_close || this.isClosing(order)) {
                return;
            }

            if (!confirm(legacyText("确定关闭这个量化订单吗？关闭后本金和收益会按客户手动关闭的规则返还。"))) {
                return;
            }

            this.closingIds = [...this.closingIds, order.id];

            axios.post('/exchange-control-panel/reports/auto-invest-orders/' + order.id + '/close').then((response) => {
                const message = response.data && response.data.message ? response.data.message : legacyText("量化订单已关闭");
                this.$toast.open(message);
                this.getList();
            }).catch((error) => {
                let message = legacyText("关闭失败");

                if (error && error.response && error.response.data) {
                    message = error.response.data.message || message;
                }

                this.$toast.error(message);
            }).finally(() => {
                this.closingIds = this.closingIds.filter((id) => id !== order.id);
            });
        },
        releaseMargin(order) {
            if (!this.canManageAutoInvestOrders) {
                this.$toast.error(legacyText("仅超级管理员可解除量化占用"));
                return;
            }

            if (!order || !order.can_release_margin || this.isReleasingMargin(order)) {
                return;
            }

            if (!confirm(legacyText("确定强制解除这笔量化订单的保证金占用吗？该操作会让这笔量化订单的占用金额恢复为可用。"))) {
                return;
            }

            this.releasingMarginIds = [...this.releasingMarginIds, order.id];

            axios.post('/exchange-control-panel/reports/auto-invest-orders/' + order.id + '/release-margin').then((response) => {
                const message = response.data && response.data.message ? response.data.message : legacyText("已解除保证金占用");
                this.$toast.open(message);
                this.getList();
            }).catch((error) => {
                let message = legacyText("解除占用失败");

                if (error && error.response && error.response.data) {
                    message = error.response.data.message || message;
                }

                this.$toast.error(message);
            }).finally(() => {
                this.releasingMarginIds = this.releasingMarginIds.filter((id) => id !== order.id);
            });
        }
    },
    watch: {
        form: {
            handler: throttle(function() {
                this.getList();
            }, 200),
            deep: true,
        },
    },
})
</script>
