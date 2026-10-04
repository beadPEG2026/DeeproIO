<script>
import {annualizedRate,stakingPeriods,fundedRewardEstimate} from '@/Functions/UserDisplay.mjs';
import RiskDialog from "@/Mixins/RiskDialog";
import Template from '{Template}/Web/Pages/Staking/Staking.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";
import {math_percentage} from "@/Functions/Math";

export default Template({
    mixins: [RiskDialog],
    components: {
        AppLayout,
        IconFilter,
    },
    data() {
        return {
            redemptionDate: '',
            showRiskModal: false,
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
        staking: Object,
        balance: String
    },
    mounted() {

    },
    methods: {
        annualizedRate,
        submit() {

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            if(!this.activeReward) {
                return this.$toast.error('Please select the staking period.');
            }

            if(this.sending) return;

            if (confirm(this.$t("Are you sure you want to stake your coins and carefully checked staking conditions? Please confirm your action")) == true) {

                this.sending = true;

                this.form.id = this.staking.id;
                this.form.days = this.activeDays;

                axios.post(this.route('staking.submit'), this.form).then((response) => {
                    this.sending = false;
                    this.$toast.success('Your coins successfully have been staked');
                    this.$inertia.visit(this.route('stakings', {'sort': 'my'}));
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
            this.calculatedReward = this.staking.subscription?.funded_term
                ? fundedRewardEstimate(this.form.amount, this.activeReward)
                : parseFloat(math_percentage(this.form.amount, this.activeReward)).toFixed(8);
        },
        calculateRedemption() {
            axios.get(this.route('staking.redemption.calculate'), {
                params: {
                    days: this.activeDays
                }
            }).then((response) => {
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

            let precision = this.staking.subscription?.funded_term ? 8 : 4;

            // restrict to 2 decimal places
            if(this.form.amount != null && this.form.amount.toString().indexOf(".")>-1 && (this.form.amount.toString().split('.')[1].length >= precision)){
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
    },
    watch: {
        'form.amount': function (type, newType) {
            this.calculate();
        },
    },
    computed: {
        periods(){return stakingPeriods(this.staking)},
        actionText() {
            return this.$page.props.staking_type == 1 ? this.$t('Quantify') : this.$t('Stake');
        },
        backText() {
            return this.$page.props.staking_type == 1 ? this.$t('Back to Quantify') : this.$t('Back to Staking');
        },
        pageTitle() {
            return this.$page.props.staking_type == 1 ? this.$t('Quantify') : this.$t('Staking');
        },
        minLabel() {
            return this.$page.props.staking_type == 1 ? this.$t('Minimum Quantify') : this.$t('Minimum Stake');
        },
        maxLabel() {
            return this.$page.props.staking_type == 1 ? this.$t('Maximum Quantify') : this.$t('Maximum Stake');
        },
        calculatorTitle() {
            return this.$page.props.staking_type == 1 ? this.$t('Quantify Calculator') : this.$t('Stake Calculator');
        },
        loginActionText() {
            return this.$page.props.staking_type == 1 ? this.$t('Login to Quantify') : this.$t('Login to Stake');
        }
    }
})
</script>
