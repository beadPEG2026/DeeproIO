<script>
import Template from '{Template}/Web/Pages/PeerTrade/Profile.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
import TextUserInput from "@/Jetstream/TextUserInput";
import SelectUserInput from "@/Jetstream/SelectUserInput";
import ReportsTab from "@/Components/Reports/ReportsTab";
import SvgIcon from "@/Components/Svg/SvgIcon";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import PaymentMethods from '@/Components/PeerTrade/PaymentMethods';
import Feedbacks from '@/Components/PeerTrade/Feedbacks';
import OnlineAds from '@/Components/PeerTrade/OnlineAds';
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
        JetDialogModal,
        JetButton,
        JetSecondaryButton,
        PaymentMethods,
        Feedbacks,
        OnlineAds,
        TopMenu
    },
    props: {
        seller: Object,
        owner: Boolean,
        blockReasons: Array,
        userBlocked: Boolean
    },
    data() {
        return {
            blockForm: {
                type: '1',
            },
            showBlockForm: false,
            showUsernameModal: false,
            usernameForm: null,
            usernameSending: false,
            fetchBalanceInterval : null,
            totalBalance: null,
            activeTab: null,
            feedbacks: null,
            feedbackStats: {
                percentage: 0.00,
                total: 0,
                positive: 0,
                negative: 0
            },
            sending: false
        }
    },
    beforeDestroy () {
        clearInterval(this.fetchBalanceInterval)
    },
    mounted() {

        if(this.owner) {
            this.activeTab = 'payment_method';
        } else {
            this.activeTab = 'ads';
        }

        this.fetchFeedbackStats();

        this.fetchTotalBalance();

        this.fetchBalanceInterval = setInterval(() => {
            this.fetchTotalBalance();
        }, 10000);
    },
    methods: {
        fetchTotalBalance() {
            axios.get(this.route('currencies.api.rates-balance')).then((response) => {
                this.totalBalance = response.data;
            })
        },
        fetchFeedbackStats() {
            axios.get(this.route('p2p.api.getFeedbackStats'), {
                params: {
                    user: this.seller.id
                }
            }).then((response) => {
                this.feedbackStats = response.data;
            });
        },
        setTab(tab) {
            this.activeTab = tab;
        },
        submitUsername() {

            if(this.usernameSending) return;

            this.usernameSending = true;

            axios.post(this.route('p2p.api.setUsername'), {username: this.usernameForm}).then((response) => {
                this.usernameSending = false;
                this.showUsernameModal = false;
                this.seller.nickname = this.usernameForm;
                this.seller.nickname_first = Array.from(this.usernameForm)[0];
            }).catch(error => {
                this.usernameSending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        openUsernameModal() {
            this.showUsernameModal = true;
        },
        showBlockModal() {
            this.showBlockForm = true;
        },
        blockUser() {

            if(this.sending) return;

            this.sending = true;

            axios.post(this.route('p2p.api.blockUser'), {
                type: this.blockForm.type,
                reason: this.blockForm.message,
                user: this.seller.id,
            }).then((response) => {
                this.sending = false;
                this.showBlockForm = false;
                this.userBlocked = true;
            }).catch(error => {
                this.sending = false;
                this.userBlocked = false;
            });

        },
        unblockUser() {

            if(this.sending) return;

            this.sending = true;

            axios.post(this.route('p2p.api.unblockUser'), {
                user: this.seller.id,
            }).then((response) => {
                this.sending = false;
                this.userBlocked = false;
            }).catch(error => {
                this.sending = false;
                this.userBlocked = false;
            });

        }
    },
})
</script>
