<script>
import {authError} from '@/Functions/AuthError.mjs';
import AuthScreenLayout from '@/Layouts/AuthScreenLayout'
import Template from '{Template}/Web/Pages/Auth/Register.template';
import JetAuthenticationCard from '@/Jetstream/AuthenticationCard'
import JetAuthenticationCardLogo from '@/Jetstream/AuthenticationCardLogo'
import JetButton from '@/Jetstream/Button'
import JetInput from '@/Jetstream/Input'
import JetCheckbox from "@/Jetstream/Checkbox";
import JetLabel from '@/Jetstream/Label'
import JetValidationErrors from '@/Jetstream/ValidationErrors'
import VueRecaptcha from 'vue-recaptcha';
import LanguageSwitcher from '@/Components/LanguageSwitcher'

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
        WalletConnect: WalletConnectDisabled,
        LanguageSwitcher
    },

    props: {
        errors: { type: Object, default: () => ({}) },
    },

    data() {
        return {
            referralState: false,
            referralError: false,
            referralReadonly: false,
            referralDraft: '',

            sendingEmailCode: false,
            emailCountdown: 0,
            emailCountdownTimer: null,

            showPassword: false,
            showPasswordConfirmation: false,
            showingLanguageDropdown: false,

            form: this.$inertia.form({
                name: '',
                email: '',
                email_code: '',
                password: '',
                password_confirmation: '',
                terms: false,
                referral: '',
                'g-recaptcha-response': false,
            })
        }
    },

    computed: {
        generalErrors() {
            return Object.entries(this.errors || {}).filter(([key]) => !['email', 'email_code', 'password', 'password_confirmation', 'referral', 'terms'].includes(key)).map(([, message]) => message);
        },
        canSendEmailCode() {
            return this.isEmailAccount(this.form.email);
        },

    },

    mounted() {
        const params = new URLSearchParams(window.location.search);
        const referralFromQuery = params.get('ref');

        if (referralFromQuery) {
            this.referralState = true;
            this.referralReadonly = true;
            this.form.referral = referralFromQuery;
            this.referralError = false;
            return;
        }

        if (this.$page.props.referral_code) {
            this.referralState = true;
            this.referralReadonly = true;
            this.form.referral = this.$page.props.referral_code;
            this.referralError = false;
            return;
        }

        if (this.$page.props.referral_code_required) {
            this.referralState = true;
        }
    },

    beforeUnmount() {
        this.destroyCountdownTimer();
    },

    beforeDestroy() {
        this.destroyCountdownTimer();
    },

    methods: {
        displayError(message) { return authError(message, this.$i18n.locale); },
        openReferral() {
            this.referralDraft = this.form.referral || '';
            this.referralError = false;
            this.$refs.referralDialog.showModal();
            this.$nextTick(() => this.$refs.referralInput.focus());
        },

        closeReferral() {
            this.$refs.referralDialog.close();
        },

        saveReferral() {
            const value = this.normalizeAccount(this.referralDraft);
            if (this.$page.props.referral_code_required && !value) {
                this.referralError = true;
                return;
            }
            if (!this.referralReadonly) this.form.referral = value;
            this.referralState = !!this.form.referral || !!this.$page.props.referral_code_required;
            this.referralError = false;
            this.closeReferral();
        },

        onCaptchaExpired() {
            this.form['g-recaptcha-response'] = false;
        },

        submit() {
            const email = this.normalizeAccount(this.form.email);

            if (!email || !this.isEmailAccount(email)) {
                this.$toast.error(this.$t('Please enter a valid email address'));
                return;
            }

            if (this.$page.props.referral_code_required && !this.normalizeAccount(this.form.referral)) {
                this.openReferral();
                this.referralError = true;
                return;
            }

            const submitData = {
                ...this.form.data(),
                email: email,
                email_code: this.form.email_code,
            };

            this.form.transform(() => submitData).post(this.route('register'), {
                onFinish: () => {
                    this.form.reset('password', 'password_confirmation');

                    if (this.$refs.recaptcha) {
                        this.$refs.recaptcha.reset();
                    }
                },
            });
        },

        sendEmailVerificationCode() {
            const email = this.normalizeAccount(this.form.email);

            if (!email || !this.isEmailAccount(email)) {
                this.$toast.error(this.$t('Please enter a valid email address'));
                return;
            }

            this.sendingEmailCode = true;

            axios.post(this.route('register.email.code.send'), {
                type: 'email',
                email: email
            }).then((response) => {
                this.$toast.success(response.data.message || this.$t('Verification email submitted. Please check your inbox and spam folder.'));
                this.startEmailCountdown();
            }).catch((error) => {
                this.$toast.error(
                    error.response?.data?.message || this.$t('Failed to send verification code')
                );
            }).finally(() => {
                this.sendingEmailCode = false;
            });
        },

        normalizeAccount(value) {
            return (value || '').toString().trim();
        },

        isEmailAccount(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.normalizeAccount(value));
        },

        startEmailCountdown() {
            this.emailCountdown = 60;

            if (this.emailCountdownTimer) {
                clearInterval(this.emailCountdownTimer);
            }

            this.emailCountdownTimer = setInterval(() => {
                if (this.emailCountdown > 0) {
                    this.emailCountdown--;
                    return;
                }

                if (this.emailCountdownTimer) {
                    clearInterval(this.emailCountdownTimer);
                    this.emailCountdownTimer = null;
                }
            }, 1000);
        },

        destroyCountdownTimer() {
            if (this.emailCountdownTimer) {
                clearInterval(this.emailCountdownTimer);
                this.emailCountdownTimer = null;
            }

        },

        onVerify(response) {
            this.form['g-recaptcha-response'] = response;
        },

        togglePassword() {
            this.showPassword = !this.showPassword;
        },

        togglePasswordConfirmation() {
            this.showPasswordConfirmation = !this.showPasswordConfirmation;
        },
    }
});
</script>

<style>

.auth-language-dropdown {
    position: fixed;
    top: 22px;
    right: 82px;
    z-index: 120;
}

.auth-language-dropdown .header-dropdown__row {
    position: relative;
}

.auth-language-dropdown__button {
    width: 42px;
    height: 42px;
    min-width: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.auth-language-dropdown__button .header-dropdown__icon,
.auth-language-dropdown__button svg {
    width: 22px;
    height: 22px;
}

.auth-language-dropdown__list {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    min-width: 190px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(8px);
    pointer-events: none;
    transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
}

.auth-language-dropdown__list.active {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    pointer-events: auto;
}

@media (max-width: 576px) {
    .auth-language-dropdown {
        top: 16px;
        right: 70px;
    }

    .auth-language-dropdown__button {
        width: 38px;
        height: 38px;
        min-width: 38px;
    }

    .auth-language-dropdown__list {
        right: -48px;
        min-width: 178px;
    }
}

</style>
