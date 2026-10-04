<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/Wallets.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import AdminReportsTab from "@/Components/Reports/AdminReportsTab";
import TextInput from '@/Jetstream/TextInput'
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetSuccessButton from '@/Jetstream/SuccessButton'


export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        TextInput,
        Pagination,
        SearchFilter,
        AdminReportsTab,
        JetDialogModal,
        JetSecondaryButton,
        JetSuccessButton
    },
    props: {
        wallets: Object,
        filters: Object,
    },
    data() {
        return {
            selectedWallet: null,
            sending: false,
            form: {
                search: this.filters.search,
                type: this.filters.type,
                user: this.filters.user
            },
            deposit: '',
            note: '',
            sourceWallet: '', sourceBalanceType: 'wallet', reference: '', requestKey: '',
            accountType: 'real',
            balanceType: 'wallet',
            fundSource: 'admin_fund',
        }
    },
    computed: {
        isSuperAdmin() {
            const roles = this.$page?.props?.user?.roles || [];

            return roles.some(role => {
                if (typeof role === 'string') {
                    return role === 'superadmin';
                }

                return role && (role.name === 'superadmin');
            });
        },
        showFundSourceOptions() {
            return this.accountType === 'real'
                && this.balanceType === 'wallet';
        },
        canUsePlatformInternalTransfer() {
            return this.showFundSourceOptions && parseFloat(this.deposit || 0) > 0;
        },
        effectiveFundSource() {
            return this.canUsePlatformInternalTransfer ? this.fundSource : 'admin_fund';
        }
    },
    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(legacyText("Text was copied to the clipboard"));
            }, function (e) {

            })
        },
        reset() {
            this.form = mapValues(this.form, () => null)
        },
        getList() {
            let query = pickBy(this.form)
            this.$inertia.replace(this.route('admin.reports.wallets', Object.keys(query).length ? query : { remember: 'forget' }))
        },
        fetchUser (q) {
            return axios.get(this.route('admin.users.fetch'), {
                params: {
                    q: q
                }
            }).then((response) => {
                return { results: response.data.users};
            });
        },
        transfer(wallet) {
            if (!this.isSuperAdmin) {
                return;
            }

            this.selectedWallet = wallet;
            this.sourceWallet = ''; this.reference = ''; this.sourceBalanceType = 'wallet';
            this.requestKey = crypto.randomUUID();
            this.deposit = '';
            this.note = '';
            this.accountType = 'real';
            this.balanceType = 'wallet';
            this.fundSource = 'admin_fund';
        },
        transferConfirm() {
            if (!this.isSuperAdmin) {
                this.$toast.error(legacyText("只有超级管理员可以充值"));
                return;
            }

            if ((this.deposit + '').trim() === "") {
                this.$toast.error('Deposit amount must be numeric');
                return;
            }

            if (this.sending) return;
            this.sending = true;
            axios.post(this.route('admin.reports.wallets.fund'), {
                'source_wallet_id': this.sourceWallet, 'source_balance_type': this.sourceBalanceType, 'reference': this.reference, 'idempotency_key': this.requestKey,
                'amount': this.deposit,
                'wallet': this.selectedWallet.id,
                'note': this.note,
                'account_type': this.accountType,
                'balance_type': this.balanceType,
                'fund_source': this.effectiveFundSource,
            }).then((response) => {
                this.$toast.open(this.$t(response.data.pending_review ? 'Submitted for independent review.' : 'Review recorded.'));
                this.selectedWallet = null;
                this.deposit = 0;
                this.note = '';
                this.accountType = 'real';
                this.balanceType = 'wallet';
                this.fundSource = 'admin_fund';
                this.getList();
            }).catch(error => { this.$toast.error(Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || this.$t('Request failed')); }).finally(() => { this.sending = false; });
        }
    },
    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
    },
})
</script>
