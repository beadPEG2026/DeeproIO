<script>
import {authError} from '@/Functions/AuthError.mjs';
    import Template from '{Template}/Web/Pages/Auth/ForgotPassword.template';
    import JetAuthenticationCard from '@/Jetstream/AuthenticationCard'
    import JetAuthenticationCardLogo from '@/Jetstream/AuthenticationCardLogo'
    import JetButton from '@/Jetstream/Button'
    import JetInput from '@/Jetstream/Input'
    import JetLabel from '@/Jetstream/Label'
    import JetValidationErrors from '@/Jetstream/ValidationErrors'
    import AppBasicLayout from '@/Layouts/AppBasicLayout'

    export default Template({
        components: {
            AppBasicLayout,
            JetAuthenticationCard,
            JetAuthenticationCardLogo,
            JetButton,
            JetInput,
            JetLabel,
            JetValidationErrors
        },

        props: {
            errors: Object,
            status: String
        },

        data() {
            return {
                form: this.$inertia.form({
                    email: ''
                })
            }
        },

        methods: {
        displayError(message) { return authError(message, this.$i18n.locale); },
            submit() {
                this.form.post(this.route('password.email'))
            }
        }
    });
</script>
