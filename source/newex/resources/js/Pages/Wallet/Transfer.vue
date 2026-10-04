<script>
import {requestIntent, completeIntent} from '@/Functions/RequestIntent.mjs';
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/Wallet/Transfer.template'
import AppLayout from '@/Layouts/AppLayout'
import TextUserInputBadge from "@/Jetstream/TextUserInputBadge"
import { math_percentage } from '@/Functions/Math'
import WalletOverview from '@/Components/WalletOverview.vue'

export default Template({
    components: {
        AppLayout,
        TextUserInputBadge,
        WalletOverview
    },

    props: {
        currencies: Object,
        umiStockTransfers: {type:Array, default:()=>[]},

        transferCommissionPercent: {
            type: [Number, String],
            default: 0,
        },

        userVip: {
            type: [Number, String],
            default: null,
        },
    },

    data() {
        return {
            processing: false,
            processing_balance: false,
            showCommissionModal: false,
            form: {
                amount: null,
                currency_id: null,
                direction: 'to_trade',
                from_account: 'funding',
                to_account: 'trade',

                account_type: 'real',
                use_virtual_wallet: false,
                virtual_balance_source: null,
            },
            error: null,
            balance: '0.00',
        }
    },

    computed: {
        rawWallets() {
            return this.$store && this.$store.getters
                ? (this.$store.getters.getWallets || [])
                : [];
        },

        currencyOptions() {
            return _.map(this.currencies, (c) => c);
        },

        accountOptions() {
            return [
                { value: 'funding', label: this.$t('Funding Account') },
                { value: 'trade', label: this.$t('Trade Account') },
            ];
        },

        toAccountOptions() {
            return this.accountOptions.filter(item => item.value !== this.form.from_account);
        },

        currentCurrency() {
            if (!this.form.currency_id || !this.currencies) {
                return null;
            }

            if (this.currencies[this.form.currency_id]) {
                return this.currencies[this.form.currency_id];
            }

            const currencyId = String(this.form.currency_id);

            return _.find(this.currencies, (item) => {
                return String(item.id || '') === currencyId;
            }) || null;
        },

        currentCurrencySymbol() {
            const currency = this.currentCurrency;

            if (!currency) {
                return '';
            }

            return String(
                currency.symbol ||
                currency.name ||
                currency.currency ||
                ''
            ).toUpperCase();
        },

        currentCurrencyLabel() {
            const currency = this.currentCurrency;

            if (!currency) {
                return '';
            }

            return currency.name || currency.symbol || '';
        },

        transferCurrencyName() {
            return this.currentCurrencyLabel;
        },

        transferAmount() {
            const amount = parseFloat(this.form.amount || 0);
            return Number.isFinite(amount) && amount > 0 ? amount : 0;
        },

        transferAmountFormatted() {
            return this.formatTransferAmount(this.transferAmount);
        },

        transferEstimatedReceivedAmount() {
            const received = this.transferAmount - this.transferFeeActualAmount;
            return received > 0 ? received : 0;
        },

        transferEstimatedReceivedAmountFormatted() {
            return this.formatTransferAmount(this.transferEstimatedReceivedAmount);
        },

        balanceType() {
            if (this.form.from_account === 'trade') {
                return legacyText("trade");
            }

            return 'account';
        },

        currentWallet() {
            if (!this.form.currency_id) {
                return null;
            }

            const currencyId = String(this.form.currency_id);
            const currencySymbol = this.currentCurrencySymbol;

            return (this.rawWallets || []).find(wallet => {
                const walletCurrencyId = wallet.currency_id !== undefined && wallet.currency_id !== null
                    ? String(wallet.currency_id)
                    : '';

                const walletId = wallet.id !== undefined && wallet.id !== null
                    ? String(wallet.id)
                    : '';

                const walletSymbol = String(
                    wallet.symbol ||
                    wallet.currency_symbol ||
                    (wallet.currency && wallet.currency.symbol) ||
                    ''
                ).toUpperCase();

                const walletCurrencyName = String(
                    wallet.currency ||
                    wallet.currency_name ||
                    (wallet.currency && wallet.currency.name) ||
                    wallet.name ||
                    ''
                ).toUpperCase();

                return (
                    walletCurrencyId === currencyId ||
                    walletId === currencyId ||
                    (currencySymbol && walletSymbol === currencySymbol) ||
                    (currencySymbol && walletCurrencyName === currencySymbol)
                );
            }) || null;
        },

        virtualSourceField() {
            if (!this.currentWallet) {
                return null;
            }

            if (this.form.from_account === 'funding') {
                const virtualWallet = this.normalizeDecimalString(this.currentWallet.balance_in_virtual_wallet);

                if (this.compareDecimalStrings(virtualWallet, '0') > 0) {
                    return 'balance_in_virtual_wallet';
                }
            }

            if (this.form.from_account === 'trade') {
                const virtualTrade = this.normalizeDecimalString(this.currentWallet.balance_in_virtual_trade);

                if (this.compareDecimalStrings(virtualTrade, '0') > 0) {
                    return 'balance_in_virtual_trade';
                }
            }

            return null;
        },

        realSourceField() {
            if (this.form.from_account === 'trade') {
                return 'balance_in_trade';
            }

            return 'balance_in_wallet';
        },

        realAvailableBalance() {
            if (!this.currentWallet) {
                return '0';
            }

            return this.normalizeDecimalString(this.currentWallet[this.realSourceField]);
        },

        transferAvailableBalance() {
            const realBalance = this.realAvailableBalance;
            const virtualBalance = this.virtualAvailableBalance;

            if (this.compareDecimalStrings(realBalance, '0') > 0) {
                return realBalance;
            }

            return this.compareDecimalStrings(virtualBalance, '0') > 0 ? virtualBalance : '0';
        },

        shouldUseVirtualTransfer() {
            return this.compareDecimalStrings(this.realAvailableBalance, '0') <= 0 && !!this.virtualSourceField;
        },

        virtualAvailableBalance() {
            if (!this.currentWallet || !this.virtualSourceField) {
                return '0';
            }

            return this.normalizeDecimalString(this.currentWallet[this.virtualSourceField]);
        },

        commissionPercent() {
            if (this.form.direction !== 'to_funding') {
                return 0;
            }

            const p = parseFloat(this.transferCommissionPercent || 0);
            return isNaN(p) ? 0 : p;
        },

        commissionAmount() {
            const amt = parseFloat(this.form.amount || 0);

            if (isNaN(amt) || amt <= 0) {
                return 0;
            }

            return math_percentage(amt, this.commissionPercent);
        },

        commissionAmountFormatted() {
            return this.formatTransferAmount(this.commissionAmount);
        },

        currentUser() {
            if (this.$page && this.$page.props) {
                if (this.$page.props.user) {
                    return this.$page.props.user;
                }

                if (this.$page.props.auth && this.$page.props.auth.user) {
                    return this.$page.props.auth.user;
                }
            }

            return null;
        },

        transferVipLevel() {
            if (this.userVip !== null && this.userVip !== undefined && this.userVip !== '') {
                const vip = parseInt(this.userVip);
                return isNaN(vip) ? 0 : vip;
            }

            const user = this.currentUser;

            if (!user) {
                return 0;
            }

            const possibleVipValues = [
                user.vip,
                user.vip_level,
                user.level,
                user.data && user.data.vip,
                user.data && user.data.vip_level,
                user.profile && user.profile.vip,
                user.profile && user.profile.vip_level,
            ];

            for (let i = 0; i < possibleVipValues.length; i++) {
                const value = possibleVipValues[i];

                if (value !== null && value !== undefined && value !== '') {
                    const vip = parseInt(value);
                    return isNaN(vip) ? 0 : vip;
                }
            }

            return 0;
        },

        transferFeeDiscountRate() {
            const discountMap = {
                0: 1,
                1: 0.9,
                2: 0.8,
                3: 0.65,
                4: 0.55,
                5: 0.45,
                6: 0.35,
                7: 0.25,
                8: 0.20,
            };

            return discountMap[this.transferVipLevel] !== undefined
                ? discountMap[this.transferVipLevel]
                : 1;
        },

        transferFeeDiscountRateFormatted() {
            const rate = Number(this.transferFeeDiscountRate || 1);

            if (!Number.isFinite(rate)) {
                return '1';
            }

            return rate.toFixed(2).replace(/\.00$/, '');
        },

        transferFeeRefundRate() {
            const rate = (1 - this.transferFeeDiscountRate) * 100;

            return rate > 0 ? rate : 0;
        },

        transferFeeRefundRateFormatted() {
            const rate = Number(this.transferFeeRefundRate || 0);

            if (!Number.isFinite(rate)) {
                return '0%';
            }

            return rate.toFixed(2).replace(/\.00$/, '') + '%';
        },

        transferFeeRefundAmount() {
            const fee = parseFloat(this.commissionAmount || 0);

            if (isNaN(fee) || fee <= 0 || this.transferFeeRefundRate <= 0) {
                return 0;
            }

            return fee * (this.transferFeeRefundRate / 100);
        },

        transferFeeRefundAmountFormatted() {
            return this.formatTransferAmount(this.transferFeeRefundAmount);
        },

        transferFeeActualAmount() {
            const fee = parseFloat(this.commissionAmount || 0);
            const refund = parseFloat(this.transferFeeRefundAmount || 0);

            if (isNaN(fee) || fee <= 0) {
                return 0;
            }

            const actual = fee - refund;

            return actual > 0 ? actual : 0;
        },

        transferFeeActualAmountFormatted() {
            return this.formatTransferAmount(this.transferFeeActualAmount);
        },
    },

    watch: {
        rawWallets: {
            handler() {
                this.loadBalance();
            },
            deep: true,
        },

        'form.currency_id'() {
            this.loadBalance();
        },

        'form.from_account'() {
            this.loadBalance();
        },
    },

    mounted() {
        const walletsPromise = _.isEmpty(this.rawWallets)
            ? this.$store.dispatch('fetchWallets', this.route('wallets.index'))
            : Promise.resolve();

        this.normalizeTransferAccounts();
        this.syncDirectionFromAccounts();

        walletsPromise.finally(() => {
            this.loadBalance();
        });
    },

    methods: {
        toNumber(value) {
            if (value === null || value === undefined || value === '') {
                return 0;
            }

            const number = Number(String(value).replace(/,/g, ''));

            return Number.isFinite(number) ? number : 0;
        },

        normalizeDecimalString(value) {
            if (value === null || value === undefined || value === '') {
                return '0';
            }

            let text = String(value).replace(/,/g, '').trim();

            if (!/^[+-]?\d*(\.\d*)?$/.test(text) || text === '' || text === '.' || text === '-' || text === '+') {
                return '0';
            }

            const negative = text.charAt(0) === '-';
            text = text.replace(/^[+-]/, '');

            let [integer, decimal = ''] = text.split('.');
            integer = (integer || '0').replace(/^0+(?=\d)/, '') || '0';
            decimal = decimal.replace(/\D/g, '').replace(/0+$/g, '');

            let normalized = decimal ? `${integer}.${decimal}` : integer;

            if (normalized === '0') {
                return '0';
            }

            return negative ? `-${normalized}` : normalized;
        },

        compareDecimalStrings(left, right) {
            left = this.normalizeDecimalString(left);
            right = this.normalizeDecimalString(right);

            const leftNegative = left.charAt(0) === '-';
            const rightNegative = right.charAt(0) === '-';

            if (leftNegative && !rightNegative) {
                return -1;
            }

            if (!leftNegative && rightNegative) {
                return 1;
            }

            const sign = leftNegative && rightNegative ? -1 : 1;
            left = left.replace(/^-/, '');
            right = right.replace(/^-/, '');

            const [leftInteger, leftDecimal = ''] = left.split('.');
            const [rightInteger, rightDecimal = ''] = right.split('.');

            if (leftInteger.length !== rightInteger.length) {
                return leftInteger.length > rightInteger.length ? sign : -sign;
            }

            if (leftInteger !== rightInteger) {
                return leftInteger > rightInteger ? sign : -sign;
            }

            const decimalLength = Math.max(leftDecimal.length, rightDecimal.length);
            const leftPadded = leftDecimal.padEnd(decimalLength, '0');
            const rightPadded = rightDecimal.padEnd(decimalLength, '0');

            if (leftPadded === rightPadded) {
                return 0;
            }

            return leftPadded > rightPadded ? sign : -sign;
        },

        formatTransferAmount(value, decimals = 8) {
            let formatted = this.normalizeDecimalString(value);

            if (this.compareDecimalStrings(formatted, '0') <= 0) {
                return '0';
            }

            if (formatted.indexOf('.') !== -1) {
                const parts = formatted.split('.');
                const integer = parts[0];
                const decimal = (parts[1] || '').slice(0, decimals);

                formatted = decimal ? `${integer}.${decimal}` : integer;
            }

            if (formatted.indexOf('.') !== -1) {
                formatted = formatted.replace(/(\.\d*?[1-9])0+$/g, '$1');
                formatted = formatted.replace(/\.0+$/g, '');
                formatted = formatted.replace(/\.$/g, '');
            }

            return formatted === '' ? '0' : formatted;
        },

        validTransferAccounts() {
            return ['funding', 'trade'];
        },

        normalizeTransferAccounts() {
            const validAccounts = this.validTransferAccounts();

            if (!validAccounts.includes(this.form.from_account)) {
                this.form.from_account = 'funding';
            }

            if (!validAccounts.includes(this.form.to_account)) {
                this.form.to_account = this.form.from_account === 'funding' ? legacyText("trade") : 'funding';
            }

            if (this.form.from_account === this.form.to_account) {
                this.form.to_account = this.form.from_account === 'funding' ? legacyText("trade") : 'funding';
            }
        },

        syncVirtualTransferFields() {
            this.form.use_virtual_wallet = this.shouldUseVirtualTransfer;
            this.form.account_type = this.shouldUseVirtualTransfer ? 'virtual' : 'real';
            this.form.virtual_balance_source = this.shouldUseVirtualTransfer ? this.virtualSourceField : null;
        },

        onCurrencyChange() {
            this.syncVirtualTransferFields();
            this.loadBalance();
        },

        onFromAccountChange() {
            this.normalizeTransferAccounts();
            this.syncDirectionFromAccounts();
            this.syncVirtualTransferFields();
            this.loadBalance();
        },

        onToAccountChange() {
            this.normalizeTransferAccounts();
            this.syncDirectionFromAccounts();
            this.syncVirtualTransferFields();
        },

        switchAccounts() {
            const from = this.form.from_account;
            const to = this.form.to_account;

            this.form.from_account = to;
            this.form.to_account = from;

            this.normalizeTransferAccounts();
            this.syncDirectionFromAccounts();
            this.syncVirtualTransferFields();
            this.loadBalance();
        },

        syncDirectionFromAccounts() {
            const from = this.form.from_account;
            const to = this.form.to_account;

            if (!from || !to || from === to) {
                this.form.direction = null;
                return;
            }

            if (from === 'funding' && to === 'trade') {
                this.form.direction = 'to_trade';
                return;
            }

            if (from === 'trade' && to === 'funding') {
                this.form.direction = 'to_funding';
                return;
            }

            this.form.direction = null;
        },

        fromAccountLabel() {
            const item = this.accountOptions.find(i => i.value === this.form.from_account);
            return item ? item.label : '-';
        },

        toAccountLabel() {
            const item = this.accountOptions.find(i => i.value === this.form.to_account);
            return item ? item.label : '-';
        },

        loadBalance() {
            if (!this.form.currency_id || !this.form.from_account) {
                this.balance = '0.00';
                return;
            }

            this.syncVirtualTransferFields();

            if (this.processing_balance) {
                return;
            }

            this.processing_balance = true;

            axios.get(this.route('wallets.api.getBalance'), {
                params: {
                    currency: this.form.currency_id,
                    type: this.balanceType,
                }
            }).then((res) => {
                this.processing_balance = false;

                if (res.data && res.data.success) {
                    const realBalance = this.normalizeDecimalString(res.data.balance);
                    const virtualBalance = this.normalizeDecimalString(res.data.virtual_balance);

                    this.balance = this.formatTransferAmount(
                        this.compareDecimalStrings(realBalance, '0') > 0 ? realBalance : virtualBalance,
                        4
                    );
                }
            }).catch(() => {
                this.processing_balance = false;
            });
        },

        injectBalance() {
            this.form.amount = this.balance;
        },

        setMaxAmount() {
            this.form.amount = this.balance === 0 ? 0 : this.balance;
        },

        handleInput($event) {
            const keyCode = $event.keyCode ? $event.keyCode : $event.which;
            const currentValue = this.form.amount == null ? '' : this.form.amount.toString();

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || currentValue.indexOf('.') !== -1)) {
                $event.preventDefault();
            }

            if (
                currentValue.indexOf('.') > -1 &&
                currentValue.split('.')[1].length >= 18
            ) {
                $event.preventDefault();
            }
        },

        clearInput() {
            if (this.form.amount === null || this.form.amount === undefined || this.form.amount === '') {
                return;
            }

            const field = this.form.amount.toString();

            if (field.charAt(0) === '.') {
                this.form.amount = 0;
                return;
            }

            if (/^0+\.\d+/.test(field)) {
                this.form.amount = field.replace(/^0+/, '0');
            }

            if (/^0+\d+/.test(field)) {
                this.form.amount = field.replace(/^0+/, '');
            }
        },

        submitTransfer() {
            if (!this.$page.props.user && !(this.$page.props.auth && this.$page.props.auth.user)) {
                return;
            }

            this.normalizeTransferAccounts();
            this.syncVirtualTransferFields();

            if (!this.form.from_account) {
                this.$toast.error(this.$t('Please select From Account'));
                return;
            }

            if (!this.form.to_account) {
                this.$toast.error(this.$t('Please select To Account'));
                return;
            }

            if (!this.validTransferAccounts().includes(this.form.from_account) || !this.validTransferAccounts().includes(this.form.to_account)) {
                this.$toast.error(this.$t('Invalid transfer account'));
                return;
            }

            if (this.form.from_account === this.form.to_account) {
                this.$toast.error(this.$t('From and To accounts cannot be the same'));
                return;
            }

            if (!this.form.currency_id || !this.form.amount || this.processing) {
                return;
            }

            this.syncDirectionFromAccounts();

            if (!this.form.direction) {
                this.$toast.error(this.$t('Invalid transfer direction'));
                return;
            }

            const availableForSubmit = this.compareDecimalStrings(this.transferAvailableBalance, '0') > 0
                ? this.transferAvailableBalance
                : this.normalizeDecimalString(this.balance);

            if (this.compareDecimalStrings(this.form.amount, availableForSubmit) > 0) {
                this.$toast.error(this.$t('Insufficient balance'));
                return;
            }

            const shouldShowCommissionModal = this.form.direction === 'to_funding' && this.commissionPercent > 0;

            if (shouldShowCommissionModal) {
                this.showCommissionModal = true;
                return;
            }

            this._performTransfer();
        },

        _performTransfer() {
            if(this.processing)return;
            this.processing = true;
            this.syncVirtualTransferFields();

            const intentScope='wallet-transfer:'+this.$page.props.user.id;
            const intent=requestIntent(intentScope,this.form,this.form);
            axios.post(this.route('wallets.api.transfer'), intent.payload, {timeout:20000,headers:{'Idempotency-Key':intent.key}})
                .then(() => {
                    completeIntent(intentScope,intent.key);
                    this.processing = false;
                    this.$toast.success(this.$t('Transfer completed'));
                    this.form.amount = null;

                    this.$store.dispatch('fetchWallets', this.route('wallets.index'))
                        .finally(() => {
                            this.loadBalance();
                        });
                })
                .catch((error) => {
                    this.processing = false;
                    if(!error.response)this.$toast.error(this.$i18n.locale.startsWith('zh')?'划转结果尚未确认，请检查资产记录；重试相同内容将沿用本次请求。':'Transfer outcome is not confirmed. Check asset history; retrying the same details will reuse this request.');

                    if (error.response && error.response.data) {
                        if (error.response.data.message) {
                            this.$toast.error(error.response.data.message);
                        }

                        if (error.response.data.errors) {
                            _.each(error.response.data.errors, (field) => {
                                this.$toast.error(field[0]);
                            });
                        }
                    }
                });
        },

        confirmCommissionAgree() {
            this.showCommissionModal = false;
            this._performTransfer();
        },

        cancelCommission() {
            this.showCommissionModal = false;
        }
    }
})
</script>
