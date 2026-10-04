<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Stakings/Form.template'
import StakingPeriodsEditor from '@/Components/Admin/StakingPeriodsEditor.vue';
import ProductConfigPreview from '@/Components/Admin/ProductConfigPreview.vue';
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
    allowed_days: null,
    rewards_percentage: null,
    min_amount: null,
    max_amount: null,
    status: null,
    staking_type: null,
    rewards_percentage_t: null,
};

export default Template({
    components: {
        ProductConfigPreview, StakingPeriodsEditor,
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
        staking: Object,
        currencies: Array,
        configuration: Object,
    },
    remember: 'form',
    data() {
        return {
            sending: false,
            effectiveAt: "",
            original: {},
            form: Object.assign({}, defaultForm),
        }
    },
    mounted() {
        if(this.isEdit) {
            this.original = JSON.parse(JSON.stringify(this.staking));
            this.form = {...this.staking};
            if(String(this.$page.props.staking_type) === '1'){
                this.form.currency_id = 2;
            }
            this.form.reward_user_id=this.configuration?.controls?.reward_user_id??null;this.form.pool_limit=this.configuration?.controls?.pool_limit??null;
            this.form.reason="";this.form.revision=this.configuration?.revision;this.form.apr_limit=this.configuration?.controls?.apr_limit??null;
        }else{
            this.form.staking_type = this.$page.props.staking_type;
            if(String(this.$page.props.staking_type) === '1'){
                this.form.currency_id = 2;
            }
        }
    },
    computed: {
        previewFields(){ const fields=[{"key": "currency_id", "label": "Currency", "kind": "currency"}, {"key": "status", "label": "Status", "kind": "status"}, {"key": "min_amount", "label": "Minimum amount", "kind": ""}, {"key": "max_amount", "label": "Maximum amount", "kind": ""}, {"key": "allowed_days", "label": "Allowed days", "kind": ""}, {"key": "rewards_percentage", "label": "Reward percentage", "kind": ""}];
            if(String(this.$page.props.staking_type)==='1')fields.push({key:'currency_idd',label:'Display currency',kind:'currency'},{key:'rewards_percentage_a',label:'量化利润最高'},{key:'rewards_percentage_t',label:'利润提升（例如 5就为5%）'},{key:'Introduction',label:'量化介绍'});
            return fields;
        },
        subTitle: function () {
            return this.isEdit ? legacyText("Edit") : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Staking' : 'Create Staking';
        },
    },
    methods: {
        submit() {
            this.form.effective_at=this.effectiveAt?new Date(this.effectiveAt).toISOString():null;

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
                this.$inertia.put(this.route('admin.stakings.update', this.staking.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.stakings.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this staking?')) {
                this.$inertia.delete(this.route('admin.stakings.destroy', this.staking.id), {
                    onSuccess: () => { this.$toast.open('Staking was deleted'); },
                    onError: () => {
                        this.$toast.error(legacyText("There are some form errors"));
                    }
                })
            }
        },
    },
});
</script>
