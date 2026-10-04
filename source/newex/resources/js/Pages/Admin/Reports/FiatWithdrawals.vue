<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/FiatWithdrawals.template'
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
import JetDialogModal from '@/Jetstream/DialogModal'
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'
import AdminReportsTab from "@/Components/Reports/AdminReportsTab";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        JetDialogModal,
        JetSecondaryButton,
        JetConfirmationModal,
        JetDangerButton,
        AdminReportsTab
    },

    props: {
        withdrawals: Object,
        filters: {
            type: Object,
            default: () => ({})
        },
    },

    data() {
        return {
            selectedAction: null,
            selectedWithdrawal: null,
            detailWithdrawal: null,

            withdrawalBeingReviewed: false,
            withdrawalBankInfoReviewed: false,
            bankInfo: null,

            showReasonModal: false,
            rejectedReason: null,
            rejectedReasonText: "",
            noteText: "",

            sending: false,

            form: {
                search: this.filters.search || null,
                type: this.filters.type || null,
                status: this.filters.status || null,
                payment_method: this.filters.payment_method || null,
                user_id: this.filters.user_id || null,
                withdrawal_id: this.filters.withdrawal_id || null,
                currency: this.filters.currency || null,
                bank: this.filters.bank || null,
                period: this.filters.period || null,
                per_page: this.filters.per_page || '100',
                referrer: this.filters.referrer || null,
            },

            filterTimer: null,
        }
    },

    beforeDestroy() {
        if (this.filterTimer) {
            clearTimeout(this.filterTimer);
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
    },

    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },

        doCopy(string) {
            if (!string) {
                return;
            }

            this.$copyText(string).then(() => {
                this.$toast.open(legacyText("Text was copied to the clipboard"));
            }, function (e) {

            })
        },

        queueSubmit() {
            if (this.filterTimer) {
                clearTimeout(this.filterTimer);
            }

            this.filterTimer = setTimeout(() => {
                this.getList();
            }, 450);
        },

        submitFilter() {
            this.getList();
        },

        reset() {
            this.form = {
                search: null,
                type: null,
                status: null,
                payment_method: null,
                user_id: null,
                withdrawal_id: null,
                currency: null,
                bank: null,
                period: null,
                per_page: '100',
                referrer: this.filters.referrer || null,
            };

            this.getList();
        },

        getList() {
            if (this.filterTimer) {
                clearTimeout(this.filterTimer);
            }

            let query = pickBy(this.form, value => {
                return value !== null && value !== undefined && value !== '';
            });

            this.$inertia.replace(
                this.route(
                    'admin.reports.withdrawals.fiat',
                    Object.keys(query).length ? query : { remember: 'forget' }
                )
            )
        },

        approve(withdrawal) {
            if (!this.isSuperAdmin) {
                return this.$toast.error(legacyText("只有超级管理员可以操作提现"));
            }

            this.selectedWithdrawal = withdrawal;
            this.selectedAction = 'approve';
            this.withdrawalBeingReviewed = true;
            this.noteText = '';
            this.rejectedReasonText = '';
        },

        reject(withdrawal) {
            if (!this.isSuperAdmin) {
                return this.$toast.error(legacyText("只有超级管理员可以操作提现"));
            }

            this.selectedWithdrawal = withdrawal;
            this.selectedAction = 'reject';
            this.withdrawalBeingReviewed = true;
            this.noteText = '';
            this.rejectedReasonText = '';
        },

        confirmAction() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (!this.selectedWithdrawal || !this.selectedAction) {
                return;
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.withdrawalBeingReviewed = false;
                    this.selectedWithdrawal = null;
                    this.selectedAction = null;
                    this.noteText = '';
                    this.rejectedReasonText = '';
                    this.$toast.open(legacyText("Withdrawal was moderated"));
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            let form = {
                action: this.selectedAction
            };

            if (this.selectedAction == "reject") {
                form.reason = this.rejectedReasonText.trim();
            }

            if (this.selectedAction == "approve") {
                form.note = this.noteText.trim();
            }

            this.$inertia.put(
                this.route('admin.reports.withdrawals.fiat.moderate', this.selectedWithdrawal.id),
                form,
                afterRequest
            );
        },

        showReason(withdrawal) {
            this.showReasonModal = true;
            this.rejectedReason = withdrawal.rejected_reason;
        },

        closeReasonModal() {
            this.showReasonModal = false;
            this.rejectedReason = null;
        },

        showBankInfo(withdrawal) {
            this.bankInfo = withdrawal;
            this.withdrawalBankInfoReviewed = true;
        },

        closeBankInfoModal() {
            this.withdrawalBankInfoReviewed = false;
            this.bankInfo = null;
        },

        openDetail(withdrawal) {
            this.detailWithdrawal = withdrawal;
        },

        closeDetail() {
            this.detailWithdrawal = null;
        },

        normalizedStatus(status) {
            if (status === 1 || status === '1') {
                return 'confirmed';
            }

            if (status === 2 || status === '2') {
                return 'rejected';
            }

            if (status === 0 || status === '0') {
                return 'pending';
            }

            return status || 'pending';
        },

        isPending(withdrawal) {
            return this.normalizedStatus(withdrawal.status) === 'pending';
        },

        isConfirmed(withdrawal) {
            return this.normalizedStatus(withdrawal.status) === 'confirmed';
        },

        isRejected(withdrawal) {
            return this.normalizedStatus(withdrawal.status) === 'rejected';
        },

        isVirtualWithdrawal(withdrawal) {
            if (!withdrawal || !withdrawal.user) {
                return false;
            }

            return withdrawal.user.is_xn === true
                || withdrawal.user.is_xn === 1
                || withdrawal.user.is_xn === '1'
                || withdrawal.user.is_xm === true
                || withdrawal.user.is_xm === 1
                || withdrawal.user.is_xm === '1';
        },

        parseJson(value) {
            if (!value) {
                return {};
            }

            if (typeof value === 'object') {
                return value;
            }

            try {
                return JSON.parse(value);
            } catch (e) {
                return {};
            }
        },

        detail(withdrawal) {
            if (!withdrawal) {
                return {};
            }

            if (withdrawal.admin_withdrawal_detail) {
                return withdrawal.admin_withdrawal_detail;
            }

            const note = this.parseJson(withdrawal.note);

            return {
                order_id: note.order_id || withdrawal.withdrawal_id,
                source: note.source,
                crypto_currency_id: note.crypto_currency_id || withdrawal.currency_id,
                crypto_symbol: note.crypto_symbol || (withdrawal.currency ? withdrawal.currency.symbol : null),
                crypto_amount: note.crypto_amount || withdrawal.amount,
                fiat_currency_id: note.fiat_currency_id,
                fiat_symbol: note.fiat_symbol,
                gross_fiat_amount: note.gross_fiat_amount,
                final_fiat_amount: note.final_fiat_amount || note.fiatAmount || note.amountOut,
                fee_percent: note.fee_percent,
                fee_crypto_amount: note.fee_crypto_amount || withdrawal.fee,
                fee_fiat_amount: note.fee_fiat_amount,
                exchange_rate: note.exchange_rate,
                price_in_fiat: note.price_in_fiat,
                price_in_usdt: note.price_in_usdt,
                usdt_to_fiat_rate: note.usdt_to_fiat_rate,
                locked_from_account: note.locked_from_account,
            };
        },

        detailValue(withdrawal, key) {
            const detail = this.detail(withdrawal);

            return detail ? detail[key] : null;
        },

        bankInfoData(withdrawal) {
            if (!withdrawal) {
                return {};
            }

            if (withdrawal.admin_bank_info) {
                return withdrawal.admin_bank_info;
            }

            return {
                bank_account_id: this.detailValue(withdrawal, 'bank_account_id'),
                payment_method: withdrawal.type,
                holder_name: withdrawal.account_holder_name,
                account_holder_name: withdrawal.account_holder_name,
                account_holder_address: withdrawal.account_holder_address,
                bank_name: withdrawal.name,
                bank_address: withdrawal.address,
                country_name: withdrawal.country ? withdrawal.country.name : null,
                account_number: withdrawal.iban,
                iban: withdrawal.iban,
                bic: withdrawal.swift,
                swift: withdrawal.swift,
                ifsc: withdrawal.ifsc,
                display_name: withdrawal.account_holder_name || withdrawal.iban || withdrawal.account_holder_address,
            };
        },

        bankValue(withdrawal, key) {
            const bank = this.bankInfoData(withdrawal);

            return bank ? bank[key] : null;
        },

        bankDisplayName(withdrawal) {
            const bank = this.bankInfoData(withdrawal);

            if (!bank) {
                return '-';
            }

            return bank.display_name
                || bank.holder_name
                || bank.account_holder_name
                || bank.account_number
                || bank.iban
                || bank.account_holder_address
                || '-';
        },

        withdrawalId(withdrawal) {
            return withdrawal.withdrawal_id || this.detailValue(withdrawal, 'order_id') || '-';
        },

        typeText(value) {
            const map = {
                cc: legacyText("银行卡"),
                bank: legacyText("银行转账"),
                ach: 'ACH',
                BANKCARD: legacyText("银行卡"),
                SEPA: 'SEPA Bank Transfer',
                LOCAL: 'Local Payment',
                local_offramp: legacyText("本地卖出"),
                perfectmoney: 'Perfect Money',
                payeer: 'Payeer',
                deriv: 'Deriv',
            };

            return map[value] || value || '-';
        },

        paymentText(withdrawal) {
            return this.typeText(this.bankValue(withdrawal, 'payment_method') || withdrawal.type);
        },

        cryptoSymbol(withdrawal) {
            return this.detailValue(withdrawal, 'crypto_symbol') || (withdrawal.currency ? withdrawal.currency.symbol : '-');
        },

        cryptoAmount(withdrawal) {
            return this.detailValue(withdrawal, 'crypto_amount') || withdrawal.amount || '0';
        },

        cryptoFee(withdrawal) {
            return this.detailValue(withdrawal, 'fee_crypto_amount') || withdrawal.fee || '0';
        },

        fiatSymbol(withdrawal) {
            return this.detailValue(withdrawal, 'fiat_symbol') || '-';
        },

        finalFiatAmount(withdrawal) {
            return this.detailValue(withdrawal, 'final_fiat_amount') || this.amountWithFee(withdrawal);
        },

        grossFiatAmount(withdrawal) {
            return this.detailValue(withdrawal, 'gross_fiat_amount') || '-';
        },

        fiatFee(withdrawal) {
            return this.detailValue(withdrawal, 'fee_fiat_amount') || '-';
        },

        amountWithFee(withdrawal) {
            const amount = parseFloat(withdrawal.amount || 0);
            const fee = parseFloat(withdrawal.fee || 0);

            return Number.isFinite(amount - fee) ? (amount - fee) : 0;
        },

        formatDate(value) {
            if (!value) {
                return '-';
            }

            return value;
        },
    },

    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 450),
            deep: true,
        },
    },
})
</script>
