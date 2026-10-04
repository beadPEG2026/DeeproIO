<script>
import SelectInput from "@/Jetstream/SelectInput";
import Template from '{Template}/Web/Pages/Wallet/Deposit/Fiat/DepositPerfect.template'
import AppLayout from '@/Layouts/AppLayout'
import JetButton from '@/Jetstream/Button'
import LoadingButton from "@/Jetstream/LoadingButton";
import TextUserInput from "@/Jetstream/TextUserInput";

const defaultForm = {
    currency: null,
    amount: '',
};

export default Template({
    components: {
        AppLayout,
        SelectInput,
        JetButton,
        LoadingButton,
        TextUserInput
    },
    props: {
        symbol: String,
        currency: Object,
        errors: Object,
        siteName: String,
        accountId: String,
        siteLang: String,
    },
    mounted() {

    },
    data() {
        return {
            form: Object.assign({}, defaultForm),
            method: 'perfectmoney',
            sending: false,
        }
    },
    methods: {
        submit() {

            if(this.sending) return;

            if(this.form.amount == '' || parseFloat(this.form.amount) < parseFloat(this.currency.min_deposit)) {
                this.$toast.error(this.$t('Minimum deposit:') + ' ' + this.currency.min_deposit + ' ' + this.currency.symbol);
                return;
            }

            if(parseFloat(this.form.amount) > parseFloat(this.currency.max_deposit)) {
                this.$toast.error(this.$t('Maximum deposit:') + ' ' + this.currency.max_deposit + ' ' + this.currency.symbol);
                return;
            }

            this.sending = true;
            this.$refs.perfectMoneyForm.requestSubmit();
        },
        handleInput ($event) {

            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || this.form.amount.toString().indexOf('.') != -1)) {
                $event.preventDefault();
            }

            // restrict to 2 decimal places
            if(this.form.amount.toString() != null && this.form.amount.toString().indexOf(".")>-1 && (this.form.amount.toString().split('.')[1].length > 1)){
                $event.preventDefault();
            }
        },
        clearInput ($event) {
            let field = this.form.amount.toString();

            if(field.charAt(0) == '.') {
                this.form.amount = 0;
                return;
            }

            if (/^0+\.\d+/.test( field )) {
                this.form.amount = field.replace(/^0+/, '0');
            }

            if (/^0+\d+/.test( field )) {
                this.form.amount = field.replace(/^0+/, '');
            }
        }
    }
})
</script>
