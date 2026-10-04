<script>
import Template from '{Template}/Web/Pages/PeerTrade/MyOrder.template'
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
import LoadingButton from "@/Jetstream/LoadingButton";
import JetDialogModal from '@/Jetstream/DialogModal'
import JetButton from '@/Jetstream/Button'
import JetSecondaryButton from '@/Jetstream/SecondaryButton'
import JetCheckbox from '@/Jetstream/Checkbox'
import Chat from '@/Components/PeerTrade/Chat'
import Countdown from '@/Jetstream/Countdown'
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
        LoadingButton,
        JetDialogModal,
        JetSecondaryButton,
        JetCheckbox,
        JetButton,
        Chat,
        Countdown,
        TopMenu
    },
    props: {
        model: Object,
        paymentMethods: Array,
        userPaymentMethod: Object,
        isBuy: Boolean,
        buyerCancellationReasons: Array,
        sellerCancellationReasons: Array,
    },
    computed: {
        getOrder: function () {
            return this.order
        },
    },
    data() {
        return {
            order: {},
            refreshKey: 1,
            feedbackSending:false,
            selectedMethods: [],
            selectMethodDisabled: false,
            fetchInterval: null,
            showAppealOrder: false,
            showCancelOrder: false,
            showReleaseOrder: false,
            showConfirmAd: false,
            showMethods: false,
            chatMessages: [],
            message: null,
            receiving: false,
            methodSending: false,
            refreshCountDown: false,
            releaseTwoFa: null,
            scrollOps: {
                vuescroll: {},
                scrollPanel: {
                    initialScrollY: '100%'
                },
                rail: {},
                bar: {}
            },
            showFeedbackOrder: false,
            showFeedbackType: 'positive',
            feedbackForm: {
                is_anonymous: false,
                is_negative: false,
            },
            userFeedback: false,
            feedbackDeleting: false,
            isEditFeedback: null,
            sending: false,
            cancelForm: {},
            releaseCheckboxState: false,
            appealForm: {
                files: [],
            },
            fileRecords: [],
            appealFilesUploaded: false,
            uploadUrl: this.route('user-file-upload-document'),
            uploadHeaders: {
                'X-XSRF-TOKEN' : $cookies.get('XSRF-TOKEN')
            },
            appealErrors: null,
            fileIds: [],
        }
    },
    mounted() {

        this.order = this.model;

        this.fetchInterval = setInterval(() => {
            this.getOrderStatus();
        }, 2000);

        this.checkFeedback();
    },
    beforeDestroy() {
        clearInterval(this.fetchInterval);
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
        textAreaAdjust() {
            const { message } = this.$refs;
            message.style.height = "0px";
            message.style.height = (message.scrollHeight)+"px";
        },
        confirmTransfer() {

            if(this.sending) return;

            this.sending = true;

            this.order.status = 'confirm_transfer';

            axios.post(this.route('p2p.api.setOrderStatus'), {id: this.order.id, status: 'confirm_transfer'}).then((response) => {

                this.sending = false;

                this.refreshCountDown = true;

            }).catch(error => {

                this.sending = false;
            });
        },
        releaseOrder() {

            if(this.sending || !this.releaseCheckboxState) return;

            this.sending = true;

            let status = 'completed';

            axios.post(this.route('p2p.api.setOrderStatus'), {id: this.order.id, status: status, twofa: this.releaseTwoFa}).then((response) => {
                this.sending = false;
                this.order.status = status;
                this.showReleaseOrder = false;
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    this.$toast.error(field[0]);
                });
            });
        },
        cancelOrder() {

            if(this.sending) return;

            this.sending = true;

            axios.post(this.route('p2p.api.setOrderStatus'), {
                id: this.order.id,
                status: 'cancel',
                reason: this.cancelForm.type,
                message: this.cancelForm.message,
            }).then((response) => {
                this.sending = false;
                this.showCancelOrder = false;
                this.order.status = response.data.status;
            }).catch(error => {
                this.sending = false;
                this.showCancelOrder = false;
            });
        },
        appealOrder() {

            if(this.sending) return;

            this.sending = true;

            axios.post(this.route('p2p.api.submitAppeal'), {
                id: this.order.id,
                reason: this.appealForm.reason,
                attachments: this.appealForm.files,
            }).then((response) => {
                this.sending = false;
                this.showAppealOrder = false;
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        showConfirmPopup() {
            this.showConfirmAd = true;
        },
        showCancelPopup() {
            this.showCancelOrder = true;
        },
        showReleasePopup() {

            if(this.order.status !== "confirm_transfer") {
                return;
            }

            this.showReleaseOrder = true;
        },
        showAppealPopup() {

            if(this.order.status !== "confirm_transfer") {
                return;
            }

            if(this.order.type == "sell" && this.order.user.referral_code == this.$page.props.user.referral_code) {
                this.showAppealOrder = true;
                return;
            }

            if(this.order.type == "buy" && this.order.seller.referral_code == this.$page.props.user.referral_code) {
                this.showAppealOrder = true;
                return;
            }

            if(!this.order.appeal_available) return;

            this.showAppealOrder = true;
        },
        showMethod() {
            this.showMethods = true;
        },
        closeMethods() {
            this.showMethods = false;
        },
        confirmMethod(method) {

            if(this.methodSending) return;

            this.methodSending = true;

            this.order.user_payment_method = method.id;

            this.closeMethods();

            axios.post(this.route('p2p.api.changePaymentMethod'), {order_id: this.order.id, id: method.id}).then((response) => {
                this.methodSending = false;
            }).catch(error => {
                this.methodSending = false;
            });
        },
        leaveFeedback(is_positive, reset = false) {

            if(reset) {
                this.isEditFeedback = null;
            }

            this.showFeedbackType = is_positive;
            this.showFeedbackOrder = true;
        },
        submitFeedback() {

            if(this.feedbackSending) return;

            this.feedbackSending = true;

            this.feedbackForm.order_id = this.order.id;

            let route = 'p2p.api.postOrderFeedback';

            let formData = this.feedbackForm;

            if(this.isEditFeedback) {
                route = 'p2p.api.editFeedback';
                formData.id = this.isEditFeedback;
            }

            formData.is_negative = !this.showFeedbackType;
            formData.is_anonymous = this.feedbackForm.is_anonymous;

            axios.post(this.route(route), formData).then((response) => {
                this.feedbackSending = false;
                this.showFeedbackOrder = false;
                this.checkFeedback();
            }).catch(error => {
                this.feedbackSending = false;
                this.showFeedbackOrder = false;
            });
        },
        checkFeedback() {
            axios.get(this.route('p2p.api.getOrderFeedback'), {
                params: {
                    order_id: this.order.id
                }
            }).then((response) => {
                this.userFeedback = response.data.feedback;
            });
        },
        editFeedback(feedback) {
            this.isEditFeedback = feedback.id;
            this.feedbackForm.content = feedback.content;
            this.feedbackForm.is_anonymous = feedback.is_anonymous;
            this.showFeedbackType = !feedback.is_negative;
            this.showFeedbackOrder = true;
        },
        deleteFeedback(feedback) {

            if(this.feedbackDeleting) return;

            this.feedbackDeleting = true;

            axios.post(this.route('p2p.api.deleteFeedback'), {id: feedback.id}).then((response) => {
                this.feedbackDeleting = false;
                this.checkFeedback();
            }).catch(error => {
                this.feedbackDeleting = false;
            });
        },
        onSelect: function(fileRecords){
            this.upload(fileRecords);
            this.appealFilesUploaded = false;
        },
        onUpload: function(responses) {

            responses.forEach((response) => {

                if (response && !response.error) {
                    this.appealForm.files.push(response.data.uuid);
                    this.fileIds.push(response.fileRecord.lastModified);
                }
            });
        },
        upload: function(fileRecords){

            let self = this;
            this.$refs.fileUploadForm.upload(this.uploadUrl, this.uploadHeaders, fileRecords).then(function(){
                self.appealFilesUploaded = true;
            });

        },
        removePic: function() {

        },
        onDelete: function(fileRecord){

            let index = this.fileIds.indexOf(fileRecord.lastModified);

            if(index >= 0) {
                this.appealForm.files.splice(index, 1);
            }

            this.$refs.fileUploadForm.deleteFileRecord(fileRecord);
        },
        endTimeCalc(secs) {

            if(!secs) return 0;

            return new Date().getTime() + (parseInt(secs) * 1000);
        },
        getOrderStatus() {
            axios.get(this.route('p2p.api.getOrderStatus'), {
                params: {
                    id: this.order.id
                }
            }).then((response) => {

                this.order = response.data.order;

                if(this.refreshCountDown) {
                    this.refreshKey++;
                    this.refreshCountDown = false;
                }
            });
        }
    },
})

</script>
