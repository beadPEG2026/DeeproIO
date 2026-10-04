<script>
import Template from '{Template}/Web/Pages/PeerTrade/CreatePeerTrade.template'
import AppLayout from '@/Layouts/AppLayout'
import IconFilter from "@/Components/Table/IconFilter";
import {math_formatter} from "@/Functions/Math";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetCheckbox from '@/Jetstream/Checkbox'
import throttle from "lodash/throttle";
import { component as VueNumber } from '@coders-tm/vue-number-format'
import {mapGetters} from "vuex";
import TopMenu from '@/Components/PeerTrade/TopMenu'

export default Template({
    components: {
        AppLayout,
        IconFilter,
        JetDialogModal,
        JetSecondaryButton,
        JetCheckbox,
        VueNumber,
        TopMenu
    },
    data() {
        return {
            chosenMethods: [],
            showMethods: false,
            highestOrderPrice: 0,
            pairRate: 0,
            lowestOrderPrice: 0,
            selectedMethods: [],
            fixedPriceMinLimit: null,
            fixedPriceMaxLimit: null,
            buyFee: 0,
            sellFee: 0,
            regions: [],
            form: {
                amount: '',
                coin: null,
                fiat: null,
                fixed_price: '',
                floated_price: 100,
                min_amount: '',
                max_amount: '',
                timeframe: '15',
                type: 'fixed',
                side: 'buy',
                regions: '',
                remarks: '',
                auto_reply: '',
            },
            step: 1,
            coins: null,
            fiats: null,
            methods: null,
            bidPrice: 90,
            sending: false,
            errors: {},
        }
    },
    props: {
        ad: Object,
        isEdit: Boolean,
        basePair: String,
        quotePair: String,
        paymentMethods: Array,
        userPaymentMethodIds: Array,
    },
    mounted() {

        this.loadAssets();

        this.loadRegions();

        if(this.isEdit) {
            this.loadAd();
        }

        if(_.isEmpty(this.wallets) && this.$page.props.user) {
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        }
    },
    computed: {
        ...mapGetters({
            wallets: 'getWallets'
        }),
        baseWallet: function () {
            if(this.$store.getters.getUser && this.form.coin) {
                return this.$store.getters.getWallet(this.form.coin);
            }
        },
        selectMethodDisabled() {
            return this.selectedMethods.length >= 5;
        },
        calculatedPrice() {

            let price = 0;

            if(this.form.type == "fixed") {
                price = this.form.fixed_price;
            } else {

                let percentage = parseFloat(this.form.floated_price);

                if(!percentage) percentage = 0;

                price = math_formatter((this.pairRate * percentage) / 100, 2);
            }

            if(!price) return 0;

            return price;
        },
        userMethods() {
            return _.filter(this.methods, (method) => {
                return this.chosenMethods.includes(method.id);
            });
        },
        userPaymentMethods() {
            return _.filter(this.paymentMethods, (method) => {
                return this.chosenMethods.includes(method.id);
            });
        },
        calculatedFee() {

            if(!this.baseWallet || this.baseWallet.balance_in_wallet == 0) return 0;

            let amount = this.baseWallet.balance_in_wallet;

            let fee = this.form.side == 'sell' ? this.sellFee : this.buyFee;

            if(fee == 0) return amount;

            return math_formatter(amount - (amount * (fee / 100)), 8);
        },
    },
    methods: {
        loadAd() {
            this.form.side = this.ad.type;
            this.form.min_amount = this.ad.min_amount;
            this.form.max_amount = this.ad.max_amount;
            this.form.amount = this.ad.remaining_amount;
            this.form.timeframe = this.ad.timeframe;
            this.form.auto_reply = this.ad.auto_reply;
            this.form.remarks = this.ad.remarks;

            this.form.coin = this.ad.coin.symbol;
            this.form.fiat = this.ad.fiat.symbol;

            this.form.type = this.ad.price_type;
            this.form.fixed_price = this.ad.fixed_price;
            this.form.floated_price = this.ad.price_percentage;

            if(this.form.side == "buy") {
                this.ad.payment_methods.forEach((method) => {
                    this.chosenMethods.push(method.id);
                    this.selectedMethods.push(method.id);
                });
            } else {
                this.userPaymentMethodIds.forEach((method) => {
                    this.chosenMethods.push(method);
                    this.selectedMethods.push(method);
                });
            }

            this.form.regions = [];

            this.ad.regions.forEach((region) => {
                this.form.regions.push(region.id);
            });
        },
        nextStep() {

          if(this.step == 3) {
              this.postAd();
              return;
          }

          if(this.validateForm()) {
              this.step++;
          }
        },
        previousStep() {
          if(this.step == 1) return;

          this.step--;
        },
        getBestPairRate() {
            axios.get(this.route('p2p.api.getPairBestRate'), {
                params: {
                    base: this.form.coin,
                    quote: this.form.fiat
                }
            }).then((response) => {

                this.pairRate = response.data.rate;

                this.lowestOrderPrice = response.data.lowestOrderPrice;
                this.highestOrderPrice = response.data.highestOrderPrice;
                this.buyFee = response.data.buy_fee;
                this.sellFee = response.data.sell_fee;
                let decimals = 2;

                if(this.form.coin == "USDT" && this.form.fiat == "USD") {
                    decimals = 3;
                }


                this.fixedPriceMinLimit = math_formatter(this.pairRate * 0.80, decimals);
                this.fixedPriceMaxLimit = math_formatter(this.pairRate * 1.20, decimals);

            }).catch(error => {

            });
        },
        loadAssets() {
            axios.get(this.route('p2p.api.assets')).then((response) => {
                this.coins = response.data.coins;
                this.fiats = response.data.fiats;

                if(!this.isEdit) {
                    this.form.coin = this.basePair;
                    this.form.fiat = this.quotePair;
                }

            }).catch(error => {

            });
        },
        loadPaymentMethods() {
            axios.get(this.route('p2p.api.paymentMethods'), {
                params: {
                    currency: this.form.fiat
                }
            }).then((response) => {
                this.methods = response.data;
            }).catch(error => {

            });
        },
        setType(type) {
            this.form.side = type;

            this.selectedMethods = [];
            this.chosenMethods = [];
        },
        handleInput ($event, field) {

            let formField = this.form[field];

            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || formField.toString().indexOf('.') != -1)) {
                $event.preventDefault();
            }

            // restrict to 2 decimal places
            if(formField != null && formField.toString().indexOf(".")>-1 && (formField.toString().split('.')[1].length >= 8)){
                $event.preventDefault();
            }
        },
        clearInput ($event, field) {

            let formField = this.form[field].toString();

            if(formField.charAt(0) == '.') {
                this.form[field] = 0;
                return;
            }

            if (/^0+\.\d+/.test( formField )) {
                this.form[field] = formField.replace(/^0+/, '0');
            }

            if (/^0+\d+/.test( formField )) {
                this.form[field] = formField.replace(/^0+/, '');
            }

        },
        openMethods() {
            this.showMethods = true;
        },
        closeMethods() {
            this.showMethods = false;
        },
        deleteMethod(id) {
            let index = this.chosenMethods.indexOf(id);
            if (index !== -1) {
                this.chosenMethods.splice(index, 1);
            }
        },
        confirmMethods() {
            this.chosenMethods = this.selectedMethods;

            if(this.chosenMethods.length > 0) {
                this.errors.payment_method = null;
            }

            this.closeMethods();
        },
        validateForm() {

            this.errors = {};

            // STEP 1
            if(this.step == 1) {

                if(!this.form.coin || !this.form.fiat) {

                    this.errors.assets = this.$t('Both assets must be selected');

                    return false;


                } else if(this.form.type == "fixed" && (!this.form.fixed_price || (parseFloat(this.form.fixed_price) < this.fixedPriceMinLimit) || (parseFloat(this.form.fixed_price) > this.fixedPriceMaxLimit))) {

                    this.errors.fixed_price = this.$t('Fixed Price should be between') + ' [' + this.fixedPriceMinLimit + ' - ' + this.fixedPriceMaxLimit + ']';

                    return false;

                } else if(this.form.type == "float" && (!this.form.floated_price || (parseFloat(this.form.floated_price) < 80) || (parseFloat(this.form.floated_price) > 120))) {

                    this.errors.floated_price = this.$t('Floating Price margin should be between [80% - 120%]');

                    return false;

                }

                return true;
            } else if(this.step == 2) {

                if((!this.form.amount || parseFloat(this.form.amount) <= 0) && !this.isEdit) {

                    this.errors.amount = this.$t('Total amount must be greater than 0');

                    return false;

                } else if(this.form.side == "sell" && parseFloat(this.form.amount) > this.calculatedFee && !this.isEdit) {

                    this.errors.amount = this.$t('Insufficient balance. Please top up your wallet first.');

                    return false;

                } else if(!this.form.min_amount || parseFloat(this.form.min_amount) <= 0) {

                    this.errors.min_amount = this.$t('Minimum amount must be greater than 0');

                    return false;

                } else if(parseFloat(this.form.min_amount) > this.form.amount * this.pairRate) {

                    this.errors.min_amount = this.$t('Min limit should not exceed the total amount');

                    return false;

                } else if(!this.form.max_amount || parseFloat(this.form.max_amount) <= 0) {

                    this.errors.max_amount = this.$t('Maximum amount must be greater than 0');

                    return false;

                } else if(this.chosenMethods.length == 0) {

                    this.errors.payment_method = this.$t('At least 1 payment method must be selected');

                    return false;
                }

                return true;
            }
        },
        postAd() {

            if(this.sending) return;

            this.sending = true;

            this.form.paymentMethods = this.chosenMethods;

            if(this.isEdit) {

                this.form.id = this.ad.id;

                axios.post(this.route('p2p.api.editAd'), this.form).then((response) => {
                    this.$inertia.visit(this.route('p2p.my-ads'));
                    this.sending = false;
                }).catch(error => {
                    this.sending = false;
                });

                return;
            }

            axios.post(this.route('p2p.api.postAd'), this.form).then((response) => {
                this.$inertia.visit(this.route('p2p.create.success'));
                this.sending = false;
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);

                        if(key == "fiat" || key == "coin") {
                            this.step = 1;
                        } else if(key == "paymentMethods" || key == "amount") {
                            this.step = 2;
                        }
                    }
                });
            });
        },
        numericValue() {

            let digits = 2;

            if(this.form.coin == "USDT" && this.form.fiat == "USD") {
                digits = 3;
            }

            return {
                decimal: '.',
                separator: '',
                prefix: '',
                precision: digits,
            }
        },
        cryptoNumericValue() {

            let digits = 5;

            return {
                decimal: '.',
                separator: '',
                prefix: '',
                precision: digits,
            }
        },
        setAmount() {
            this.form.amount = this.calculatedFee;
        },
        convertToBaseRate(amount) {
            return math_formatter(amount / this.pairRate, 8);
        },
        convertToQuoteRate(amount) {
            return math_formatter(amount * this.pairRate, 2);
        },
        loadRegions() {
            axios.get(this.route('countries')).then((response) => {

                let all = [
                    {
                        'id': 0,
                        'name': this.$t('All Regions')
                    }
                ];
                let options = response.data;
                this.regions = all.concat(options);

            }).catch(error => {

            });
        },
    },
    watch: {
        'form.coin': function (type, newType) {
            this.getBestPairRate();
        },
        'form.fiat': function (type, newType) {
            this.getBestPairRate();
            this.loadPaymentMethods();
        },
        'form.side': function (type, newType) {
            this.errors = {};
        },
        'form.regions': function (type, newType) {
            if(this.form.regions && this.form.regions.includes(0)) {
                this.form.regions = [];
            }
        }
    }
})
</script>
