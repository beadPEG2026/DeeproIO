<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/BankAccounts/Form.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";
import JetInputError from '@/Jetstream/InputError';

const defaultForm = {
    'reference_number': null,
    'name': null,
    'iban': null,
    'swift': null,
    'ifsc': null,
    'address': null,
    'account_holder_name': null,
    'account_holder_address': null,
    'note': null,
    'status': true,
    'country_id': null,
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
        EmptyColumn,
        JetInputError
    },
    props: {
        isEdit: {
            type: Boolean,
            default: false,
        },
        errors: Object,
        bankAccount: Object,
        countries: Array,
    },
    remember: 'form',
    data() {
        return {
            sending: false,
            form: Object.assign({}, defaultForm),
        }
    },
    mounted() {
        if(this.isEdit) {
            this.form = this.bankAccount;
        }
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? this.bankAccount.name : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Bank Account' : 'Create Bank Account';
        },
    },
    methods: {
        submit() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.$toast.open('Database updated');
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            if(this.isEdit) {
                this.$inertia.put(this.route('admin.bank_accounts.update', this.bankAccount.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.bank_accounts.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this bank account?')) {
                this.$inertia.delete(this.route('admin.bank_accounts.destroy', this.bankAccount.id), {
                    onSuccess: () => { this.$toast.open('Bank Account was deleted'); }
                })
            }
        },
    },
});
</script>
