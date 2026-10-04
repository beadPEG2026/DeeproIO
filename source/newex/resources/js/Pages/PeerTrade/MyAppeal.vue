<script>
import Template from '{Template}/Web/Pages/PeerTrade/MyAppeal.template'
import AppLayout from '@/Layouts/AppLayout'
import Welcome from '@/Jetstream/Welcome'
import NavButtonLink from "@/Jetstream/NavButtonLink";
import Pagination from '@/Jetstream/Pagination'
import NavLink from "@/Jetstream/NavLink";
import Badge from "@/Jetstream/Badge";
import SearchFilter from '@/Jetstream/SearchFilter'
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
import JetConfirmationModal from '@/Jetstream/ConfirmationModal'
import JetDangerButton from '@/Jetstream/DangerButton'
import pickBy from "lodash/pickBy";
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
        JetDangerButton,
        SelectUserInput,
        ReportsTab,
        SvgIcon,
        LoadingButton,
        JetDialogModal,
        JetConfirmationModal,
        JetSecondaryButton,
        JetCheckbox,
        JetButton,
        Chat,
        TopMenu
    },
    props: {
        stage: Number,
        isReviewer: Boolean,
        isInitiator: Boolean,
        order: Object,
        appeals: Object,
    },
    data() {
        return {
            appealForm: {
                files: [],
            },
            showAppealOrder: false,
            cancelAppealModal: false,
            fileRecords: [],
            appealFilesUploaded: false,
            uploadUrl: this.route('user-file-upload-document'),
            uploadHeaders: {
                'X-XSRF-TOKEN' : $cookies.get('XSRF-TOKEN')
            },
            appealErrors: null,
            fileIds: [],
            sending: false,
            appealReviewMessage: null,
            appealReviewTurn: 'defendant',
            appealListInterval: null,
        }
    },
    mounted() {
        this.appealListInterval = setInterval(() => {
            this.getList();
        }, 5000);
    },
    beforeDestroy: function(){
        clearInterval(this.appealListInterval)
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
        cancelAppeal() {
            this.cancelAppealModal = true;
        },
        cancelAppealConfirm() {

            if(this.sending) return;

            this.sending = true;

            this.cancelAppealModal = false;

            axios.post(this.route('p2p.api.cancelAppeal', {id: this.order.id})).then((response) => {
                this.sending = false;
                this.getList();
                this.$toast.open(this.$t('Appeal was cancelled'));
            }).catch(error => {
                this.sending = false;
            });
        },
        respondAppeal() {
            this.showAppealOrder = true;
        },
        respondAppealSubmit() {

            if(this.sending) return;

            this.sending = true;

            axios.post(this.route('p2p.api.respondAppeal'), {
                id: this.order.id,
                reason: this.appealForm.reason,
                attachments: this.appealForm.files,
            }).then((response) => {
                this.sending = false;
                this.showAppealOrder = false;
                this.getList();
            }).catch(error => {
                this.sending = false;
                _.each(error.response.data.errors, (field, key) => {
                    if(field[0] !== "") {
                        this.$toast.error(field[0]);
                    }
                });
            });
        },
        submitReview() {

            if(this.appealReviewMessage == "" || !this.appealReviewMessage) {
                this.$toast.error(this.$t('Review Message can not be empty'));
                return;
            }

            if (window.confirm("Are you sure to submit this review message?")) {

                if (this.sending) return;

                this.sending = true;

                axios.post(this.route('p2p.api.submitAppealReview'), {
                    id: this.order.id,
                    message: this.appealReviewMessage,
                    turn: this.appealReviewTurn
                }).then((response) => {
                    this.sending = false;
                    this.showAppealOrder = false;
                    this.appealReviewMessage = null;
                    this.getList();
                    this.$toast.open(this.$t('Your message has been submitted'));
                }).catch(error => {
                    this.sending = false;
                    this.showAppealOrder = false;
                });

            }

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
        getList() {

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

            this.$inertia.replace(this.route('p2p.my-appeal', this.order.id), afterRequest)
        }
    },
})

</script>
