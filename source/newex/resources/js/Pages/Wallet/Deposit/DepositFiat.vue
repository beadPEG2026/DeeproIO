<script>
import SelectInput from "@/Jetstream/SelectInput";
import Template from '{Template}/Web/Pages/Wallet/Deposit/DepositFiat.template'
import AppLayout from '@/Layouts/AppLayout'
import {mapGetters} from "vuex";
import {string_cut} from "@/Functions/String";
import JetButton from '@/Jetstream/Button'
import JetDialogModal from '@/Jetstream/DialogModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import DepositBank from "./Fiat/DepositBank";
import DepositCc from "./Fiat/DepositCc";
import DepositPerfect from "./Fiat/DepositPerfect";
import SvgIcon from "@/Components/Svg/SvgIcon";
import DepositPayeer from "./Fiat/DepositPayeer";

export default Template({
    components: {
        AppLayout,
        SelectInput,
        JetButton,
        DepositBank,
        DepositCc,
        DepositPerfect,
        DepositPayeer,
        JetDialogModal,
        JetSecondaryButton,
        SvgIcon
    },
    props: {
        symbol: String,
        currency: Object,
        networks: Array,
        errors: Object,
        stripe: String,
        siteName: String,
        siteLang: String,
        accountId: String,
    },
    data() {
        return {
            step: 'payment_method',
            method: '',
            wallet : false,
            walletData: false,
            showDepositModal: false,
            closeDepositModal: false,
            depositModal: null,
        }
    },
    mounted() {
        if(_.isEmpty(this.deposits)) {
            this.$store.dispatch('fetchFiatDeposits', this.route('wallets.api.deposits.fiat'));
        }
    },
    computed: {
        ...mapGetters({
            deposits: 'getFiatDeposits',
        }),
    },
    methods: {
        format_string(string, limit) {
            return string_cut(string, limit);
        },
        setStep(step, method) {
            this.method = method;
            this.step = step;
        },
        openModal(deposit) {
            this.depositModal = deposit;
            this.showDepositModal = true;
        },
        closeModal() {
            this.showDepositModal = false;
        }
    }
})
</script>
