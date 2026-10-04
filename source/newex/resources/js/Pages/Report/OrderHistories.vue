<script>
import Template from '{Template}/Web/Pages/Report/OrderHistories.template'
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
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import SvgIcon from "@/Components/Svg/SvgIcon";

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
        JetDialogModal,
        JetSecondaryButton,
        SvgIcon
    },
    props: {
        orders: Object,
        filters: Object,
        markets: Object,
    },
    data() {
        return {
            sending: false,
            form: {
                market: parseInt(this.filters.market),
                side: this.filters.side,
            },
            showModal: false,
            tradesModal: null,
        }
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
        openModal(trades) {
            this.tradesModal = trades;
            this.showModal = true;
        },
        closeModal() {
            this.showModal = false;
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
            this.$inertia.replace(this.route('reports.order-history', Object.keys(query).length ? query : { remember: 'forget' }), afterRequest)
        },
    },
})
</script>
