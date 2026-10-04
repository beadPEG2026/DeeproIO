<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/Languages/Form.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";

const defaultForm = {
    name: null,
    slug: null,
    status: false,
    is_default: false,
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
        language: Object,
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
            this.form = this.language
        }
    },
    computed: {
        subTitle: function () {
            return this.isEdit ? this.language.name : legacyText("Create");
        },
        actionButtonTitle: function () {
            return this.isEdit ? 'Update Language' : 'Create Language';
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
                this.$inertia.put(this.route('admin.languages.update', this.language.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.languages.store'), this.form, afterRequest);
            }
        },
        destroy() {

            if(this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this language?')) {
                let form = {
                    language: this.language.id,
                    id: this.language.id,
                }

                this.$inertia.delete(this.route('admin.languages.destroy', form), {
                    onSuccess: () => { this.$toast.open('Language was deleted'); },
                    onError: () => {
                        this.$toast.open({
                            message: this.errors.id,
                            type: 'error',
                        });
                    }
                })
            }
        },
    },
});
</script>
