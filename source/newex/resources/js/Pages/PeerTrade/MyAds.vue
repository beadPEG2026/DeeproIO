<script>
import { legacyText } from '@/Functions/LegacyTranslation';

import Template from '{Template}/Web/Pages/PeerTrade/MyAds.template'
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
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'
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
        JetConfirmationModal,
        JetSecondaryButton,
        JetDangerButton,
        TopMenu
    },
    props: {
        ads: Object,
        currencies: Object,
        fiatCurrencies: Object,
    },
    data() {
        return {
            form: {
                status: 'active',
                type: '',
                ad_id: '',
                coin: '',
                fiat: '',
            },
            sending: false,
            selectedAdKey: null,
            selectedAd: null,
            adRemoveModal: false,
            statuses: [
                {
                    'id': 'active',
                    'name': legacyText("Active"),

                },
                {
                    'id': 'draft',
                    'name': legacyText("Offline"),

                },
                {
                    'id': 'closed',
                    'name': legacyText("Archived"),

                },
                {
                    'id': 'hidden',
                    'name': 'Hidden By System',

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
            this.$inertia.replace(this.route('p2p.my-ads', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },
        editAd(id) {
            this.$inertia.visit(this.route('p2p.my-ads.edit', id));
        },
        setAdStatus(ad, status) {
            axios.post(this.route('p2p.api.setStatus'), {id: ad.id, status: status}).then((response) => {
                this.$toast.open('Ad has been moved to draft');
                this.getList();
            }).catch(error => {
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        deleteAd(ad, key) {
            this.selectedAd = ad;
            this.selectedAdKey = key;
            this.adRemoveModal = true;
        },
        confirmAdDelete() {

            if(this.sending) return;

            this.sending = true;
            axios.post(this.route('p2p.api.deleteAd'), {id: this.selectedAd.id}).then((response) => {
                this.$delete(this.ads.data, this.selectedAdKey);
                this.adRemoveModal = false;
                this.sending = false;
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
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
