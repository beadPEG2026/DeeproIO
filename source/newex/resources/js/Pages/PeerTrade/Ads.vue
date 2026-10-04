<script>
import Template from '{Template}/Web/Pages/PeerTrade/Ads.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import pickBy from 'lodash/pickBy'
import mapValues from 'lodash/mapValues'
import {string_cut} from "@/Functions/String";
import TextUserInput from "@/Jetstream/TextUserInput";
import SelectUserInput from "@/Jetstream/SelectUserInput";
import ReportsTab from "@/Components/Reports/ReportsTab";
import SvgIcon from "@/Components/Svg/SvgIcon";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import throttle from "lodash/throttle";
import JetCheckbox from '@/Jetstream/Checkbox'
import { component as VueNumber } from '@coders-tm/vue-number-format'
import OfferModal from '@/Components/PeerTrade/OfferModal'
import TopMenu from '@/Components/PeerTrade/TopMenu'
import vClickOutside from "v-click-outside";

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
        OfferModal,
        TopMenu
    },
    props: {
        ads: Object,
        filters: Object,
        basePair: String,
        quotePair: String,
        baseCurrencies: Array,
        quoteCurrencies: Array,
        userPaymentMethods: Array,
        takerSellFee: String,
        takerBuyFee: String,
    },
    data() {
        return {
            sortBy: 'price',
            activeTheme: '',
            selectMethodDisabled: false,
            selectedMethods: null,
            sending: false,
            posting: false,
            activeOffer: null,
            showAdOffer: false,
            paymentMethod: null,
            regions: [],
            fiats: [],
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
            form: {
                amount: this.filters.amount,
                payment_method: this.filters.payment_method,
                basePair: this.filters.basePair,
                quotePair: this.filters.quotePair,
                type: this.filters.type,
                region: this.filters.region,
                sort: this.filters.sort,
                merchants_only: this.filters.merchants_only,
                timeframe: this.filters.timeframe
            },
            selectedInterval: null,
            fetchInterval1: null,
            fetchInterval2: null,
            fetchInterval3: null,
            filterShow: false,
            refreshIntervals: [
                {
                    'id': 1,
                    'name': this.$t('Manual'),
                },
                {
                    'id': 2,
                    'name': this.$t('Every 5s'),
                },
                {
                    'id': 3,
                    'name': this.$t('Every 10s'),
                },
                {
                    'id': 4,
                    'name': this.$t('Every 20s'),
                }
            ]
        }
    },
    beforeDestroy() {
        clearInterval(this.fetchInterval1);
        clearInterval(this.fetchInterval2);
        clearInterval(this.fetchInterval3);
    },
    mounted() {

        this.loadAssets();
        this.loadPaymentMethods();
        this.loadRegions();
        this.setupFilters();

        this.fetchInterval1 = setInterval(() => {
            if(this.selectedInterval == 2) {
                this.getList();
            }

        }, 5000);

        this.fetchInterval2 = setInterval(() => {

            if(this.selectedInterval == 3) {
                this.getList();
            }

        }, 10000);

        this.fetchInterval3 = setInterval(() => {

            if(this.selectedInterval == 4) {
                this.getList();
            }

        }, 20000);
    },
    methods: {
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
        reset() {
            this.form = mapValues(this.form, () => null)
        },
        getList() {

            if(this.sending) return;

            this.sending = true;

            if(this.form.payment_method && this.form.payment_method.includes(0)) {
                this.form.payment_method = [];
            }

            let afterRequest = {
                onStart: () => this.sending = true,
                onFinish: () => this.sending = false,
                onSuccess: () => {
                    this.sending = false;
                },
                onError: () => {
                    this.sending = false;
                },
                preserveScroll: true
            };

            let query = pickBy(this.form)
            this.$inertia.replace(this.route('p2p.ads', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },
        setType(type) {
            this.form.type = type;
        },
        setBasePair(pair) {
            if(this.form.basePair == pair) return;
            this.form.basePair = pair;
        },
        setupFilters() {

            if(!this.form.sort) {
                this.form.sort = 'price';
            }

            if(!this.form.timeframe) {
                this.form.timeframe = 'all';
            }

            if(!this.form.basePair) {
                this.form.basePair = this.basePair;
            }

            if(!this.form.quotePair) {
                this.form.quotePair = this.quotePair;
            }

            if(this.filters.region)  {
                this.form.region = parseInt(this.filters.region);
            }
        },
        loadAssets() {
            axios.get(this.route('p2p.api.assets')).then((response) => {
                this.fiats = response.data.fiats;
            }).catch(error => {

            });
        },
        loadPaymentMethods() {
            axios.get(this.route('p2p.api.paymentMethods'), {
                params: {
                    currency: this.form.quotePair
                }
            }).then((response) => {
                let all = [
                    {
                        'id': 0,
                        'name': this.$t('All Payments')
                    }
                ];
                let options = response.data;

                this.paymentMethods = all.concat(options);

                this.form.payment_method = [];

                if(this.filters.payment_method) {
                    this.filters.payment_method.forEach((method) => {
                        this.form.payment_method.push(parseInt(method));
                    });
                }

            }).catch(error => {

            });
        },
        loadRegions() {
            axios.get(this.route('countries')).then((response) => {

                this.activeTheme = document.getElementById("body").getAttribute("class");

                if(!this.activeTheme || this.activeTheme == '')
                    this.activeTheme = "light";

                let all = [
                    {
                        'id': null,
                        'name': this.$t('All Regions'),
                        'logo': '/images/countries/all-' + this.activeTheme +'.png',
                    }
                ];

                let options = response.data;
                this.regions = all.concat(options);

            }).catch(error => {

            });
        },
        openOffer(ad) {

            if(!this.$page.props.user) {
                return this.$inertia.visit(this.route('login'));
            }

            this.activeOffer = ad;

            this.$worker.$emit("openOffer", {
                'activeOffer': this.activeOffer,
                'showAdOffer': true,
            });
        },
        parseSelect(option) {

        },
        hideFilters() {
            this.filterShow = false;
        },
        handleInput ($event) {

            let keyCode = ($event.keyCode ? $event.keyCode : $event.which);

            if ((keyCode < 48 || keyCode > 57) && (keyCode !== 46 || this.form.amount.toString().indexOf('.') != -1)) {
                $event.preventDefault();
            }

            let precision = 8;

            // restrict to 2 decimal places
            if(this.form.amount != null && this.form.amount.toString().indexOf(".")>-1 && (this.form.amount.toString().split('.')[1].length >= 99)){
                $event.preventDefault();
            }
        },
        clearInput ($event) {
            if(this.form.amount.toString().charAt(0) == '.') {
                this.form.amount = 0;
            }
        },
        showLimitedMethods(ads, first = true) {

            if(first) {
                return ads.slice(0,3);
            }

            return ads.slice(3);
        },
        showTooltipMethods(methods) {
            let list = [];
            methods.forEach((item) => {
                list.push(item.name);
            });

            return list.join(', ');
        }
    },
    directives: {
        clickOutside: vClickOutside.directive
    },
    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
        showAdOffer: function (type, newType) {
            this.refresh_state = !type;
        },
        'form.quotePair': function (type, newType) {
            this.loadPaymentMethods();
        },
    },
})
</script>
