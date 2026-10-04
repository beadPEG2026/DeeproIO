<script>
import Template from '{Template}/Web/Pages/PeerTrade/PaymentMethods.template'
import AppLayout from '@/Layouts/AppLayout'
import TextUserInput from "@/Jetstream/TextUserInput";
import TextUserInputBadge from "@/Jetstream/TextUserInputBadge";
import SelectInput from "@/Jetstream/SelectInput";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import LoadingButton from "@/Jetstream/LoadingButton";
import SvgIcon from "@/Components/Svg/SvgIcon";
import TopMenu from '@/Components/PeerTrade/TopMenu'

export default Template({
    components: {
        AppLayout,
        TextUserInput,
        TextUserInputBadge,
        SelectInput,
        JetDialogModal,
        JetSecondaryButton,
        LoadingButton,
        SvgIcon,
        TopMenu
    },
    props: {
        methods: Array,
        id: String,
        paymentMethod: Object,
    },
    mounted() {
        if(this.id) {
            this.activeMethod = this.paymentMethod.method_id;
            this.changeMethod();
        }
    },
    data() {
        return {
            form: {

            },
            fields: [],
            activeMethod: null,
            sending: false,
        }
    },
    computed: {

    },
    methods: {
        submit() {

            if(this.sending) return;

            this.sending = true;

            this.postData = {id: this.activeMethod, form: this.form};

            if(this.id) {
                this.postData.post_id = this.id;
            }

            axios.post(this.route('p2p.api.postUserPaymentMethod'), this.postData).then((response) => {
                this.$inertia.visit(this.route('p2p.profile', this.$page.props.user.referral_code));
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    this.$toast.error(field[0]);
                });
            });
        },
        changeMethod() {
            return axios.get(this.route('p2p.api.paymentMethodFields'), {
                params: {
                    id: this.activeMethod
                }
            }).then((response) => {
                this.fields = response.data;

                if(this.id) {
                    _.each(this.paymentMethod.fields, (field, key) => {
                        this.form[field.id] = field.content;
                    });
                }
            });
        },
        cancel() {
            this.$inertia.visit(this.route('p2p.profile', this.$page.props.user.referral_code));
        }
    },
})
</script>
