<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Lendings/CollateralForm.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";

const defaultForm = {

    currency_id: null,

    flex_initial_ltv: null,
    flex_margin_call: null,
    flex_liquidation_ltv: null,

    weekly_initial_ltv: null,
    weekly_margin_call: null,
    weekly_liquidation_ltv: null,

    monthly_initial_ltv: null,
    monthly_margin_call: null,
    monthly_liquidation_ltv: null,
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
        lending: Object,
        currency: Object,
        currencies: Array,
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
            this.form = this.currency;
        }
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? legacyText("Edit") : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Colleteral' : 'Create Colleteral';
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

            this.form.lending_id = this.lending.id;

            if(this.isEdit) {
                this.$inertia.put(this.route('admin.lendings.collateral.update', {lending: this.lending.id, currency: this.currency.id}), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.lendings.collateral.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this collateral?')) {
                this.$inertia.delete(this.route('admin.lendings.collateral.destroy', {currency: this.currency.id}), {
                    onSuccess: () => { this.$toast.open('Colleteral was deleted'); },
                    onError: () => {
                        this.$toast.error(legacyText("There are some form errors"));
                    }
                })
            }
        },
    },
});
</script>
