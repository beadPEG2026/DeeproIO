<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import JetButton from '@/Jetstream/Button'
import JetFormSection from '@/Jetstream/FormSection'
import JetInput from '@/Jetstream/Input'
import JetInputError from '@/Jetstream/InputError'
import JetLabel from '@/Jetstream/Label'
import JetActionMessage from '@/Jetstream/ActionMessage'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import Template from '{Template}/Web/Pages/Profile/BankAccounts.template'
import SvgIcon from "@/Components/Svg/SvgIcon";
import Badge from "@/Jetstream/Badge";
import TextUserInput from "@/Jetstream/TextUserInput";

export default Template({
    components: {
        JetActionMessage,
        JetButton,
        JetFormSection,
        JetInput,
        JetInputError,
        JetLabel,
        JetSecondaryButton,
        SvgIcon,
        Badge,
        TextUserInput
    },

    props: {
        countries: {
            type: [Object, Array],
            default: () => []
        }
    },

    data() {
        return {
            accounts: null,
            countriesList: [],
            countriesLoading: false,
            sending: false,
            showBankAccountModal: false,
            bankAccountErrors: {},
            bankAccountForm: {
                first_name: '',
                last_name: '',
                country_id: null,
                iban: '',
                bic: '',
            }
        }
    },

    computed: {
        hasPendingBankAccount() {
            return Array.isArray(this.accounts) && this.accounts.some((account) => {
                return this.normalizedAccountStatus(account) === 'pending';
            });
        },

        hasApprovedBankAccount() {
            return Array.isArray(this.accounts) && this.accounts.some((account) => {
                return this.normalizedAccountStatus(account) === 'approved';
            });
        },

        hasRejectedBankAccount() {
            return Array.isArray(this.accounts) && this.accounts.some((account) => {
                return this.normalizedAccountStatus(account) === 'rejected';
            });
        },

        hasSubmittedBankAccount() {
            return this.hasPendingBankAccount || this.hasApprovedBankAccount;
        },

        canSubmitBankAccount() {
            return !this.hasPendingBankAccount && !this.hasApprovedBankAccount;
        },

        bankAccountButtonText() {
            if (this.hasApprovedBankAccount) {
                return legacyText("Bank account already approved");
            }

            if (this.hasPendingBankAccount) {
                return legacyText("Bank account under review");
            }

            if (this.hasRejectedBankAccount) {
                return legacyText("Resubmit Bank Account");
            }

            return legacyText("Add Bank Account");
        },

        bankAccountStatusNotice() {
            if (this.hasApprovedBankAccount) {
                return legacyText("Your bank account has been approved. You cannot submit another bank account.");
            }

            if (this.hasPendingBankAccount) {
                return legacyText("Your bank account is under review. Please wait for the review result.");
            }

            if (this.hasRejectedBankAccount) {
                return legacyText("Your previous bank account was rejected. You can submit a new bank account.");
            }

            return '';
        },

        countryOptions() {
            let list = [];

            if (this.countries && Array.isArray(this.countries.data)) {
                list = this.countries.data;
            } else if (Array.isArray(this.countries) && this.countries.length > 0) {
                list = this.countries;
            } else if (this.countriesList && this.countriesList.length > 0) {
                list = this.countriesList;
            } else if (
                this.$page &&
                this.$page.props &&
                this.$page.props.countries &&
                Array.isArray(this.$page.props.countries.data)
            ) {
                list = this.$page.props.countries.data;
            } else if (
                this.$page &&
                this.$page.props &&
                this.$page.props.countries &&
                Array.isArray(this.$page.props.countries)
            ) {
                list = this.$page.props.countries;
            }

            return list.map((country) => {
                return {
                    id: country.id,
                    name: country.name || country.text || country.title || country.label || ''
                };
            }).filter((country) => {
                return country.id && country.name;
            });
        }
    },

    mounted() {
        this.getAccounts();
        this.getCountries();
    },

    methods: {
        getCountries() {
            if (this.countryOptions.length > 0 || this.countriesLoading) {
                return;
            }

            this.countriesLoading = true;

            axios.get('/bank-account/countries')
                .then((response) => {
                    this.countriesList = response.data.countries || [];
                })
                .catch(() => {
                    this.countriesList = [];
                })
                .finally(() => {
                    this.countriesLoading = false;
                });
        },

        openBankAccountModal() {
            if (!this.canSubmitBankAccount) {
                let message = 'Bank account cannot be submitted at this time.';

                if (this.hasApprovedBankAccount) {
                    message = 'Your bank account has been approved. You cannot submit another bank account.';
                } else if (this.hasPendingBankAccount) {
                    message = 'Your bank account is under review. Please wait for the review result.';
                }

                if (this.$toast) {
                    this.$toast.error(this.$t(message));
                }

                return;
            }

            this.bankAccountErrors = {};
            this.getCountries();
            this.showBankAccountModal = true;
        },

        closeBankAccountModal() {
            if (this.sending) {
                return;
            }

            this.showBankAccountModal = false;
        },

        resetBankAccountForm() {
            this.bankAccountForm = {
                first_name: '',
                last_name: '',
                country_id: null,
                iban: '',
                bic: '',
            };

            this.bankAccountErrors = {};
        },

        submitBankAccount() {
            if (this.sending || !this.canSubmitBankAccount) {
                return;
            }

            this.sending = true;
            this.bankAccountErrors = {};

            axios.post(this.route('bank_account.link_account'), this.bankAccountForm)
                .then((response) => {
                    if (this.$toast) {
                        this.$toast.open(
                            response.data.message || this.$t('Bank account submitted successfully')
                        );
                    }

                    this.showBankAccountModal = false;
                    this.resetBankAccountForm();
                    this.getAccounts();
                })
                .catch((error) => {
                    let message = legacyText("Submit failed");

                    if (error.response && error.response.data) {
                        if (error.response.data.errors) {
                            this.bankAccountErrors = error.response.data.errors;
                        }

                        if (error.response.data.message) {
                            message = error.response.data.message;
                        }
                    }

                    if (this.$toast) {
                        this.$toast.error(this.$t(message));
                    }
                })
                .finally(() => {
                    this.sending = false;
                });
        },

        async getAccounts() {
            await axios.get(this.route('bank_account.accounts')).then((response) => {
                this.accounts = response.data.accounts || [];
            }).catch(() => {
                this.accounts = [];
            });
        },

        normalizedAccountStatus(account) {
            if (!account) {
                return 'pending';
            }

            if (account.accountStatus === 'approved' || account.accountStatus === 'active') {
                return 'approved';
            }

            if (account.accountStatus === 'rejected' || account.accountStatus === 'reject') {
                return 'rejected';
            }

            return 'pending';
        },

        accountStatusText(account) {
            const status = this.normalizedAccountStatus(account);

            if (status === 'approved') {
                return legacyText("Approved");
            }

            if (status === 'rejected') {
                return legacyText("Rejected");
            }

            return legacyText("Under Review");
        },

        accountStatusClass(account) {
            const status = this.normalizedAccountStatus(account);

            if (status === 'approved') {
                return 'bg-green-100 text-green-700';
            }

            if (status === 'rejected') {
                return 'bg-red-100 text-red-700';
            }

            return 'bg-orange-100 text-orange-700';
        }
    },
});
</script>
