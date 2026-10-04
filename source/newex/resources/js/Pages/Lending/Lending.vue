<script>
import Template from '{Template}/Web/Pages/Lending/Lending.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";

export default Template({
    components: {
        AppLayout,
        IconFilter,
    },
    data() {
        return {
            redemptionDate: '',
            valueDate: '',
            state: false,
            sending: false,
            form: {
                id: null,
                amount: '',
            },
            activeReward: '',
            activeDays: '',
            calculatedReward: '',
        }
    },
    props: {
        lending: Object,
        balance: String
    },
    methods: {
        submit() {

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            if(!this.activeReward) {
                return this.$toast.error('Please select the lending period.');
            }

            if(this.sending) return;

            if (confirm(this.$t("Are you sure you want to stake your coins and carefully checked lending conditions? Please confirm your action")) == true) {

                this.sending = true;

                this.form.id = this.lending.id;
                this.form.days = this.activeDays;

                axios.post(this.route('lending.submit'), this.form).then((response) => {
                    this.sending = false;
                    this.$toast.success('Your coins successfully have been staked');
                    this.$inertia.visit(this.route('lendings', {'sort': 'my'}));
                }).catch(error => {
                    this.sending = false;
                    _.each(error.response.data.errors, (field, key) => {
                        if (field[0] !== "") {
                            this.$toast.error(field[0]);
                        }
                    });
                });
            }
        },
        login() {
            this.$inertia.visit(this.route('login'));
        },
        setRange(days, reward) {
            this.activeReward = reward;
            this.activeDays = days;
            this.calculate();
            this.calculateRedemption();
        },
        calculate() {

            if(!this.activeDays || !this.form.amount) return;

            let fixedReward = this.activeReward / 100;
            let year = 365;

            let amount = this.form.amount * Math.pow(1 + fixedReward / year,year * this.activeDays / year) - this.form.amount;
            this.calculatedReward = parseFloat(amount).toFixed(8);
        },
        calculateRedemption() {
            axios.post(this.route('lending.redemption.calculate'), { days: this.activeDays }).then((response) => {
                if(response.data.value_date) {
                    this.redemptionDate = response.data.redemption_date;
                    this.valueDate = response.data.value_date;
                }
            }).catch(error => {

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
        'form.amount': function (type, newType) {
            this.calculate();
        },
    }
})
</script>
