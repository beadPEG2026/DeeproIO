<script>
import Template from '{Template}/Admin/Pages/Admin/PeerTrades/OrdersAppeals.template'
import AppLayout from '@/Layouts/AdminLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import NavButton from "@/Jetstream/NavButton";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetCheckbox from '@/Jetstream/Checkbox'
import AdminTopMenu from "@/Components/PeerTrade/AdminTopMenu";

export default Template({
    components: {
        Badge,
        NavLink,
        NavButtonLink,
        NavButton,
        AppLayout,
        Welcome,
        Pagination,
        JetDialogModal,
        JetSecondaryButton,
        JetCheckbox,
        JetButton,
        AdminTopMenu
    },
    props: {
        appeals: Object,
    },
    data() {
        return {
            appeal: 'approve_release',
            activeAppealKey: null,
            sending: false,
            showModerateModal: false,
            activeAppeal: null,
        }
    },
    methods: {
        moderate(appeal, key) {
            this.showModerateModal = true;
            this.activeAppealKey = key;
            this.activeAppeal = appeal;
        },
        moderateSubmit() {

            if(this.sending) return;

            this.sending = true;

            if (confirm('Are you sure you want to moderate this appeal?')) {

                axios.post(this.route('admin.peerOrdersAppeals.moderate'), {
                    id: this.activeAppeal.id,
                    status: this.appeal
                }).then((response) => {

                    if(!response.data.status) {
                        this.$toast.error('The appeal may be already cancelled by counterparty or moderated before.');
                    } else {
                        this.appeals[this.activeAppealKey].appeal_stage = 'moderated';
                    }
                    this.sending = false;
                    this.showModerateModal = false;
                }).catch(error => {
                    this.sending = false;
                    this.showModerateModal = false;
                });

            }
        },
    }

})
</script>
