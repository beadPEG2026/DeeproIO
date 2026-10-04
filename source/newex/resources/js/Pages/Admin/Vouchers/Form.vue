<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Vouchers/Form.template'
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
    'user_id': null,
    'currency_id': null,
    'code': null,
    'amount': null,
    'is_redeemed': false,
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
        voucher: Object,
        currencies: Array,
        voucherUsers: Array,
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
            this.form = this.voucher;
        }
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? this.voucher.code : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Voucher' : 'Create Voucher';
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
                this.$inertia.put(this.route('admin.vouchers.update', this.voucher.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.vouchers.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this voucher?')) {
                this.$inertia.delete(this.route('admin.vouchers.destroy', this.voucher.id), {
                    onSuccess: () => { this.$toast.open('Voucher was deleted'); }
                })
            }
        },
    },
});
</script>
