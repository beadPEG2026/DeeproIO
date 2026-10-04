<script>
import Template from '{Template}/Web/Pages/Auth/Ping.template';
import JetAuthenticationCard from '@/Jetstream/AuthenticationCard'
import JetAuthenticationCardLogo from '@/Jetstream/AuthenticationCardLogo'
import JetButton from '@/Jetstream/Button'
import JetInput from '@/Jetstream/Input'
import JetCheckbox from '@/Jetstream/Checkbox'
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
        JetCheckbox,
        JetLabel,
        JetValidationErrors,
    },

    props: {
        errors: Object,
        status: String
    },

    data() {
        return {
            form: this.$inertia.form({
                ping: '',
            })
        }
    },

    methods: {
        submit() {
            this.form
                .transform(data => ({
                    ... data,
                    remember: this.form.remember ? 'on' : ''
                }))
                .post(this.route('system-monitor.ping.register'), {
                    onFinish: () => {

                    },
                })
        },
    }
});
</script>
