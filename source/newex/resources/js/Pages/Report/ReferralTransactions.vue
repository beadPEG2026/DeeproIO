<script>
import QRCode from 'qrcode';
import Template from '{Template}/Web/Pages/Report/ReferralTransactions.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import { string_cut } from "@/Functions/String";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter
    },

    props: {
        transactions: Object,
        filters: Object,
        referred_users: Object,
        total_referred_users: Number,
        today_referred_users: Number,
        referral_amount: [Number, String],
        today_referral_amount: [Number, String],
        current_date: String,
        selected_parent_user: {
            type: Object,
            default: null,
        },
        level_counts: {
            type: Object,
            default: () => ({})
        }
    },

    data() {
        return {
            sending: false,
            form: {
                search: this.filters && this.filters.search ? this.filters.search : null,

                email: this.filters && this.filters.email ? this.filters.email : null,

                level: this.filters
                    && this.filters.level !== null
                    && this.filters.level !== undefined
                    && this.filters.level !== ''
                    ? Number(this.filters.level)
                    : null,

                parent_id: this.filters && this.filters.parent_id ? Number(this.filters.parent_id) : null,

                parent_email: this.filters && this.filters.parent_email ? this.filters.parent_email : null,

                period: this.filters && this.filters.period && this.filters.period.length
                    ? this.filters.period
                    : ['2019-01-01', this.current_date],
            },
        }
    },

    computed: {
        inviteUrl() { return this.$page.props.user?.referral_code ? this.route('register',{referral:this.$page.props.user.referral_code}) : ''; },
        levelCountList() {
            return Array.from({ length: 9 }).map((_, level) => {
                const key = 's' + level;

                return {
                    key: level,
                    label: 'lv' + level,
                    value: this.level_counts && this.level_counts[key] ? this.level_counts[key] : 0,
                };
            });
        },

        hasSelectedLevel() {
            return this.form.level !== null && this.form.level !== undefined && this.form.level !== '';
        },

        isViewingChildUsers() {
            return !!this.form.parent_id;
        },

        currentParentEmail() {
            if (this.selected_parent_user && this.selected_parent_user.email) {
                return this.selected_parent_user.email;
            }

            return this.form.parent_email ? this.form.parent_email : '';
        },

        currentParentLevelLabel() {
            if (this.selected_parent_user && this.selected_parent_user.level_label) {
                return this.selected_parent_user.level_label;
            }

            return '';
        },

        currentFilterLevelLabel() {
            return this.hasSelectedLevel ? 'lv' + this.form.level : '';
        },

        currentViewingInvitesLabel() {
            if (this.currentFilterLevelLabel) {
                return this.$t('Viewing {level} users of')
                    .replace('{level}', this.currentFilterLevelLabel);
            }

            return this.$t('Viewing users of');
        },
    },

    mounted() { this.renderInviteQr(); },
    methods: {
        renderInviteQr() { this.$nextTick(() => { if (this.$refs.inviteQr && this.inviteUrl) QRCode.toCanvas(this.$refs.inviteQr,this.inviteUrl,{width:160,margin:1,errorCorrectionLevel:'M',color:{dark:'#17212e',light:'#ffffff'}}).catch(() => {}); }); },
        async shareInvite() { if (!this.inviteUrl) return; if (navigator.share) { try { await navigator.share({title:'Deepro',text:this.$t('Invite friends'),url:this.inviteUrl}); return; } catch(error) { if(error.name==='AbortError') return; } } this.copyInvite(); },
        copyInvite() { this.$copyText(this.route('register',{referral:this.$page.props.user.referral_code})).then(()=>this.$toast.open(this.$t('Text was copied to the clipboard'))).catch(()=>this.$toast.error(this.$t('Copy failed'))); },
        cleanQuery(source) {
            return pickBy(source, (value) => {
                if (Array.isArray(value)) {
                    return value.length > 0;
                }

                return value !== null && value !== undefined && value !== '';
            });
        },

        formatAmount(value, decimals = 4) {
            const number = parseFloat(String(value || 0).replace(/,/g, ''));

            if (!Number.isFinite(number)) {
                return Number(0).toFixed(decimals);
            }

            return number.toFixed(decimals);
        },

        getUserLevelLabel(user) {
            return user && user.level_label ? user.level_label : 'lv0';
        },

        format_string(string, limit) {
            return string_cut(string, limit);
        },

        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {
            })
        },

        getTodayDate() {
            if (this.current_date) {
                return this.current_date;
            }

            return new Date().toISOString().slice(0, 10);
        },

        getDefaultPeriod() {
            return ['2019-01-01', this.getTodayDate()];
        },

        /**
         * 点击“查看”后，只查询当天佣金。
         * 会把日期范围改成今天到今天，其它日期的数据不会再显示。
         */
        viewTodayCommission() {
            const today = this.getTodayDate();

            this.form.period = [today, today];

            this.$nextTick(() => {
                this.getList();
            });
        },

        /**
         * 只重置时间范围，不清空邮箱、等级、下级查看状态。
         */
        resetTime() {
            this.form.period = this.getDefaultPeriod();

            this.$nextTick(() => {
                this.getList();
            });
        },

        filterByLevel(level) {
            if (this.form.level === level) {
                this.form.level = null;
            } else {
                this.form.level = level;
            }
        },

        showDirectInvites(user) {
            if (!user || !user.id) {
                return;
            }

            this.form.parent_id = user.id;
            this.form.parent_email = user.email;
            this.form.level = null;
            this.form.email = null;
        },

        /**
         * 返回我的团队列表
         */
        backToMyTeam() {
            this.form.parent_id = null;
            this.form.parent_email = null;
            this.form.level = null;
        },

        reset() {
            this.form = {
                search: null,
                email: null,
                level: null,
                parent_id: null,
                parent_email: null,
                period: this.getDefaultPeriod()
            }
        },

        getList() {
            let query = this.cleanQuery(this.form);

            this.$inertia.replace(
                this.route(
                    'reports.referral-transactions',
                    Object.keys(query).length ? query : { remember: 'forget' }
                )
            );
        },
    },

    watch: {
        inviteUrl() { this.renderInviteQr(); },
        form: {
            handler: throttle(function () {
                this.getList()
            }, 150),
            deep: true,
        },
    },
})
</script>

<style>
.referral-dashboard {
    display: grid;
    gap: 22px;
    padding-bottom: 28px;
}

.referral-dashboard__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.referral-dashboard__header h2 {
    margin: 0;
    color: #ffffff;
    font-size: 28px;
    line-height: 1.2;
    font-weight: 800;
}

.referral-dashboard__header p,
.referral-section__head p {
    margin: 6px 0 0;
    color: #8d93a6;
    font-size: 13px;
    line-height: 1.45;
}

.referral-dashboard__ghost,
.referral-table__action {
    height: 36px;
    border: 1px solid rgba(234, 198, 69, 0.48);
    border-radius: 8px;
    padding: 0 14px;
    color: #f4d557;
    background: rgba(234, 198, 69, 0.09);
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    white-space: nowrap;
}

.referral-dashboard__ghost:hover,
.referral-table__action:hover {
    background: rgba(234, 198, 69, 0.16);
}

.referral-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.referral-stats__item,
.referral-section {
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 8px;
    background: rgba(15, 23, 42, 0.46);
}

.referral-stats__item {
    min-height: 110px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.referral-stats__item span,
.referral-controls label {
    color: #8d93a6;
    font-size: 12px;
    line-height: 1.4;
}

.referral-stats__item strong {
    margin-top: 10px;
    color: #ffffff;
    font-size: 26px;
    line-height: 1.1;
    font-weight: 800;
    word-break: break-word;
}

.referral-stats__item small {
    margin-top: 6px;
    color: #eac645;
    font-size: 12px;
    font-weight: 700;
}

.referral-controls {
    display: grid;
    grid-template-columns: minmax(220px, 0.7fr) minmax(260px, 1fr) auto;
    gap: 12px;
    align-items: end;
}

.referral-controls__search,
.referral-controls__date {
    display: grid;
    gap: 8px;
}

.referral-controls input,
.referral-controls .form-input,
.referral-controls .t-datepicker,
.referral-controls .vpd-input-group input {
    min-height: 42px;
    border: 1px solid rgba(148, 163, 184, 0.22) !important;
    border-radius: 8px !important;
    color: #ffffff !important;
    background: rgba(15, 23, 42, 0.62) !important;
}

.referral-controls input::placeholder {
    color: #6f7688;
}

.referral-controls__actions {
    display: flex;
    gap: 10px;
}

.referral-level-filter {
    display: grid;
    grid-template-columns: repeat(9, minmax(82px, 1fr));
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 2px;
}

.referral-level-filter__item {
    min-height: 72px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 8px;
    background: rgba(15, 23, 42, 0.46);
    cursor: pointer;
    display: grid;
    place-items: center;
    gap: 4px;
    color: #9aa3b8;
}

.referral-level-filter__item span {
    font-size: 12px;
    font-weight: 700;
}

.referral-level-filter__item strong {
    color: #ffffff;
    font-size: 22px;
    line-height: 1;
    font-weight: 800;
}

.referral-level-filter__item--active {
    border-color: rgba(234, 198, 69, 0.62);
    background: rgba(234, 198, 69, 0.12);
    color: #f4d557;
}

.referral-section {
    padding: 18px;
}

.referral-section__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 14px;
}

.referral-section__head h3 {
    margin: 0;
    color: #ffffff;
    font-size: 18px;
    line-height: 1.25;
    font-weight: 800;
}

.referral-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.referral-table {
    width: 100%;
    min-width: 920px;
    border-collapse: collapse;
}

.referral-table th {
    padding: 12px 10px;
    color: #8d93a6;
    font-size: 12px;
    font-weight: 700;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
}

.referral-table td {
    padding: 14px 10px;
    color: #dce5f3;
    font-size: 13px;
    line-height: 1.4;
    white-space: nowrap;
    border-bottom: 1px solid rgba(148, 163, 184, 0.09);
}

.referral-table__email {
    max-width: 280px;
    border: 0;
    padding: 0;
    color: #dce5f3;
    background: transparent;
    font-weight: 700;
    text-align: left;
    cursor: pointer;
    white-space: normal;
    word-break: break-word;
}

.referral-table__email:hover {
    color: #f4d557;
}

.referral-badge {
    min-height: 24px;
    border-radius: 999px;
    padding: 4px 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #9ae6d7;
    background: rgba(45, 212, 191, 0.12);
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
    white-space: nowrap;
}

.referral-badge--gold {
    color: #f4d557;
    background: rgba(234, 198, 69, 0.13);
}

.referral-badge--green {
    color: #63e6a5;
    background: rgba(34, 197, 94, 0.13);
}

.referral-badge--orange {
    color: #f5b76b;
    background: rgba(245, 158, 11, 0.14);
}

.referral-empty {
    padding: 22px 12px;
    color: #8d93a6;
    text-align: center;
    font-size: 13px;
}

@media (max-width: 1100px) {
    .referral-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .referral-controls {
        grid-template-columns: 1fr;
    }

    .referral-controls__actions {
        justify-content: flex-start;
    }
}

@media (max-width: 680px) {
    .referral-dashboard__header {
        flex-direction: column;
    }

    .referral-dashboard__header h2 {
        font-size: 24px;
    }

    .referral-stats {
        grid-template-columns: 1fr;
    }

    .referral-stats__item {
        min-height: 92px;
    }

    .referral-level-filter {
        grid-template-columns: repeat(9, 86px);
    }

    .referral-section {
        padding: 14px;
    }
}
</style>
