<script>
import Template from '{Template}/Web/Components/PeerTrade/OfferModal.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import {string_cut} from "@/Functions/String";
import TextUserInput from "@/Jetstream/TextUserInput";
import SelectUserInput from "@/Jetstream/SelectUserInput";
import ReportsTab from "@/Components/Reports/ReportsTab";
import SvgIcon from "@/Components/Svg/SvgIcon";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetCheckbox from '@/Jetstream/Checkbox'
import { component as VueNumber } from '@coders-tm/vue-number-format'
import {mapGetters} from "vuex";
import {math_percentage, math_formatter} from "../../Functions/Math";

export default Template({
    components: {
        Badge,
        JetCheckbox,
        NavLink,
        NavButtonLink,
        AppLayout,
        Welcome,
        Pagination,
        SearchFilter,
        TextUserInput,
        SelectUserInput,
        ReportsTab,
        SvgIcon,
        JetDialogModal,
        JetButton,
        JetSecondaryButton,
        VueNumber,
    },
    props: {
        form: Object,
    },
    data() {
        return {
            isQuoteAmount: null,
            userPaymentMethods: [],
            takerSellFee: null,
            takerBuyFee: null,
            showAdOffer: false,
            activeOffer: null,
            selectMethodDisabled: false,
            selectedMethods: null,
            sending: false,
            posting: false,
            paymentMethod: null,
            refresh_state: false,
            paymentMethods: [],
            number: {
                decimal: '.',
                separator: '',
                prefix: '',
                precision: 5,
            },
            adForm: {
                offerAmount: '',
                offerReceived: ''
            },
            formErrors: {
                amount: null,
                payment_method: null,
            },
            showMethods: false,
        }
    },
    mounted() {

        this.$worker.$on('openOffer', (data) => {

            this.paymentMethod = null;

            this.formErrors = {
                amount: null,
                payment_method: null,
            };

            this.activeOffer = data.activeOffer;
            this.showAdOffer = data.showAdOffer;

            this.loadPaymentMethods();
            this.loadFess();
        });


        if(_.isEmpty(this.wallets) && this.$page.props.user) {
            this.$store.dispatch('fetchWallets', this.route('wallets.index'));
        }
    },
    computed: {
        ...mapGetters({
            wallets: 'getWallets'
        }),
        baseWallet: function () {
            if(this.$store.getters.getUser && this.activeOffer.coin.symbol) {
                return this.$store.getters.getWallet(this.activeOffer.coin.symbol);
            }
        },
        activePaymentMethod: function () {
            if(this.activeOffer) {
                return this.activeOffer.payment_methods.map(entry => entry.id);
            }
            return [];
        },
        processingFee: function() {

            if(!this.adForm.offerReceived) return 0;

            return math_formatter(math_percentage(this.adForm.offerReceived, this.activeOffer.type == 'buy' ? this.takerSellFee : this.takerBuyFee), 8);
        },
    },
    methods: {
        loadPaymentMethods() {
            axios.get(this.route('p2p.api.getUserPaymentMethods'), {
                params: {
                    user: this.$page.props.user.referral_code
                }
            }).then((response) => {
                this.userPaymentMethods = response.data;
            });
        },
        numericValue(digits) {
            return {
                decimal: '.',
                separator: '',
                prefix: '',
                precision: digits,
            }
        },
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        doCopy(string) {
            this.$copyText(string).then(() => {
                this.$toast.open(this.$t('Text was copied to the clipboard'));
            }, function (e) {

            })
        },
        openOffer(ad) {
            this.activeOffer = ad;
            this.paymentMethod = null;

            this.formErrors = {
                amount: null,
                payment_method: null,
            };

            this.showAdOffer = true;
        },
        acceptOffer() {

            if(this.posting) return;

            this.formErrors = {
                amount: null,
                payment_method: null,
            };

            this.posting = true;

            let amount = this.adForm.offerReceived;

            if(this.isQuoteAmount) {
                amount = this.adForm.offerAmount;
            }

            let form = {
                'amount': amount,
                'payment_method': this.paymentMethod,
                'ad_id': this.activeOffer.id,
                'isQuoteAmount': this.isQuoteAmount,
            }

            axios.post(this.route('p2p.api.setOrder'), form).then((response) => {
                this.$inertia.visit(this.route('p2p.my-order', response.data.id));
                this.posting = false;
            }).catch(error => {

                this.posting = false;

                let content = error.response.data.errors;

                if(content.amount) {
                    this.formErrors.amount = content.amount[0];
                }

                if(content.payment_method) {
                    this.formErrors.payment_method = content.payment_method[0];
                }

                if(content.ad_id) {
                    this.$toast.error(content.ad_id[0]);
                }

            });
        },
        loadFess() {
            axios.get(this.route('p2p.api.processingFee'), {
                params: {
                    symbol: this.activeOffer.coin.symbol,
                }
            }).then((response) => {
                this.takerSellFee = response.data.takerSellFee;
                this.takerBuyFee = response.data.takerBuyFee;
            })
        },
        showMethodPopup() {
            this.showMethods = true;
        },
        confirmMethods(method) {
            this.paymentMethod = method.id;
            this.closeMethods();
        },
        confirmUserMethods(method) {
            this.paymentMethod = method.id;
            this.closeMethods();
        },
        closeMethods() {
            this.showMethods = false;
        },
        parseSelect(option) {

        },
        calculateSend() {

            this.isQuoteAmount = true;

            if(this.activeOffer.type == 'sell') {
                let amountWithoutFee = this.adForm.offerAmount / this.activeOffer.offered_price;

                this.adForm.offerReceived = math_formatter(amountWithoutFee - math_percentage(amountWithoutFee, this.takerBuyFee), 8);

            } else {

                let amountWithoutFee = this.adForm.offerAmount / this.activeOffer.offered_price;

                this.adForm.offerReceived = math_formatter(amountWithoutFee + math_percentage(amountWithoutFee, this.takerBuyFee), 8);
            }
        },
        calculateReceive() {

            this.isQuoteAmount = false;

            if(this.activeOffer.type == 'buy') {
                this.adForm.offerAmount = math_formatter((this.adForm.offerReceived - math_percentage(this.adForm.offerReceived, this.takerSellFee)) * this.activeOffer.offered_price, 2);
            } else {
                this.adForm.offerAmount = math_formatter((math_percentage(this.adForm.offerReceived, this.takerSellFee) + parseFloat(this.adForm.offerReceived)) * this.activeOffer.offered_price, 2);
            }
        },
        calculateLimits() {
            return this.activeOffer.min_amount + ' - ' + this.activeOffer.max_amount;
        },
        refreshPrice() {
            axios.get(this.route('p2p.api.getAdInfo'), {
                params: {
                    ad_id: this.activeOffer.id
                }
            }).then((response) => {

                let res = response.data.ad;

                this.activeOffer.offered_price = res.offered_price;

            }).catch(error => {

            });
        },
        parseBreaks(text) {
            return text;
        }
    },
    watch: {
        showAdOffer: function (type, newType) {
            this.refresh_state = !type;
        },
    },
})
</script>
