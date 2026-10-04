<script>
import SelectInput from "@/Jetstream/SelectInput";
import Template from '{Template}/Web/Pages/Wallet/Deposit/Fiat/DepositBank.template'
import AppLayout from '@/Layouts/AppLayout'
import {string_cut} from "@/Functions/String";
import JetButton from '@/Jetstream/Button'
import TextUserInput from "@/Jetstream/TextUserInput";
import LoadingButton from "@/Jetstream/LoadingButton";

const defaultForm = {
    currency: null,
    amount: '',
    note: '',
};

export default Template({
    components: {
        AppLayout,
        SelectInput,
        JetButton,
        TextUserInput,
        LoadingButton
    },
    props: {
        symbol: String,
        currency: Object,
        errors: Object,
    },
    data() {
        return {
            form: Object.assign({}, defaultForm),
            uploadUrl: this.route('user-file-upload'),
            uploadHeaders: {
                'X-XSRF-TOKEN' : $cookies.get('XSRF-TOKEN')
            },
            uploaded: false,
            receipt: null,
            sending: false,
        }
    },
    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        submit() {

            if(this.sending) return;

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.$toast.open(this.$t('Your payment is being processed'));
                    this.$inertia.visit(this.route('wallets.deposit.fiat.success'));
                },
                onError: () => {
                    this.sending = false;
                    this.$toast.error(this.$t('There are some form errors'));
                },
                preserveScroll: true
            };

            this.form.currency_id = this.currency.id;

            this.$inertia.post(this.route('wallets.deposit.store.bank'), this.form, afterRequest);
        },
        removePic: function(){
            this.receipt = null;
            this.form.receipt = [];
            this.uploaded = false;
        },
        upload: function(){
            let self = this;
            this.$refs.uploadForm.upload(this.uploadUrl, this.uploadHeaders, [this.receipt]).then(function(){
                self.uploaded = true;
            });
        },
        onSelect: function(fileRecords){
            this.upload();
            this.uploaded = false;
        },
        onUpload: function(responses){
            let response = responses[0];
            if (response && !response.error) {
                this.form.receipt_id = response.data.uuid;
            }
        },
        onError: function(responses) {
            let objects = responses[0];
            if (objects.response) {
                _.each(objects.response.data.errors, (field) => {
                    this.$toast.error(field[0]);
                });
            }
        },
        handleInput ($event) {

            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || this.form.amount.toString().indexOf('.') != -1)) {
                $event.preventDefault();
            }

            // restrict to 2 decimal places
            if(this.form.amount.toString() != null && this.form.amount.toString().indexOf(".")>-1 && (this.form.amount.toString().split('.')[1].length > 8)){
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
        },
    }
})
</script>
