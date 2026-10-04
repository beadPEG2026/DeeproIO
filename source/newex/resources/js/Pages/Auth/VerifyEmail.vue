<script>
    import Template from '{Template}/Web/Pages/Auth/VerifyEmail.template';
    import JetAuthenticationCard from '@/Jetstream/AuthenticationCard'
    import JetAuthenticationCardLogo from '@/Jetstream/AuthenticationCardLogo'
    import JetButton from '@/Jetstream/Button'
    import AppBasicLayout from '@/Layouts/AppBasicLayout'

    export default Template({
        components: {
            AppBasicLayout,
            JetAuthenticationCard,
            JetAuthenticationCardLogo,
            JetButton,
        },

        props: {
            status: String
        },

        data() {
            return {
                form: this.$inertia.form()
            }
        },

        methods: {
            submit() {
                this.form.post(this.route('verification.send'))
            },
        },

        computed: {
            verificationLinkSent() {
                return this.status === 'verification-link-sent';
            }
        }
    });
</script>
