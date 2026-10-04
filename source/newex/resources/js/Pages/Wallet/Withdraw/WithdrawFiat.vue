<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Wallet/Withdraw/WithdrawFiat.template'
import SelectInput from "@/Jetstream/SelectInput";
import AppLayout from '@/Layouts/AppLayout'
import {mapGetters} from "vuex";
import {string_cut} from "@/Functions/String";
import JetButton from '@/Jetstream/Button'
import TextUserInput from "@/Jetstream/TextUserInput";
import TextUserInputBadge from "@/Jetstream/TextUserInputBadge";
import LoadingButton from "@/Jetstream/LoadingButton";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import SvgIcon from "@/Components/Svg/SvgIcon";
import {math_formatter, math_percentage} from "@/Functions/Math";

const defaultForm = {
    currency: null,
    amount: 0,
    name: '',
    iban: '',
    swift: '',
    ifsc: '',
    address: '',
    account_holder_name: '',
    account_holder_address: '',
    country_id: null,
};

export default Template({
    components: {
        AppLayout,
        SelectInput,
        JetButton,
        TextUserInput,
        TextUserInputBadge,
        LoadingButton,
        JetDialogModal,
        JetSecondaryButton,
        SvgIcon
    },
    props: {
        symbol: String,
        currency: Object,
        errors: Object,
        countries: Object,
        limit: Object,
        networks: Array,
    },
    data() {
        return {
            form: Object.assign({}, defaultForm),
            sending: false,
            showWithdrawalModal: false,
            closeWithdrawalModal: false,
            withdrawalModal: null,
        }
    },
    mounted() {

        if(_.isEmpty(this.wallets)) {
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        }

        if(_.isEmpty(this.withdrawals)) {
            this.$store.dispatch('fetchFiatWithdrawals', this.route('wallets.api.withdrawals.fiat'));
        }
    },
    computed: {
        ...mapGetters({
            wallets: 'getWallets',
            withdrawals: 'getFiatWithdrawals',
        }),
        wallet: function () {
            if(this.$store.getters.getUser) {
                return this.$store.getters.getWallet(this.currency.symbol);
            }
        },
        currentUser() {
            return this.$page.props.user || this.$store.getters.getUser || {};
        },
        isVirtualAccount() {
            const user = this.currentUser || {};

            return user.is_xn === true
                || user.is_xn === 1
                || user.is_xn === '1'
                || user.is_xm === true
                || user.is_xm === 1
                || user.is_xm === '1';
        },
        realAvailableBalance() {
            if (!this.wallet) {
                return 0;
            }

            return this.toNumber(this.wallet.balance_in_wallet);
        },
        virtualWalletBalance() {
            if (!this.wallet) {
                return 0;
            }

            const nested = this.getNestedVirtualBalances();
            const value = this.wallet.balance_in_virtual_wallet !== undefined
                ? this.wallet.balance_in_virtual_wallet
                : (nested.balance_in_virtual_wallet !== undefined ? nested.balance_in_virtual_wallet : nested.wallet);

            return this.toNumber(value);
        },
        virtualAvailableBalance() {
            if (!this.wallet) {
                return 0;
            }

            return this.virtualWalletBalance;
        },
        virtualAvailableBalanceDisplay() {
            return math_formatter(this.virtualAvailableBalance, 8);
        },
        availableBalance() {
            const balance = this.isVirtualAccount
                ? this.realAvailableBalance + this.virtualAvailableBalance
                : this.realAvailableBalance;

            return math_formatter(balance, 8);
        },
        withdrawFee: function () {
            let fee = this.currency.withdraw_fee;
            let feeFixed = this.currency.withdraw_fee_fixed;

            if(parseFloat(fee) > 0) {
                return {
                    type: 'floating',
                    fee: fee,
                    displayFee: math_formatter(fee, 2)
                };
            }

            const normalizedFixedFee = feeFixed === null || feeFixed === undefined || feeFixed === ''
                ? 0
                : feeFixed;

            return {
                type: 'fixed',
                fee: normalizedFixedFee,
                displayFee: math_formatter(normalizedFixedFee, 2)
            };
        },
        calculatedFee: function () {

            if(!this.form.amount) return 0;

            let withdrawFee = this.withdrawFee;

            if(withdrawFee.type == "fixed") {
                return math_formatter(this.form.amount - withdrawFee.fee, 2);
            }

            return math_formatter(this.form.amount - math_percentage(this.form.amount, withdrawFee.fee), 2);
        },
    },
    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = parseFloat(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },
        getNestedVirtualBalances() {
            if (!this.wallet) {
                return {};
            }

            if (this.wallet.virtual_balances) {
                return this.wallet.virtual_balances;
            }

            if (this.wallet.balances && this.wallet.balances.virtual) {
                return this.wallet.balances.virtual;
            }

            return {};
        },
        submit() {

            if(this.sending) return;

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.$toast.open('Withdraw was submitted');
                    this.$inertia.visit(this.route('wallets.withdraw.fiat.success'));
                },
                onError: () => {
                    this.sending = false;
                    this.$toast.error(legacyText("There are some form errors"));
                },
            };

            this.form.currency_id = this.currency.id;

            this.$inertia.post(this.route('wallets.withdraw.store.fiat'), this.form, afterRequest);
        },
        openModal(withdrawal) {
            this.withdrawalModal = withdrawal;
            this.showWithdrawalModal = true;
        },
        amountWithFee(withdrawal) {
            return numeral(withdrawal.amount).subtract(numeral(withdrawal.fee).value()).value();
        },
        closeModal() {
            this.showWithdrawalModal = false;
        },
        handleInput ($event) {

            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || this.form.amount.toString().indexOf('.') != -1)) {
                $event.preventDefault();
            }

            let precision = 3;

            // restrict to 2 decimal places
            if(this.form.amount != null && this.form.amount.toString().indexOf(".")>-1 && (this.form.amount.toString().split('.')[1].length >= precision)){
                $event.preventDefault();
            }
        },
        clearInput ($event) {
            let field = this.form.amount.toString();

            if(field.charAt(0) == '.') {
                this.form.amount = 0;
                return;
            }

            if (/^0+\.\d+/.test( field )) {
                this.form.amount = field.replace(/^0+/, '0');
            }

            if (/^0+\d+/.test( field )) {
                this.form.amount = field.replace(/^0+/, '');
            }
        },
        setMaxAmount() {
            this.form.amount = this.availableBalance;
        }
    }
});
</script>
