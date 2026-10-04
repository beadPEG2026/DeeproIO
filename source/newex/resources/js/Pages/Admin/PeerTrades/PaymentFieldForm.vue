<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/PeerTrades/PaymentFieldForm.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";

const defaultForm = {
    title: null,
    required: false,
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
    },
    props: {
        isEdit: {
            type: Boolean,
            default: false,
        },
        errors: Object,
        paymentField: Object,
        paymentMethod: Object
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
            this.form = this.paymentField;
        }
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? this.paymentField.title : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Payment Field' : 'Create Payment Field';
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
                    this.$toast.open('Database Updated');
                },
                onError: () => {
                    this.$toast.error(legacyText("There are some form errors"));
                }
            };

            this.form.payment_method = this.paymentMethod.id

            if(this.isEdit) {
                this.$inertia.put(this.route('admin.peerPaymentFields.update', this.paymentField.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.peerPaymentFields.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this payment field?')) {
                this.$inertia.delete(this.route('admin.peerPaymentFields.destroy', this.paymentField.id), {
                    onSuccess: () => { this.$toast.open('Payment field was deleted'); },
                    onError: () => {
                        this.$toast.error(legacyText("There are some form errors"));
                    }
                })
            }
        },
    },
    watch: {

    }
});
</script>
