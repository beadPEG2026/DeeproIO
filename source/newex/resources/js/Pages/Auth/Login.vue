<script>
import {authError} from '@/Functions/AuthError.mjs';
import Template from '{Template}/Web/Pages/Auth/Login.template';
import JetAuthenticationCard from '@/Jetstream/AuthenticationCard'
import JetAuthenticationCardLogo from '@/Jetstream/AuthenticationCardLogo'
import JetButton from '@/Jetstream/Button'
import JetInput from '@/Jetstream/Input'
import JetCheckbox from '@/Jetstream/Checkbox'
import JetLabel from '@/Jetstream/Label'
import JetValidationErrors from '@/Jetstream/ValidationErrors'
import AuthScreenLayout from '@/Layouts/AuthScreenLayout'
import VueRecaptcha from 'vue-recaptcha';
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import QrCode from 'vue-qrcode-component'

// 钱包插件关闭：不要再 import 真正的钱包组件
// import WalletConnect from '@/Components/WalletConnect'

// 空组件占位，防止模板里还有 <WalletConnect /> 时报错
const WalletConnectDisabled = {
    name: 'WalletConnect',
    render(h) {
        return h('span', {
            style: {
                display: 'none'
            }
        })
    }
}

export default Template({
    components: {
        AuthScreenLayout,
        JetAuthenticationCard,
        JetAuthenticationCardLogo,
        JetButton,
        JetInput,
        JetCheckbox,
        JetLabel,
        JetValidationErrors,
        VueRecaptcha,
        JetDialogModal,
        JetSecondaryButton,
        QrCode,

        // 使用空组件替代真实钱包插件
        WalletConnect: WalletConnectDisabled,
    },

    props: {
        errors: {
            type: Object,
            default: () => ({}),
        },
        canResetPassword: Boolean,
        status: String
    },

    data() {
        return {
            activeTab: 'email',
            showPassword: false,
            form: this.$inertia.form({
                email: '',
                password: '',
                remember: false,
                'g-recaptcha-response': false,
            }),
            qrToken: null,
            qrKey: 0,
            qrApproved: false,
            qrCodeEnabled: false,
        }
    },

    computed: {
        generalErrors() {
            return Object.entries(this.errors || {}).filter(([key]) => !['email', 'password'].includes(key)).map(([, message]) => message);
        },
    },

    mounted() {
        if (this.activeTab === 'qr') {
            this.fetchQrCodeToken(true);
        }

        this.fetchInterval = setInterval(() => {
            if (this.activeTab === 'qr') {
                this.fetchQrCodeToken();
            }
        }, 5000);
    },

    watch: {
        activeTab(newTab) {
            if (newTab === 'qr') {
                this.showQrCode();
            } else {
                this.closeQrCode();
            }
        }
    },

    beforeDestroy() {
        clearInterval(this.fetchInterval);
    },

    methods: {
        displayError(message) { return authError(message, this.$i18n.locale); },
        submit() {
            if (this.activeTab === 'qr') {
                return;
            }

            const email = String(this.form.email || '').trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                this.$toast.error(this.$t('Please enter a valid email address'));
                return;
            }

            const submitData = {
                ...this.form.data(),
                email,
                remember: this.form.remember ? 'on' : ''
            };

            this.form
                .transform(() => submitData)
                .post(this.route('login'), {
                    onFinish: () => {
                        this.form.reset('password');

                        if (this.$refs.recaptcha) {
                            this.$refs.recaptcha.reset();
                        }
                    },
                })
        },

        setActiveTab(tab) {
            this.activeTab = tab;

            if (tab === 'qr') {
                this.showQrCode();
            } else {
                this.closeQrCode();
            }
        },

        onVerify(response) {
            this.form['g-recaptcha-response'] = response;
        },

        onCaptchaExpired() {
            this.form['g-recaptcha-response'] = false;
        },

        showReadonlyPopup() {
            this.$toast.error('New User Registration is disabled in DEMO mode, please use predefined user credentials instead.')
        },

        showQrCode() {
            this.qrCodeEnabled = true;
            this.fetchQrCodeToken(true);
        },

        closeQrCode() {
            this.qrCodeEnabled = false;
        },

        fetchQrCodeToken(force = false) {
            if (!this.qrCodeEnabled && !force) {
                return;
            }

            axios.get(this.route('auth.qr-code-token'), {
                params: {
                    prevtoken: this.qrToken
                }
            }).then((response) => {
                if (this.qrToken !== response.data.token) {
                    this.qrKey++;
                }

                this.qrToken = response.data.token;

                if (response.data.isLogged) {
                    this.qrApproved = true;
                    window.location.reload();
                }
            }).catch(error => {

            });
        }
    }
});
</script>