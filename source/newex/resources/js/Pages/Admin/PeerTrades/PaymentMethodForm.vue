<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/PeerTrades/PaymentMethodForm.template'
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
    status: false,
    currencies: [],
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
        paymentMethod: Object,
        currencies: Array,
        currenciesIds: Array,
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
            this.form = this.paymentMethod;
            this.form.currencies = this.currenciesIds;
        }
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? this.paymentMethod.title : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Payment Method' : 'Create Payment Method';
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

            if(this.isEdit) {
                this.$inertia.put(this.route('admin.peerPaymentMethods.update', this.paymentMethod.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.peerPaymentMethods.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this payment method?')) {
                this.$inertia.delete(this.route('admin.peerPaymentMethods.destroy', this.paymentMethod.id), {
                    onSuccess: () => { this.$toast.open('Payment method was deleted'); },
                    onError: () => {
                        this.$toast.error(legacyText("There are some form errors"));
                    }
                })
            }
        },
        setColorCode() {

        }
    },
    watch: {

    }
});
</script>
