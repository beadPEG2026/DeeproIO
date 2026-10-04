<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/OptionTemplate/Form.template'
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
    'market_id': null,
    'period': null,
    'type': null,
    'amount': null,
    'action': null,
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
        option: Object,
        markets: Array,
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
            this.form = this.option;
        }
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? legacyText("Edit") : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Template' : 'Create Template';
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
                this.$inertia.put(this.route('admin.options.templates.update', this.option.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.options.templates.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this option?')) {
                this.$inertia.delete(this.route('admin.options.templates.destroy', this.option.id), {
                    onSuccess: () => { this.$toast.open('Option was deleted'); }
                })
            }
        },
    },
});
</script>
