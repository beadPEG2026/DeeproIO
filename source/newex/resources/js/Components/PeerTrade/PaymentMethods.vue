<script>
import Template from '{Template}/Web/Components/PeerTrade/PaymentMethods.template'
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'

export default Template({
    components: {
        JetConfirmationModal,
        JetSecondaryButton,
        JetDangerButton
    },
    props: [],
    data() {
        return {
            sending: false,
            tabPage: '',
            paymentMethods: [],
            paymentMethodMethodBeingDeleted: false,
            selectedMethod: null,
        }
    },
    mounted() {
        this.loadPaymentMethods();
    },

    methods: {
        loadPaymentMethods() {
            axios.get(this.route('p2p.api.getUserPaymentMethods'), {
                params: {
                    user: this.$page.props.user.referral_code
                }
            }).then((response) => {
                this.paymentMethods = response.data;
            });
        },
        setReport(report) {
            this.$inertia.visit(this.route(report));
        },
        setTabPage() {
            this.$inertia.visit(this.route(this.tabPage));
        },
        confirmDelete(id) {
            this.selectedMethod = id;
            this.paymentMethodMethodBeingDeleted = true;
        },
        deleteMethod() {

            if(this.sending) return;

            this.sending = true;

            axios.post(this.route('p2p.api.deleteUserPaymentMethod'), {id: this.selectedMethod }).then((response) => {
                this.sending = false;
                this.paymentMethodMethodBeingDeleted = false;
                this.$toast.open(this.$t('Payment method has been successfully deleted.'));
                this.loadPaymentMethods();
            });
        }
    }
})
</script>
