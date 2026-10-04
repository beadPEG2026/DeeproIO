<script>
import Template from '{Template}/Web/Pages/Lending/Lendings.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'

export default Template({
    components: {
        AppLayout,
        IconFilter,
        JetSecondaryButton,
        JetDialogModal
    },
    data() {
        return {
            isCollateral: false,
            calculatedModel: {
                amount: '',
            },
            collateral: null,
            form: {
                amount: '',
                type: 'flexible',
            },
            repayForm: {
                amount: '',
            },
            sending: false,
            filter: {
                sortBy: null,
            },
            showRepayModal: false,
            showLendingModal: false,
            closeLendingModal: false,
            lendingModal: null,
            currencies: [],
            borrowTypes: [],
        }
    },
    props: {
        lendings: Object,
        sort: String,
        isRepayment: String
    },
    methods: {
        setLending(lending) {
            this.$inertia.visit(this.route('lending', lending.id));
        },
        setSort(sort) {
            this.$inertia.visit(this.route('lendings', {'sort': sort}));
        },
        repay() {

            this.repayForm.id = this.lendingModal.id;

            if(this.sending) return;

            this.sending = true;

            let route = this.route('lending.repay');

            if(this.isCollateral) {
                route = this.route('lending.collateral.add');
            }

            axios.post(route, this.repayForm).then((response) => {
                this.sending = false;

                if(this.isCollateral) {
                    this.$toast.success(this.$t('Collateral amount has been successfully added'));
                } else {
                    this.$toast.success(this.$t('Your loan has been successfully repaid'));
                }

                this.$inertia.visit(this.route('lendings', {'sort': 'my'}));
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        openModal(lending) {
            this.lendingModal = lending;
            this.borrowTypes = [];

            if (this.lendingModal.annual_rate_flexible) {
                this.borrowTypes.push({
                    'id': 'flexible',
                    'name': this.$t('Flexible Rate')
                });
            }

            if (this.lendingModal.annual_rate_weekly) {
                this.borrowTypes.push({
                    'id': 'weekly',
                    'name': this.$t('7 Days Stable Rate')
                });
            }

            if (this.lendingModal.annual_rate_monthly) {
                this.borrowTypes.push({
                    'id': 'monthly',
                    'name': this.$t('30 Days Stable Rate')
                });
            }

            this.loadCollaterrals();
            this.showLendingModal = true;
        },
        closeModal() {
            this.showLendingModal = false;
        },
        openRepayModal(lending, isCollateral) {
            this.isCollateral = isCollateral;
            this.lendingModal = lending;
            this.showRepayModal = true;
        },
        openRepayHistory(lending) {
            this.$inertia.visit(this.route('lendings', {'sort': 'my', 'repayment_history': lending.id}));
        },
        closeRepayModal() {
            this.showRepayModal = false;
        },
        borrow() {

            this.form.id = this.lendingModal.id;
            this.form.currency_id = this.lendingModal.currency_id;

            if(this.sending) return;

            this.sending = true;

            axios.post(this.route('lending.borrow'), this.form).then((response) => {
                this.sending = false;
                this.$toast.success('Your have successfully borrowed asset.');
                this.$inertia.visit(this.route('lendings', {'sort': 'my'}));
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        setupInitialData() {
            this.collateral = this.currencies[this.form.collateral_id];
            this.calcCollaterral();
        },
        loadCollaterrals() {
            axios.get(this.route('lendings.collaterrals'), {
                params: {
                    id: this.lendingModal.id
                }
            }).then((response) => {
                this.currencies = response.data;
                let currency = Object.values(this.currencies)[0];

            });
        },
        calcCollaterral() {
            axios.get(this.route('lendings.collaterral.calc'), {
                params: {
                    collateral_id: this.form.collateral_id,
                    currency_id:this.lendingModal.currency_id,
                    type: this.form.type,
                    amount: this.form.amount
                }
            }).then((response) => {
                this.calculatedModel = response.data;
            });
        },
        handleInput ($event) {

            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || this.form.amount.toString().indexOf('.') != -1)) {
                $event.preventDefault();
            }

            let precision = 8;

            // restrict to 2 decimal places
            if(this.form.amount != null && this.form.amount.toString().indexOf(".")>-1 && (this.form.amount.toString().split('.')[1].length >= precision)){
                $event.preventDefault();
            }
        },
        clearInput ($event) {
            if(this.form.amount.toString().charAt(0) == '.') {
                this.form.amount = 0;
            }
        },
    },
    watch: {
        'form.amount'(newVal){
            this.calcCollaterral()
        },
    }
})
</script>
