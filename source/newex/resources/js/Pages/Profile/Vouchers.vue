<script>
import JetButton from '@/Jetstream/Button'
import JetFormSection from '@/Jetstream/FormSection'
import JetInput from '@/Jetstream/Input'
import JetInputError from '@/Jetstream/InputError'
import JetLabel from '@/Jetstream/Label'
import JetActionMessage from '@/Jetstream/ActionMessage'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import Template from '{Template}/Web/Pages/Profile/Vouchers.template'
import SvgIcon from "@/Components/Svg/SvgIcon";
import Badge from "@/Jetstream/Badge";
import TextUserInput from "@/Jetstream/TextUserInput";
import * as gateFiSDK from "@gatefi/js-sdk";

export default Template({
    components: {
        JetActionMessage,
        JetButton,
        JetFormSection,
        JetInput,
        JetInputError,
        JetLabel,
        JetSecondaryButton,
        SvgIcon,
        Badge,
        TextUserInput
    },
    data() {
        return {
            formResponse: '',
            sending: false,
            form: {
                code: '',
            },
            vouchers: null,
            transactions: null,
            showBuy: false,
        }
    },
    mounted() {

        /*let instance = new gateFiSDK.GateFiSDK({
            merchantId: "602e8fae-9a5b-44fc-89fd-5d646bfa726c",
            displayMode: "embedded",
            nodeSelector: "#buy-container",
            walletAddress: "0x1E2bfb1b188ABF005e43bC0D669899Efb20d5DC4",
            walletLock: true,
            email: "support@cryptotech.exchange",
            defaultFiat: {
                currency: "USD",
                amount: 99.00,
            },
            isSandbox: true
        })*/



        this.getTransactions();
    },
    methods: {
        submit() {

            if(this.sending) return false;

            this.formResponse = '';

            this.sending = true;

            axios.post(this.route('voucher.redeem'), this.form).then((response) => {

                this.sending = false;

                this.formResponse = response.data.message;

                this.getTransactions();

            }).catch(error => {
                this.sending = false;
                this.formResponse = error.response.data.message;
            });
        },
        async getTransactions() {
            await axios.get(this.route('voucher.transactions')).then((response) => {
                this.transactions = response.data;
            }).catch(error => {

            });
        },
    },
});
</script>
