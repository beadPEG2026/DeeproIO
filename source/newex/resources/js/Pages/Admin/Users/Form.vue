<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Users/Form.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";

const defaultForm = {
    name: null,
    email: '',
    phone: '',
    nickname: '',
    vip: 0,
    is_vip_update: 0,
    deactivated: null,
    withdrawal_disabled: false,
    p2p_trade_ban: false,
    excluded_from_transfer_fee: false,
    is_t: false,
    kyc_verified: false,
    email_verified: false,
    password: '',
    password_confirmation: '',
    tjremail: '',
    leader_nickname: '',
};

export default Template({
    components: {
        NavButtonLink,
        AppLayout,
        TextInput,
        TextareaInput,
        LoadingButton,
        TrashedMessage,
        SelectInput,
        EmptyColumn
    },

    props: {
        isEdit: {
            type: Boolean,
            default: false,
        },
        errors: Object,
        model: Object,
        roles: {
            type: [Array, Object],
            default: function () {
                return [];
            },
        },
        has2fa: {
            type: Boolean,
            default: false,
        },
    },

    data() {
        return {
            sending: false,
            sendingRoles: false,
            disabling2fa: false,
            impersonating: false,
            twoFactorEnabled: this.has2fa,
            roleType: '',
            functionPermissions: {},
            roleOptions: [
                {
                    value: 'superadmin',
                    title: legacyText("總後台"),
                    desc: legacyText("最高管理權限，可查看與管理所有資料。"),
                },
                {
                    value: 'admin',
                    title: legacyText("後台"),
                    desc: legacyText("後台管理角色，可依照勾選的功能權限進入對應頁面。"),
                },
                {
                    value: 'user_leader',
                    title: legacyText("組長"),
                    desc: legacyText("組長角色，用於查看與管理自己團隊資料。"),
                },
                {
                    value: 'salesman',
                    title: legacyText("業務員"),
                    desc: legacyText("業務角色，用於查看與維護自己名下客戶資料。"),
                },
            ],
            permissionGroups: [
                {title:'UMI 运营', permissions:[{key:'perm_umi_read',title:'查询',desc:'查看 UMI 用户、团队与业务记录'},{key:'perm_umi_export',title:'导出',desc:'导出完整 UMI 业务记录'},{key:'perm_umi_settlement',title:'结算',desc:'执行 UMI 收益结算'},{key:'perm_umi_burn',title:'销毁与恢复',desc:'提交销毁、核验及积分异常恢复；托管审批另行授权'},{key:'perm_umi_orders',title:'订单处理',desc:'核对并退回未托管充值款'},{key:'perm_umi_stock',title:'积分与份额',desc:'到期处理、份额划转及核销'},{key:'perm_umi_quotes',title:'报价',desc:'保存盘口凭证与核定股票报价'},{key:'perm_umi_legacy',title:'旧账户接续',desc:'开通旧账户、核定批次及本金接续'},{key:'perm_umi_settings',title:'业务配置',desc:'资金账户、业务开关及日率配置'}]},
                {
                    title: legacyText("基礎"),
                    permissions: [
                        { key: 'perm_dashboard', title: legacyText("儀表盤"), desc: legacyText("查看後台首頁統計資料") },
                    ],
                },
                {
                    title: legacyText("交易管理"),
                    permissions: [
                        { key: 'perm_markets', title: legacyText("市場"), desc: legacyText("市場交易對管理") },
                        { key: 'perm_currencies', title: legacyText("代幣"), desc: legacyText("代幣與幣種管理") },
                        { key: 'perm_networks', title: legacyText("網路"), desc: legacyText("鏈網路管理") },
                        { key: 'perm_bank_accounts', title: legacyText("銀行"), desc: legacyText("銀行帳戶管理") },
                        { key: 'perm_p2p', title: 'P2P', desc: legacyText("P2P 訂單與申訴處理") },
                        { key: 'perm_stakings', title: legacyText("質押"), desc: legacyText("質押產品管理") },
                        { key: 'perm_launchpads', title: legacyText("發射平台"), desc: legacyText("Launchpad 項目管理") },
                        { key: 'perm_quantify', title: legacyText("量化"), desc: legacyText("量化產品管理") },
                        { key: 'perm_liquidity', title: legacyText("任務"), desc: legacyText("流動性與任務管理") },
                    ],
                },
                {
                    title: legacyText("會員管理"),
                    permissions: [
                        { key: 'perm_users', title: legacyText("用戶"), desc: legacyText("用戶列表與用戶資料") },
                        { key: 'perm_kyc_documents', title: legacyText("實名認證"), desc: legacyText("KYC 文件審核") },
                        { key: 'perm_vouchers', title: legacyText("優惠券"), desc: legacyText("優惠券管理") },
                    ],
                },
                {
                    title: legacyText("充值提現"),
                    permissions: [
                        { key: 'perm_deposits', title: legacyText("充值"), desc: legacyText("充值報表與充值資料") },
                        { key: 'perm_withdrawals', title: legacyText("提現"), desc: legacyText("提現報表與提現資料") },
                        { key: 'perm_finances', title: legacyText("財務"), desc: legacyText("財務流水與交易記錄") },
                    ],
                },
                {
                    title: legacyText("其他"),
                    permissions: [
                        { key: 'perm_pages', title: legacyText("頁面"), desc: legacyText("靜態頁面管理") },
                        { key: 'perm_articles', title: legacyText("文章"), desc: legacyText("文章與公告管理") },
                        { key: 'perm_languages', title: legacyText("語言"), desc: legacyText("多語言資料管理") },
                        { key: 'perm_options_templates', title: legacyText("選項模板"), desc: legacyText("選項模板管理") },
                        { key: 'perm_support_tickets', title: legacyText("支持中心"), desc: legacyText("工單與客服支持") },
                        { key: 'perm_cold_storage', title: legacyText("冷錢包"), desc: legacyText("冷錢包管理") },
                    ],
                },
                {
                    title: legacyText("設置"),
                    permissions: [
                        { key: 'perm_settings', title: legacyText("設置"), desc: legacyText("系統設置管理") },
                    ],
                },
            ],
            form: Object.assign({}, defaultForm),
        }
    },

    mounted() {
        this.syncFormFromModel();
    },

    watch: {
        model: {
            handler() {
                this.syncFormFromModel();
            },
            deep: true,
        },
    },

    computed: {
        subTitle: function () {
            return this.model ? this.model.name : '';
        },

        actionButtonTitle: function () {
            return 'Update User';
        },

        currentRoleName() {
            const role = this.roleOptions.find(item => item.value === this.roleType);

            return role ? role.title : legacyText("未設定");
        },

        allPermissionKeys() {
            let keys = [];

            this.permissionGroups.forEach(group => {
                group.permissions.forEach(permission => {
                    keys.push(permission.key);
                });
            });

            return keys;
        },

        isSuperAdmin() {
            const roles = this.$page?.props?.user?.roles || this.$page?.props?.auth?.user?.roles || [];

            return roles.some(role => {
                if (typeof role === 'string') {
                    return role === 'superadmin';
                }

                return role.name === 'superadmin';
            });
        },

        currentUserId() {
            const user = this.$page?.props?.user || this.$page?.props?.auth?.user || null;

            return user && user.id ? Number(user.id) : null;
        },

        targetIsSuperAdmin() {
            return this.normalizeRoles(this.roles).some(role => {
                if (typeof role === 'string') {
                    return role === 'superadmin';
                }

                return Number(role.id) === 8 || role.name === 'superadmin';
            });
        },

        canImpersonate() {
            if (!this.isEdit || !this.model || !this.isSuperAdmin || this.targetIsSuperAdmin) {
                return false;
            }

            if (this.currentUserId && Number(this.model.id) === this.currentUserId) {
                return false;
            }

            return !this.form.deactivated;
        },
    },

    methods: {
        syncFormFromModel() {
            if (!this.isEdit || !this.model) {
                this.form = Object.assign({}, defaultForm);
                return;
            }

            this.form = Object.assign({}, defaultForm, this.model, {
                email: this.model.email ? String(this.model.email) : '',
                phone: this.model.phone ? String(this.model.phone) : '',
                nickname: this.model.nickname ? String(this.model.nickname) : '',
                leader_nickname: this.model.leader_nickname ? String(this.model.leader_nickname) : '',
                vip: this.model.vip !== null && this.model.vip !== undefined ? Number(this.model.vip) : 0,
                is_vip_update: this.model.is_vip_update !== null && this.model.is_vip_update !== undefined ? Number(this.model.is_vip_update) : 0,
                password: '',
                password_confirmation: '',
                tjremail: '',
            });

            this.roleType = this.getCurrentRoleType(this.roles);
            this.functionPermissions = this.getCurrentFunctionPermissions(this.roles);
            this.twoFactorEnabled = this.has2fa;
        },

        normalizeRoles(roles) {
            if (!roles) {
                return [];
            }

            if (Array.isArray(roles)) {
                return roles;
            }

            if (typeof roles === 'object') {
                return Object.values(roles);
            }

            return [];
        },

        getCurrentRoleType(roles) {
            const roleList = this.normalizeRoles(roles);

            if (roleList.includes('superadmin')) {
                return 'superadmin';
            }

            if (roleList.includes('admin')) {
                return 'admin';
            }

            if (roleList.includes('user_leader')) {
                return 'user_leader';
            }

            if (roleList.includes('salesman')) {
                return 'salesman';
            }

            return '';
        },

        getCurrentFunctionPermissions(roles) {
            const roleList = this.normalizeRoles(roles);
            const permissions = {};

            this.permissionGroups.forEach(group => {
                group.permissions.forEach(permission => {
                    permissions[permission.key] = roleList.includes(permission.key);
                });
            });

            return permissions;
        },

        generateRandomPassword() {
            const lowerLetters = 'abcdefghijklmnopqrstuvwxyz';
            const upperLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            const numbers = '0123456789';
            const symbols = '!@#$%^&*';

            const allChars = lowerLetters + upperLetters + numbers + symbols;

            const pick = (pool) => {
                return pool[Math.floor(Math.random() * pool.length)];
            };

            let chars = [
                pick(lowerLetters),
                pick(upperLetters),
                pick(numbers),
                pick(symbols),
            ];

            while (chars.length < 8) {
                chars.push(pick(allChars));
            }

            for (let i = chars.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                const temp = chars[i];
                chars[i] = chars[j];
                chars[j] = temp;
            }

            const password = chars.join('');

            this.form.password = password;
            this.form.password_confirmation = password;

            this.$toast.open(legacyText("已生成包含英文、数字和字符的 8 位密码"));
        },

        isGroupChecked(group) {
            return group.permissions.every(permission => {
                return !!this.functionPermissions[permission.key];
            });
        },

        isGroupPartChecked(group) {
            return group.permissions.some(permission => {
                return !!this.functionPermissions[permission.key];
            });
        },

        togglePermissionGroup(group) {
            const checked = !this.isGroupChecked(group);

            group.permissions.forEach(permission => {
                this.$set(this.functionPermissions, permission.key, checked);
            });
        },

        selectAllPermissions() {
            this.allPermissionKeys.forEach(key => {
                this.$set(this.functionPermissions, key, true);
            });
        },

        clearAllPermissions() {
            this.allPermissionKeys.forEach(key => {
                this.$set(this.functionPermissions, key, false);
            });
        },

        destroy() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this user?')) {
                this.$inertia.delete(this.route('admin.users.destroy', this.model.id), {
                    onSuccess: () => {
                        this.$toast.open('User was deleted');
                    }
                })
            }
        },

        submit() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.form.password = '';
                    this.form.password_confirmation = '';
                    this.$toast.open('User was updated');
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            this.$inertia.put(this.route('admin.users.update', this.model.id), this.form, afterRequest);
        },

        submitRoles() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (!this.roleType) {
                return this.$toast.error(legacyText("請選擇一個身份角色"));
            }

            let afterRequest = {
                onStart: () => this.sendingRoles = true,
                onFinish: () => this.sendingRoles = false,
                onSuccess: () => {
                    this.$toast.open(legacyText("使用者角色、功能權限與組長昵称已更新"));
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            this.$inertia.put(this.route('admin.users.roles.update', this.model.id), {
                role_type: this.roleType,
                leader_nickname: this.form.leader_nickname,
                permissions: this.functionPermissions,
            }, afterRequest);
        },

        disable2fa() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.');
            }

            if (!confirm('Are you sure you want to disable Two-Factor Authentication for this user? They will need to set it up again.')) {
                return;
            }

            this.disabling2fa = true;

            axios.post(this.route('admin.users.disable2fa', this.model.id))
                .then(response => {
                    this.disabling2fa = false;

                    if (response.data.success) {
                        this.twoFactorEnabled = false;
                        this.$toast.open(response.data.message);
                    } else {
                        this.$toast.error(response.data.message);
                    }
                })
                .catch(error => {
                    this.disabling2fa = false;
                    this.$toast.error(error.response?.data?.message || 'Failed to disable 2FA');
                });
        },

        impersonateUser() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.');
            }

            if (!this.canImpersonate || this.impersonating) {
                return;
            }

            const displayName = this.form.email || this.form.phone || this.form.wallet_id || this.form.name || `ID ${this.model.id}`;

            if (!confirm(legacyText("确认模拟登录用户 {value0}？", {value0: displayName}))) {
                return;
            }

            this.impersonating = true;

            axios.post(`/exchange-control-panel/users/${this.model.id}/impersonate`)
                .then(response => {
                    const data = response.data || {};

                    if (!data.success) {
                        this.$toast.error(data.message || legacyText("模拟登录失败"));
                        return;
                    }

                    this.$toast.open(data.message || legacyText("已模拟登录该用户"));
                    window.location.href = data.redirect || '/markets';
                })
                .catch(error => {
                    this.$toast.error(error.response?.data?.message || legacyText("模拟登录失败"));
                })
                .finally(() => {
                    this.impersonating = false;
                });
        },
    },
});
</script>
