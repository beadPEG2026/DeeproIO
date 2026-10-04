<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/PeerTrade/MyOrders.template'
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
import throttle from "lodash/throttle";
import TopMenu from '@/Components/PeerTrade/TopMenu'

export default Template({
    components: {
        Badge,
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
        TopMenu
    },
    props: {
        orders: Object,
        currencies: Object,
        fiatCurrencies: Object,
    },
    data() {
        return {
            form: {
                status: '',
                type: '',
                order_id: '',
                coin: '',
                fiat: '',
            },
            statuses: [
                {
                    'id': '',
                    'name': 'All Orders',

                },
                {
                    'id': 'completed',
                    'name': legacyText("Completed"),

                },
                {
                    'id': 'cancelled',
                    'name': legacyText("Cancelled"),

                },
                {
                    'id': 'pending_payment',
                    'name': legacyText("Pending Payment"),

                },
                {
                    'id': 'confirm_transfer',
                    'name': legacyText("Pending Release"),
                },
                {
                    'id': 'appealed_by_counterparty',
                    'name': 'Appeal in Progress',
                }
            ],
            types: [
                {
                    'id': '',
                    'name': 'All Types',

                },
                {
                    'id': 'buy',
                    'name': legacyText("Buy"),

                },
                {
                    'id': 'sell',
                    'name': legacyText("Sell"),

                },
            ]
        }
    },
    mounted() {

    },
    methods: {
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
            this.$inertia.replace(this.route('p2p.my-orders', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },
        editAd(id) {
            this.$inertia.visit(this.route('p2p.my-ads.edit', id));
        },
        setAdStatus(ad, status) {
            axios.post(this.route('p2p.api.setStatus'), {id: ad.id, status: status}).then((response) => {
                ad.status = response.data.status;
            }).catch(error => {

            });
        },
        deleteAd(ad, key) {
            axios.post(this.route('p2p.api.deleteAd'), {id: ad.id}).then((response) => {
                this.$delete(this.ads.data, key);
            }).catch(error => {

            });
        }
    },
    watch: {
        form: {
            handler: throttle(function() {
                this.getList()
            }, 150),
            deep: true,
        },
    },
})
</script>
