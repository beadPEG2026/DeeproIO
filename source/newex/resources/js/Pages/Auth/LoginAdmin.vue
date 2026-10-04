<script>
import Template from '{Template}/Web/Pages/Auth/LoginAdmin.template';
import JetAuthenticationCard from '@/Jetstream/AuthenticationCard'
import JetAuthenticationCardLogo from '@/Jetstream/AuthenticationCardLogo'
import JetButton from '@/Jetstream/Button'
import JetInput from '@/Jetstream/Input'
import JetCheckbox from '@/Jetstream/Checkbox'
import JetLabel from '@/Jetstream/Label'
import JetValidationErrors from '@/Jetstream/ValidationErrors'
import AppBasicLayout from '@/Layouts/AppBasicLayout'
import VueRecaptcha from 'vue-recaptcha';

export default Template({
    components: {
        AppBasicLayout,
        JetAuthenticationCard,
        JetAuthenticationCardLogo,
        JetButton,
        JetInput,
        JetCheckbox,
        JetLabel,
        JetValidationErrors,
        VueRecaptcha
    },

    props: {
        errors: Object,
        canResetPassword: Boolean,
        status: String,
        currentAccount: { type: Object, default: null }
    },

    data() {
        return {
            switchingAccount: false,
            form: this.$inertia.form({
                email: '',
                password: '',
                remember: false,
                dashboard: true,
                'g-recaptcha-response': false,
            })
        }
    },
    mounted() {

    },
    methods: {
        switchAccount() {
            this.$inertia.post(this.route('logout'), { admin_login: true }, {
                onStart: () => { this.switchingAccount = true },
                onFinish: () => { this.switchingAccount = false },
            })
        },
        submit() {
            this.form
                .transform(data => ({
                    ... data,
                    remember: this.form.remember ? 'on' : ''
                }))
                .post(this.route('login'), {
                    onFinish: () => {
                        this.form.reset('password')
                        if (this.$refs.recaptcha) this.$refs.recaptcha.reset()
                    },
                })
        },
        onVerify: function (response) {
            Object.defineProperty(this.form, 'g-recaptcha-response', {
                value: response
            });
        },
    }
});
</script>
