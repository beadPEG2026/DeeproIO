<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/CopyTrading/Index.template'
import PublicationFields from '@/Components/Admin/PublicationFields.vue';
import AppLayout from '@/Layouts/AdminLayout'
import Pagination from '@/Jetstream/Pagination'
import throttle from 'lodash/throttle'

export default Template({
    components: {
        PublicationFields,
        AppLayout,
        Pagination,
    },

    props: {
        traders: Object,
        userOptions: Array,
        filters: Object,
    },

    data() {
        return {
            saving: false,
            query: {
                search: this.filters.search || '',
                user_search: this.filters.user_search || '',
                status: this.filters.status || '',
                per_page: this.filters.per_page || 20,
            },
            form: {
                publication_status: 'draft', publication_reference: '',
                user_id: '',
                display_name: '',
                strategy_label: '',
                history_orders_count: 0,
                win_rate: 0,
                section_label: legacyText("高盈亏"),
                display_followers_count: 0,
                display_followers_limit: 0,
                display_badges: '',
                display_profit_amount: 0,
                display_roi_percent: 0,
                display_asset_scale: 0,
                display_max_drawdown: 0,
                display_lead_days: 0,
                display_chart_points: '',
                sort_order: 0,
                is_enabled: true,
            },
            rows: this.prepareRows(this.traders),
        }
    },

    watch: {
        traders: {
            handler(value) {
                this.rows = this.prepareRows(value);
            },
            deep: true,
        },

        'query.search': throttle(function () {
            this.getList();
        }, 350),

        'query.status': function () {
            this.getList();
        },

        'query.per_page': function () {
            this.getList();
        },
    },

    computed: {
        safeUserOptions() {
            return Array.isArray(this.userOptions) ? this.userOptions : [];
        },
    },

    methods: {
        prepareRows(traders) {
            const data = traders && Array.isArray(traders.data) ? traders.data : [];

            return data.map((item) => ({
                ...item,
                edit_display_name: item.display_name || '',
                edit_strategy_label: item.strategy_label || '',
                edit_history_orders_count: item.history_orders_count || 0,
                edit_win_rate: item.win_rate || 0,
                edit_section_label: item.section_label || legacyText("高盈亏"),
                edit_display_followers_count: item.display_followers_count || 0,
                edit_display_followers_limit: item.display_followers_limit || 0,
                edit_display_badges: item.display_badges || '',
                edit_display_profit_amount: item.display_profit_amount || 0,
                edit_display_roi_percent: item.display_roi_percent || 0,
                edit_display_asset_scale: item.display_asset_scale || 0,
                edit_display_max_drawdown: item.display_max_drawdown || 0,
                edit_display_lead_days: item.display_lead_days || 0,
                edit_display_chart_points: item.display_chart_points || '',
                edit_sort_order: item.sort_order || 0,
                edit_is_enabled: !!item.is_enabled,
            }));
        },

        getList() {
            const params = {};

            Object.keys(this.query).forEach((key) => {
                const value = this.query[key];

                if (value !== null && value !== undefined && value !== '') {
                    params[key] = value;
                }
            });

            this.$inertia.get('/exchange-control-panel/copy-trading', params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        },

        searchUsers() {
            this.getList();
        },

        resetForm() {
            this.form = {
                publication_status: 'draft', publication_reference: '',
                user_id: '',
                display_name: '',
                strategy_label: '',
                history_orders_count: 0,
                win_rate: 0,
                section_label: legacyText("高盈亏"),
                display_followers_count: 0,
                display_followers_limit: 0,
                display_badges: '',
                display_profit_amount: 0,
                display_roi_percent: 0,
                display_asset_scale: 0,
                display_max_drawdown: 0,
                display_lead_days: 0,
                display_chart_points: '',
                sort_order: 0,
                is_enabled: true,
            };
        },

        addTrader() {
            if (!this.form.user_id) {
                this.showToast(legacyText("请选择用户"), 'error');
                return;
            }

            this.saving = true;

            this.$inertia.post('/exchange-control-panel/copy-trading', this.form, {
                preserveScroll: true,
                onSuccess: () => this.resetForm(),
                onFinish: () => {
                    this.saving = false;
                },
            });
        },

        saveTrader(row) {
            if (!row || !row.id) {
                return;
            }

            this.$inertia.put('/exchange-control-panel/copy-trading/' + row.id, {
                publication_revision: row.publication_revision,
                publication_status: row.publication_status,
                publication_reference: row.publication_reference,
                display_name: row.edit_display_name,
                strategy_label: row.edit_strategy_label,
                history_orders_count: row.edit_history_orders_count,
                win_rate: row.edit_win_rate,
                section_label: row.edit_section_label,
                display_followers_count: row.edit_display_followers_count,
                display_followers_limit: row.edit_display_followers_limit,
                display_badges: row.edit_display_badges,
                display_profit_amount: row.edit_display_profit_amount,
                display_roi_percent: row.edit_display_roi_percent,
                display_asset_scale: row.edit_display_asset_scale,
                display_max_drawdown: row.edit_display_max_drawdown,
                display_lead_days: row.edit_display_lead_days,
                display_chart_points: row.edit_display_chart_points,
                sort_order: row.edit_sort_order,
                is_enabled: row.edit_is_enabled,
            }, {
                preserveScroll: true,
            });
        },

        deleteTrader(row) {
            if (!row || !row.id) {
                return;
            }

            if (!window.confirm(legacyText("确定要从前台跟单展示中移除这个用户吗？"))) {
                return;
            }

            this.$inertia.delete('/exchange-control-panel/copy-trading/' + row.id, {
                preserveScroll: true,
            });
        },

        userLabel(user) {
            if (!user) {
                return '-';
            }

            return user.label || user.email || user.referral_code || ('UID ' + user.id);
        },

        accountLabel(row) {
            const user = row.user || {};
            const account = user.email || user.referral_code || ('UID ' + row.user_id);
            const nickname = user.nickname || user.leader_nickname || '';

            return nickname ? account + ' / ' + nickname : account;
        },

        showToast(message, type = 'success') {
            if (!this.$toast) {
                return;
            }

            this.$toast.open({
                message: message,
                type: type,
                duration: 2200,
            });
        },
    },
})
</script>

<style scoped>
.copy-admin-card {
    background: var(--ui-surface);
    border: 1px solid var(--ui-line);
    border-radius: 10px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.copy-admin-field label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    color: var(--ui-muted);
}

.copy-admin-input {
    width: 100%;
    min-height: 40px;
    border: 1px solid var(--ui-line);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 14px;
    color: var(--ui-text);
    background: var(--ui-surface);
    resize: vertical;
}

.copy-admin-table {
    width: 100%;
    min-width: 1580px;
}

.copy-admin-table th {
    padding: 16px 14px;
    font-size: 13px;
    color: var(--ui-text);
    text-align: left;
    font-weight: 600;
    background: var(--ui-surface);
}

.copy-admin-table td {
    padding: 14px;
    border-top: 1px solid var(--ui-line);
    vertical-align: top;
}
</style>
