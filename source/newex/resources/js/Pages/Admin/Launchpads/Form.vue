<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Launchpads/Form.template'
import ProductConfigPreview from '@/Components/Admin/ProductConfigPreview.vue';
import PublicationFields from '@/Components/Admin/PublicationFields.vue';
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
    publication_status: 'draft', publication_reference: '',
    'name': null,
    'description': null,
    'currency_id': null,
    'network_id': null,
    'rate': null,
    'min_buy': null,
    'max_buy': null,
    'soft_cap': null,
    'hard_cap': null,
    'start_time': null,
    'end_time': null,
    'status': true,
};

export default Template({
    components: {
        PublicationFields,
        ProductConfigPreview,
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
        launchpad: Object,
        networks: Array,
        currencies: Array,
    },
    remember: 'form',
    data() {
        return {
            sending: false,
            original: {},
            form: Object.assign({}, defaultForm),
        }
    },
    mounted() {
        if(this.isEdit) {
            this.original = JSON.parse(JSON.stringify(this.launchpad));
            this.form = {...this.launchpad};
        }
    },
    computed: {
        previewFields(){ const fields=[{"key": "name", "label": "Name", "kind": ""}, {"key": "currency_id", "label": "Currency", "kind": "currency"}, {"key": "network_id", "label": "Network", "kind": "network"}, {"key": "status", "label": "Status", "kind": "enabled"}, {"key": "rate", "label": "Rate", "kind": ""}, {"key": "min_buy", "label": "Minimum purchase", "kind": ""}, {"key": "max_buy", "label": "Maximum purchase", "kind": ""}, {"key": "soft_cap", "label": "Soft cap", "kind": ""}, {"key": "hard_cap", "label": "Hard cap", "kind": ""}, {"key": "start_time", "label": "Start time (UTC)", "kind": ""}, {"key": "end_time", "label": "End time (UTC)", "kind": ""}, {"key": "description", "label": "Description", "kind": ""}, {"key": "dy_am", "label": "合约交易量 >  多少USDT", "kind": ""}, {"key": "kt_sl", "label": "空投多少代币(1000就是1000个代币)", "kind": ""}];
            return fields;
        },
        subTitle: function () {
            return this.isEdit ? this.launchpad.name : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Launchpad' : 'Create Launchpad';
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
                this.$inertia.put(this.route('admin.launchpads.update', this.launchpad.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.launchpads.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this launchpad?')) {
                this.$inertia.delete(this.route('admin.launchpads.destroy', this.launchpad.id), {
                    onSuccess: () => { this.$toast.open('Launchpad was deleted'); }
                })
            }
        },
    },
});
</script>
