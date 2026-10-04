<script>
import Template from '{Template}/Web/Components/PeerTrade/Feedbacks.template'
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetDangerButton from '@/Jetstream/DangerButton'
import PaginationAjax from '@/Jetstream/PaginationAjax'
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'


export default Template({
    components: {
        JetConfirmationModal,
        JetSecondaryButton,
        JetDangerButton,
        PaginationAjax,
        JetDialogModal,
        JetButton,
    },
    props: {
        owner: Boolean,
        user: Object,
        stats: Object
    },
    data() {
        return {
            sending: false,
            tabPage: '',
            paymentMethods: [],
            paymentMethodMethodBeingDeleted: false,
            selectedMethod: null,
            feedbacks: {},
            activeFeedbackKey: null,
            activeFeedback: null,
            showReplyModal: false,
            reply: null,
            type: null,
        }
    },
    mounted() {
        this.type = null;
        this.getFeedbacks();
    },

    methods: {
        setReport(report) {
            this.$inertia.visit(this.route(report));
        },
        setTabPage() {
            this.$inertia.visit(this.route(this.tabPage));
        },
        getFeedbacks() {
            axios.get(this.route('p2p.api.getFeedbacks'), {
                params: {
                    type: this.type,
                    user: this.user.id,
                    page: this.feedbacks.current_page
                }
            }).then((response) => {
                this.feedbacks = response.data;
            })
        },
        replyToFeedback(key, feedback) {
            this.showReplyModal = true;
            this.activeFeedback = feedback;
            this.activeFeedbackKey = key;
        },
        submitReply() {
            axios.post(this.route('p2p.api.replyFeedback'), {
                reply: this.reply,
                feedback_id: this.activeFeedback.id,
            }).then((response) => {
                this.feedbacks.data[this.activeFeedbackKey].reply_content = this.reply;
                this.reply = null;
                this.showReplyModal = false;
            }).catch(error => {
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        getFeedbackByType(type) {
            this.type = type;
            this.getFeedbacks();
        },
        deleteFeedback(key, feedback) {
            axios.post(this.route('p2p.api.deleteFeedbackReply'), {
                id: feedback.id,
            }).then((response) => {
                this.feedbacks.data[key].reply_content = null;

            }).catch(error => {

            });
        }
    }
})
</script>
