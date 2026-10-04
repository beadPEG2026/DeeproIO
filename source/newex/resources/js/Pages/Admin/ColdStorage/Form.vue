<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Admin/Pages/Admin/ColdStorage/Form.template'
import AppLayout from '@/Layouts/AdminLayout'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import TextInput from '@/Jetstream/TextInput'
import TextareaInput from "@/Jetstream/TextareaInput";
import LoadingButton from "@/Jetstream/LoadingButton";
import TrashedMessage from "@/Jetstream/TrashedMessage";
import SelectInput from "@/Jetstream/SelectInput";
import EmptyColumn from "@/Jetstream/EmptyColumn";
import JetInputError from '@/Jetstream/InputError';
import JetDialogModal from '@/Jetstream/DialogModal'
import JetDangerButton from '@/Jetstream/DangerButton'

const defaultForm = {
    cold_min_balance_amount: null,
    cold_transfer_amount: null,
    network_id: null,
    currency_id: null,
    status: false,
    hot_reserve: '0',
    daily_limit: null,
    address: null,
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
        JetInputError,
        JetDialogModal,
        JetDangerButton
    },
    props: {
        isEdit: {
            type: Boolean,
            default: false,
        },
        errors: Object,
        twoFactorConfigured: Boolean,
        coldStorage: Object,
        currencies: Array,
        networks: Array,
        needGoogleVerify: {
            type: Boolean,
            default: false,
        },
    },
    remember: 'form',
    data() {
        return {
            sending: false,
            form: Object.assign({}, defaultForm),
            showGoogleVerifyModal: false,
            googleCode: '',
            verifyingGoogleCode: false,
        }
    },
    mounted() {
        if (this.isEdit) {
            this.form = this.coldStorage;
        }

        if (this.needGoogleVerify && this.twoFactorConfigured) {
            this.showGoogleVerifyModal = true;
        }
    },
    computed: {
        selectableNetworks() {
            return (this.currencies.find(c => Number(c.id) === Number(this.form.currency_id)) || {}).network_options || [];
        },
        subTitle() {
            return this.isEdit ? legacyText("Edit") : legacyText("Create");
        },
        actionButtonTitle() {
            return this.isEdit ? 'Update Cold Storage' : 'Create Cold Storage';
        },
    },
    methods: {
        changeAsset() {
            if (!this.selectableNetworks.some(n => Number(n.id) === Number(this.form.network_id))) this.form.network_id = null;
        },
        submit() {
            if (this.showGoogleVerifyModal) {
                return this.$toast.error(legacyText("请先完成 Google 二次验证"));
            }

            if (this.$page.props.mode == "readonly") {
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

            if (this.isEdit) {
                this.$inertia.put(this.route('admin.cold_storage.update', this.coldStorage.id), this.form, afterRequest);
            } else {
                this.$inertia.post(this.route('admin.cold_storage.store'), this.form, afterRequest);
            }
        },

        destroy() {
            if (this.showGoogleVerifyModal) {
                return this.$toast.error(legacyText("请先完成 Google 二次验证"));
            }

            if (this.$page.props.mode == "readonly") {
                return this.$toast.warning('In Demo Version we enabled READ ONLY mode to protect our demo content.')
            }

            if (confirm('Are you sure you want to delete this cold storage?')) {
                this.$inertia.delete(this.route('admin.cold_storage.destroy', this.coldStorage.id), {
                    onSuccess: () => { this.$toast.open('Cold Storage was deleted'); }
                })
            }
        },

        verifyGoogleCode() {
            if (this.verifyingGoogleCode) return;

            if (!this.googleCode || this.googleCode.length !== 6) {
                this.$toast.error(legacyText("请输入 6 位验证码"));
                return;
            }

            this.verifyingGoogleCode = true;

            axios.post('/exchange-control-panel/cold-storage/google-verify', {
                code: this.googleCode
            }).then((res) => {
                this.verifyingGoogleCode = false;

                if (res.data.success) {
                    this.$toast.success(legacyText("验证成功"));
                    this.showGoogleVerifyModal = false;
                    this.googleCode = '';
                } else {
                    this.$toast.error(res.data.message || legacyText("验证失败"));
                }
            }).catch((error) => {
                this.verifyingGoogleCode = false;

                if (error.response && error.response.data && error.response.data.message) {
                    this.$toast.error(error.response.data.message);
                } else if (error.response && error.response.data && error.response.data.errors && error.response.data.errors.code) {
                    this.$toast.error(error.response.data.errors.code[0]);
                } else {
                    this.$toast.error(legacyText("验证失败"));
                }
            });
        }
    },
});
</script>
