<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Reports/FiatDeposits.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
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
        deposits: Object,
        filters: {
            type: Object,
            default: () => ({})
        },
    },

    data() {
        return {
            receipt: null,
            selectedAction: null,
            selectedDeposit: null,
            depositBeingReviewed: null,
            showReceipt: false,
            showReasonModal: false,
            rejectedReason: null,
            rejectedReasonText: "",
            sending: false,
            filterTimer: null,

            form: {
                search: this.filters.search || null,
                type: this.filters.type || null,
                status: this.filters.status || null,
                payment_method: this.filters.payment_method || null,
                user_id: this.filters.user_id || null,
                deposit_id: this.filters.deposit_id || null,
                currency: this.filters.currency || null,
                bank: this.filters.bank || null,
                period: this.filters.period || null,
                per_page: this.filters.per_page || '100',
                referrer: this.filters.referrer || null,
            },
        }
    },

    beforeDestroy() {
        if (this.filterTimer) {
            clearTimeout(this.filterTimer);
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
                deposit_id: null,
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
                    'admin.reports.deposits.fiat',
                    Object.keys(query).length ? query : { remember: 'forget' }
                )
            );
        },

        showModal(receipt) {
            if (!receipt) {
                return;
            }

            this.receipt = receipt;
            this.showReceipt = true;
        },

        closeModal() {
            this.receipt = null;
            this.showReceipt = false;
        },

        openDetail(deposit) {
            this.selectedDeposit = deposit;
        },

        closeDetail() {
            this.selectedDeposit = null;
        },

        approve(deposit) {
            this.depositBeingReviewed = deposit;
            this.selectedAction = 'approve';
            this.rejectedReasonText = "";
        },

        reject(deposit) {
            this.depositBeingReviewed = deposit;
            this.selectedAction = 'reject';
            this.rejectedReasonText = "";
        },

        confirmAction() {
            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (!this.depositBeingReviewed || !this.selectedAction) {
                return;
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.depositBeingReviewed = null;
                    this.selectedAction = null;
                    this.rejectedReasonText = "";
                    this.$toast.open('Deposit was moderated');
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            let form = {
                action: this.selectedAction
            };

            if (this.selectedAction == "reject") {
                if (this.rejectedReasonText.trim() == "") {
                    alert("Please fill the rejection reason field");
                    return false;
                }

                form.reason = this.rejectedReasonText;
            }

            this.$inertia.put(
                this.route('admin.reports.deposits.fiat.moderate', this.depositBeingReviewed.id),
                form,
                afterRequest
            );
        },

        showReason(deposit) {
            this.showReasonModal = true;
            this.rejectedReason = deposit.rejected_reason;
        },

        closeReasonModal() {
            this.showReasonModal = false;
            this.rejectedReason = null;
        },

        isPending(deposit) {
            return this.normalizedStatus(deposit.status) === 'pending';
        },

        isConfirmed(deposit) {
            return this.normalizedStatus(deposit.status) === 'confirmed';
        },

        isRejected(deposit) {
            return this.normalizedStatus(deposit.status) === 'rejected';
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

        typeText(deposit) {
            return this.paymentText(this.bankValue(deposit, 'payment_method') || deposit.type);
        },

        paymentText(value) {
            const map = {
                cc: legacyText("银行卡"),
                bank: legacyText("银行转账"),
                ach: 'ACH',
                BANKCARD: legacyText("银行卡"),
                SEPA: 'SEPA Bank Transfer',
                LOCAL: 'Local Payment',
                local_onramp: legacyText("本地买入"),
                local_offramp: legacyText("本地卖出"),
            };

            return map[value] || value || '-';
        },

        detail(deposit) {
            if (!deposit) {
                return {};
            }

            if (deposit.admin_deposit_detail) {
                return deposit.admin_deposit_detail;
            }

            const note = this.parseJson(deposit.note);

            return {
                order_id: note.order_id || deposit.deposit_id,
                source: note.source,
                crypto_currency_id: note.crypto_currency_id,
                crypto_symbol: note.crypto_symbol || note.crypto,
                crypto_amount: note.estimated_crypto_amount || note.crypto_amount || note.amountOut,
                fiat_currency_id: note.fiat_currency_id || deposit.currency_id,
                fiat_symbol: note.fiat_symbol || (deposit.currency ? deposit.currency.symbol : null),
                fiat_amount: note.fiat_amount || deposit.amount,
                fee_percent: note.fee_percent,
                fee_amount: note.fee_amount || deposit.fee,
                exchange_rate: note.exchange_rate,
                price_in_fiat: note.price_in_fiat,
                price_in_usdt: note.price_in_usdt,
                usdt_to_fiat_rate: note.usdt_to_fiat_rate,
            };
        },

        detailValue(deposit, key) {
            const detail = this.detail(deposit);

            return detail ? detail[key] : null;
        },

        bankInfo(deposit) {
            if (!deposit) {
                return {};
            }

            if (deposit.admin_bank_info) {
                return deposit.admin_bank_info;
            }

            const note = this.parseJson(deposit.note);
            const snapshot = this.parseJson(deposit.bank_account_snapshot || note.bank_account_snapshot);

            const holderName = snapshot.account_holder_name
                || snapshot.holder_name
                || snapshot.account_name
                || snapshot.name
                || [snapshot.first_name, snapshot.last_name].filter(Boolean).join(' ');

            const accountNumber = snapshot.account_number
                || snapshot.iban
                || snapshot.card_number
                || snapshot.card_no;

            const bic = snapshot.bic
                || snapshot.swift
                || snapshot.swift_code;

            const noteDisplayName = this.hasMask(note.bank_account_name) ? null : note.bank_account_name;

            return {
                bank_account_id: deposit.bank_account_id || note.bank_account_id,
                payment_method: deposit.payment_method || note.payment_method || note.payment || deposit.type,
                display_name: noteDisplayName || this.buildBankDisplay(snapshot),
                holder_name: holderName,
                bank_name: snapshot.bank_name || snapshot.bank || snapshot.bank_code,
                account_number: accountNumber,
                bic: bic,
            };
        },

        bankValue(deposit, key) {
            const bank = this.bankInfo(deposit);

            return bank ? bank[key] : null;
        },

        bankDisplayName(deposit) {
            const bank = this.bankInfo(deposit);

            if (!bank) {
                return '-';
            }

            return bank.display_name
                || bank.account_number
                || bank.bank_name
                || bank.holder_name
                || '-';
        },

        buildBankDisplay(snapshot) {
            if (!snapshot || Object.keys(snapshot).length === 0) {
                return null;
            }

            const holder = snapshot.account_holder_name
                || snapshot.holder_name
                || snapshot.account_name
                || snapshot.name
                || [snapshot.first_name, snapshot.last_name].filter(Boolean).join(' ');

            const number = snapshot.iban
                || snapshot.account_number
                || snapshot.card_number
                || snapshot.card_no;

            const bic = snapshot.bic
                || snapshot.swift
                || snapshot.swift_code;

            return [holder, number, bic].filter(Boolean).join(' - ');
        },

        hasMask(value) {
            return typeof value === 'string' && value.indexOf('*') !== -1;
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

        cryptoSymbol(deposit) {
            return this.detailValue(deposit, 'crypto_symbol') || '-';
        },

        cryptoAmount(deposit) {
            return this.detailValue(deposit, 'crypto_amount') || '-';
        },

        fiatSymbol(deposit) {
            return this.detailValue(deposit, 'fiat_symbol') || (deposit.currency ? deposit.currency.symbol : '-');
        },

        fiatAmount(deposit) {
            return this.detailValue(deposit, 'fiat_amount') || deposit.amount || '0';
        },

        feeAmount(deposit) {
            return this.detailValue(deposit, 'fee_amount') || deposit.fee || '0';
        },

        formatDate(value) {
            if (!value) {
                return '-';
            }

            return value;
        },
    },
})
</script>

<style scoped>
.admin-filter-panel {
    background: #f5f6f8;
    border-radius: 8px;
    padding: 0;
}

.admin-filter-grid {
    display: grid;
    grid-template-columns: 1.35fr 0.85fr 0.85fr 0.95fr 0.85fr 1.2fr 0.85fr 1.2fr 1.2fr 0.85fr auto;
    gap: 12px;
    align-items: end;
}

.admin-filter-item label {
    display: block;
    color: #374151;
    font-size: 14px;
    margin-bottom: 7px;
}

.admin-filter-item input,
.admin-filter-item select {
    min-height: 40px;
    border: 1px solid #9ca3af;
    border-radius: 3px;
    background: #ffffff;
    color: #374151;
    font-size: 14px;
}

.admin-filter-actions {
    display: flex;
    align-items: end;
    height: 67px;
}

.admin-filter-reset {
    height: 40px;
    min-width: 60px;
    padding: 0 16px;
    border-radius: 4px;
    background: #10b981;
    color: #ffffff;
    font-weight: 600;
    border: none;
    cursor: pointer;
}

.admin-filter-reset:hover {
    background: #059669;
}

.admin-fiat-table th {
    white-space: nowrap;
    color: #374151;
}

.admin-fiat-table td {
    vertical-align: top;
}

.min-w-bank {
    min-width: 230px;
}

.admin-action-cell {
    min-width: 180px;
}

.admin-detail-btn {
    height: 32px;
    padding: 0 12px;
    border-radius: 6px;
    background: #eef2ff;
    color: #4f46e5;
    font-size: 13px;
    font-weight: 600;
}

.admin-detail-btn:hover {
    background: #e0e7ff;
}

.admin-detail-modal {
    display: grid;
    gap: 10px;
}

.admin-detail-row {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 16px;
    padding: 10px 0;
    border-bottom: 1px solid #eef2f7;
}

.admin-detail-row span {
    color: #6b7280;
}

.admin-detail-row strong {
    color: #111827;
    font-weight: 600;
    word-break: break-all;
}

@media (max-width: 1280px) {
    .admin-filter-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .admin-filter-actions {
        height: auto;
    }
}

@media (max-width: 768px) {
    .admin-filter-grid {
        grid-template-columns: 1fr;
    }

    .admin-filter-actions {
        height: auto;
    }

    .admin-filter-reset {
        width: 100%;
    }
}
</style>
